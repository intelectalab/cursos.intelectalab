<?php

namespace App\Modules\Sales\Application\Payments\Online;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Sales\Application\OrderService;
use App\Modules\Sales\Domain\Models\GatewayAccount;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\PaymentAttempt;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cobro en línea de las órdenes: qué pasarelas tiene conectadas la
 * organización, enviar al comprador a pagar y aplicar el pago cuando la
 * pasarela lo confirma. El pago siempre se consulta en la pasarela con la
 * cuenta de la organización: ni el aviso ni el navegador del comprador se
 * dan por ciertos.
 */
class OnlinePaymentService
{
    /**
     * @param  iterable<OnlineGateway>  $gateways
     */
    public function __construct(
        private readonly iterable $gateways,
        private readonly OrderService $orders,
        private readonly TenantSettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return list<string>
     */
    public function codes(): array
    {
        return array_map(fn (OnlineGateway $gateway) => $gateway->code(), [...$this->gateways]);
    }

    public function gateway(string $code): OnlineGateway
    {
        foreach ($this->gateways as $gateway) {
            if ($gateway->code() === $code) {
                return $gateway;
            }
        }

        throw new ApiException(ErrorCode::NotFound, 'Esa pasarela de pago no existe.', 404);
    }

    /**
     * Las pasarelas y cómo las tiene la organización, sin sus claves.
     *
     * @return list<array<string, mixed>>
     */
    public function overview(): array
    {
        $accounts = GatewayAccount::query()->get()->keyBy('gateway');
        $currency = $this->settings->currency();

        return array_map(function (OnlineGateway $gateway) use ($accounts, $currency) {
            $account = $accounts->get($gateway->code());

            return [
                'code' => $gateway->code(),
                'label' => $gateway->label(),
                'connected' => $account !== null,
                'enabled' => (bool) $account?->enabled,
                'account' => $account?->display,
                // La pasarela no cobra en la moneda de la organización: conectada o no, no se ofrece
                'currency_supported' => $gateway->supportsCurrency($currency),
                'has_webhook_secret' => filled($account?->credential('webhook_secret')),
                'connected_at' => $account?->updated_at?->toIso8601String(),
            ];
        }, [...$this->gateways]);
    }

    /**
     * Conecta (o vuelve a conectar) la cuenta de la organización, después de
     * comprobar las claves con la pasarela.
     *
     * @param  array<string, string|null>  $credentials
     */
    public function connect(string $code, array $credentials, bool $enabled = true): GatewayAccount
    {
        $gateway = $this->gateway($code);
        $display = $gateway->describeAccount($credentials);

        return DB::transaction(function () use ($code, $credentials, $display, $enabled) {
            $account = GatewayAccount::query()->updateOrCreate(['gateway' => $code], [
                'credentials' => array_filter($credentials, fn ($value) => filled($value)),
                'display' => $display,
                'enabled' => $enabled,
            ]);

            // Las claves no se guardan en la auditoría
            $this->audit->record('payment_gateways.connected', $account, null, ['gateway' => $code, 'account' => $display['name'] ?? null, 'enabled' => $enabled]);

            return $account;
        });
    }

    public function setEnabled(string $code, bool $enabled): GatewayAccount
    {
        $account = $this->account($code) ?? throw new ApiException(ErrorCode::GatewayNotAvailable, 'Primero hay que conectar la cuenta.', 409);

        if ($account->enabled !== $enabled) {
            $account->update(['enabled' => $enabled]);
            $this->audit->record('payment_gateways.'.($enabled ? 'enabled' : 'disabled'), $account, null, ['gateway' => $code]);
        }

        return $account;
    }

    public function disconnect(string $code): void
    {
        $account = $this->account($code);

        if ($account !== null) {
            $this->audit->record('payment_gateways.disconnected', $account, ['gateway' => $code, 'account' => $account->display['name'] ?? null]);
            $account->delete();
        }
    }

    /**
     * Pasarelas con las que el comprador puede pagar esta orden ahora.
     *
     * @return list<array{code: string, label: string}>
     */
    public function availableFor(Order $order): array
    {
        // Las ventas a empresas se cobran según lo pactado, no por aquí
        if (! $order->isPayable() || $order->customer_id === null || (float) $order->total_amount <= 0) {
            return [];
        }

        $order->loadMissing('currency');
        $enabled = GatewayAccount::query()->where('enabled', true)->pluck('gateway');
        $available = [];

        foreach ($this->gateways as $gateway) {
            if ($enabled->contains($gateway->code()) && $gateway->supportsCurrency($order->currency->code)) {
                $available[] = ['code' => $gateway->code(), 'label' => $gateway->label()];
            }
        }

        return $available;
    }

    /**
     * El comprador va a pagar: se prepara el cobro en la pasarela y se
     * devuelve a dónde enviarlo.
     *
     * @param  array{return: string, webhook: ?string}  $urls
     */
    public function start(Order $order, string $code, array $urls): PaymentAttempt
    {
        if (! in_array($code, array_column($this->availableFor($order), 'code'), true)) {
            throw new ApiException(ErrorCode::GatewayNotAvailable, 'Esta orden no se puede pagar por ese medio.', 409);
        }

        $order->loadMissing(['items', 'customer', 'currency']);

        $checkout = $this->gateway($code)->startCheckout($this->account($code)->forGateway(), new Charge(
            reference: $order->id,
            title: 'Orden '.str_pad((string) $order->number, 5, '0', STR_PAD_LEFT).' · '.($order->package_title ?? $order->items->first()?->title),
            amount: (string) $order->total_amount,
            currency: $order->currency->code,
            payerEmail: $order->customer->email,
            payerFirstName: $order->customer->first_name,
            payerLastName: $order->customer->last_name,
            // Pasado el plazo de la orden, la pasarela ya no la cobra
            expiresAt: $order->expires_at,
        ), $urls);

        return PaymentAttempt::create([
            'order_id' => $order->id,
            'gateway' => $code,
            'external_id' => $checkout['external_id'],
            'status' => PaymentAttempt::STATUS_CREATED,
            'amount' => $order->total_amount,
            'currency' => $order->currency->code,
            'checkout_url' => $checkout['checkout_url'],
        ]);
    }

    /**
     * Pregunta a las pasarelas si la orden ya se pagó (el comprador volvió
     * del checkout, o el aviso nunca llegó) y, si es así, aplica el pago.
     */
    public function sync(Order $order): Order
    {
        if ($order->status !== Order::STATUS_PENDING) {
            return $order;
        }

        // El intento más reciente de cada pasarela
        $attempts = PaymentAttempt::query()->where('order_id', $order->id)->latest()->orderByDesc('id')->get()->unique('gateway');

        foreach ($attempts as $attempt) {
            $account = $this->account($attempt->gateway);

            if ($account === null) {
                continue;
            }

            foreach ($this->gateway($attempt->gateway)->paymentsFor($account->forGateway(), $order->id) as $payment) {
                if ($payment->isApproved()) {
                    return $this->apply($order, $payment, $attempt->gateway);
                }
            }
        }

        return $order;
    }

    /**
     * Aviso de la pasarela. Lo que no sea de un pago o de una orden de esta
     * organización se ignora sin error, para que la pasarela no lo reintente.
     */
    public function handleWebhook(string $code, Request $request): void
    {
        $account = $this->account($code);

        if ($account === null) {
            return;
        }

        $payment = $this->gateway($code)->paymentFromWebhook($account->forGateway(), $request);

        if ($payment === null || ! Str::isUuid((string) $payment->orderReference)) {
            return;
        }

        $order = Order::query()->find($payment->orderReference);

        if ($order?->customer_id !== null) {
            $this->apply($order, $payment, $code);
        }
    }

    /**
     * Lleva a la orden lo que la pasarela dice del pago. Solo un pago
     * aprobado, por el importe y la moneda de la orden, la deja pagada; lo
     * que no cuadra queda marcado para que lo resuelva una persona.
     */
    private function apply(Order $order, GatewayPayment $payment, string $code): Order
    {
        return DB::transaction(function () use ($order, $payment, $code) {
            $order = Order::query()->lockForUpdate()->with(['currency', 'customer', 'payment'])->findOrFail($order->id);
            $attempt = $this->attemptFor($order, $payment, $code);

            if (! $payment->isApproved()) {
                if ($payment->status === GatewayPayment::REJECTED && $attempt->status === PaymentAttempt::STATUS_CREATED) {
                    $attempt->update(['status' => PaymentAttempt::STATUS_REJECTED, 'external_payment_id' => $payment->id]);
                }

                return $order;
            }

            // El mismo pago avisado otra vez
            if ($order->payment?->gateway === $code && $order->payment->reference === $payment->id) {
                return $order;
            }

            $problem = match (true) {
                $payment->currency !== $order->currency->code || $payment->amount !== number_format((float) $order->total_amount, 2, '.', '') => "La pasarela cobró {$payment->currency} {$payment->amount} y la orden es por {$order->currency->code} {$order->total_amount}.",
                $order->status === Order::STATUS_PAID => 'La orden ya estaba pagada por otro medio: hay un cobro de más.',
                $order->status !== Order::STATUS_PENDING => 'La orden ya no estaba pendiente cuando llegó el pago.',
                default => null,
            };

            if ($problem !== null) {
                if ($attempt->status !== PaymentAttempt::STATUS_NEEDS_REVIEW) {
                    $attempt->update(['status' => PaymentAttempt::STATUS_NEEDS_REVIEW, 'external_payment_id' => $payment->id, 'review_reason' => $problem]);
                    $this->audit->record('payments.online_needs_review', $attempt, null, ['order' => $order->number, 'gateway' => $code, 'payment' => $payment->id, 'reason' => $problem]);
                }

                return $order;
            }

            $attempt->update(['status' => PaymentAttempt::STATUS_APPROVED, 'external_payment_id' => $payment->id]);

            return $this->orders->applyPayment($order, $order->customer, [
                'gateway' => $code,
                'method' => $payment->method,
                'reference' => $payment->id,
                'paid_at' => $payment->paidAt,
            ]);
        });
    }

    /**
     * Intento al que corresponde el pago: el último de esa pasarela para la orden.
     */
    private function attemptFor(Order $order, GatewayPayment $payment, string $code): PaymentAttempt
    {
        /** @var Collection<int, PaymentAttempt> $attempts */
        $attempts = PaymentAttempt::query()->where('order_id', $order->id)->where('gateway', $code)->latest()->orderByDesc('id')->get();

        return $attempts->firstWhere('external_payment_id', $payment->id)
            ?? $attempts->first()
            ?? PaymentAttempt::create([
                'order_id' => $order->id,
                'gateway' => $code,
                'status' => PaymentAttempt::STATUS_CREATED,
                'amount' => $order->total_amount,
                'currency' => $order->currency->code,
            ]);
    }

    private function account(string $code): ?GatewayAccount
    {
        return GatewayAccount::query()->where('gateway', $code)->first();
    }
}
