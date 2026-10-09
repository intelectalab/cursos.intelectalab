<?php

namespace App\Modules\Sales\Application\Payments\Online;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\User;
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
    /** Intentos recientes de una orden por los que se pregunta a las pasarelas */
    private const ATTEMPTS_TO_CHECK = 5;

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
                // Moneda en la que la organización pone sus precios
                'currency' => $currency,
                // La pasarela no puede cobrar esa moneda de ninguna forma: conectada o no, no se ofrece
                'currency_supported' => $gateway->chargeCurrency($currency) !== null,
                // Cobra en otra moneda: hace falta el tipo de cambio para ofrecerla
                'needs_exchange_rate' => ChargeQuote::needsRate($gateway, $currency),
                'exchange_rate' => $account?->exchangeRate(),
                'has_webhook_secret' => filled($account?->credential('webhook_secret')) || filled($account?->credential('webhook_id')),
                'connected_at' => $account?->updated_at?->toIso8601String(),
            ];
        }, [...$this->gateways]);
    }

    /**
     * Conecta (o vuelve a conectar) la cuenta de la organización, después de
     * comprobar las claves con la pasarela.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function connect(string $code, array $credentials, bool $enabled = true): GatewayAccount
    {
        $gateway = $this->gateway($code);
        $display = $gateway->describeAccount($credentials);

        return DB::transaction(function () use ($code, $credentials, $display, $enabled) {
            $account = GatewayAccount::query()->updateOrCreate(['gateway' => $code], [
                'credentials' => array_map('strval', array_filter($credentials, fn ($value) => is_string($value) && filled($value))),
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
        $account = $this->connected($code);

        if ($account->enabled !== $enabled) {
            $account->update(['enabled' => $enabled]);
            $this->audit->record('payment_gateways.'.($enabled ? 'enabled' : 'disabled'), $account, null, ['gateway' => $code]);
        }

        return $account;
    }

    /**
     * Tipo de cambio con el que la organización cobra en dólares lo que
     * vende en soles: cuántos soles vale un dólar.
     */
    public function setExchangeRate(string $code, float $penPerUsd): GatewayAccount
    {
        $account = $this->connected($code);
        $before = $account->exchangeRate();

        if ($before !== $penPerUsd) {
            $account->update(['settings' => ['exchange_rate' => $penPerUsd] + ($account->settings ?? [])]);
            $this->audit->record('payment_gateways.exchange_rate_changed', $account, ['exchange_rate' => $before], ['gateway' => $code, 'exchange_rate' => $penPerUsd]);
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
     * Pasarelas con las que el comprador puede pagar esta orden ahora, y
     * cuánto cobraría cada una si no cobra en la moneda de la orden.
     *
     * @return list<array{code: string, label: string, charge: array{currency: string, amount: string, rate: ?string}|null}>
     */
    public function availableFor(Order $order): array
    {
        // Las ventas a empresas se cobran según lo pactado, no por aquí
        if (! $order->isPayable() || $order->customer_id === null || (float) $order->total_amount <= 0) {
            return [];
        }

        $order->loadMissing('currency');
        $accounts = GatewayAccount::query()->where('enabled', true)->get()->keyBy('gateway');
        $available = [];

        foreach ($this->gateways as $gateway) {
            $account = $accounts->get($gateway->code());
            $quote = $account ? ChargeQuote::for($gateway, (string) $order->total_amount, $order->currency->code, $account->exchangeRate()) : null;

            if ($quote !== null) {
                $available[] = [
                    'code' => $gateway->code(),
                    'label' => $gateway->label(),
                    // Solo cuando se cobra en otra moneda: el comprador debe saber cuánto antes de ir a pagar
                    'charge' => $quote->rate !== null ? $quote->toArray() : null,
                ];
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
        $gateway = $this->gateway($code);
        $account = $this->account($code);
        $quote = ChargeQuote::for($gateway, (string) $order->total_amount, $order->currency->code, $account->exchangeRate());

        $checkout = $gateway->startCheckout($account->forGateway(), new Charge(
            reference: $order->id,
            title: 'Orden '.str_pad((string) $order->number, 5, '0', STR_PAD_LEFT).' · '.($order->package_title ?? $order->items->first()?->title),
            amount: $quote->amount,
            currency: $quote->currency,
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
            // Lo que se le pidió cobrar a la pasarela: es contra lo que se comprueba el pago
            'amount' => $quote->amount,
            'currency' => $quote->currency,
            'exchange_rate' => $quote->rate,
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

        // Los intentos recientes; donde la pasarela busca por orden basta el último de cada una
        $attempts = PaymentAttempt::query()->where('order_id', $order->id)->latest()->orderByDesc('id')->limit(self::ATTEMPTS_TO_CHECK)->get()
            ->unique(fn (PaymentAttempt $attempt) => $this->gateway($attempt->gateway)->tracksByCheckout() ? $attempt->id : $attempt->gateway);

        foreach ($attempts as $attempt) {
            $account = $this->account($attempt->gateway);

            if ($account === null) {
                continue;
            }

            foreach ($this->gateway($attempt->gateway)->paymentsFor($account->forGateway(), $order->id, $attempt->external_id) as $payment) {
                if ($payment->isApproved()) {
                    return $this->apply($order, $payment, $attempt->gateway);
                }
            }
        }

        return $order;
    }

    /**
     * Reembolso total de una orden pagada. Si la cobró una pasarela, el
     * dinero se devuelve ahí con la cuenta de la organización; si el pago fue
     * por fuera, la organización ya devolvió el dinero y aquí queda registrado.
     *
     * @param  string|null  $manualReference  cómo se devolvió el dinero de un pago por fuera
     */
    public function refund(Order $order, User $actor, string $reason, ?string $manualReference = null): Order
    {
        $order->loadMissing('payment');
        $this->orders->assertRefundable($order);

        $payment = $order->payment;

        if (! $payment->isOnline()) {
            return $this->orders->markRefunded($order, $actor, $reason, $manualReference);
        }

        $account = $this->account($payment->gateway);

        if ($account === null) {
            throw new ApiException(
                ErrorCode::GatewayNotAvailable,
                'La cuenta con la que se cobró este pago ya no está conectada. Vuelve a conectarla en Configuración para poder devolverlo.',
                409
            );
        }

        // Primero el dinero: si la pasarela no devuelve, la orden queda como estaba
        $refundId = $this->gateway($payment->gateway)->refund($account->forGateway(), (string) $payment->reference);

        return $this->orders->markRefunded($order, $actor, $reason, $refundId);
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
     * aprobado, por el importe y la moneda que se le pidió cobrar, la deja
     * pagada; lo que no cuadra queda marcado para que lo resuelva una persona.
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

                // Alguien devolvió el pago en la pasarela sin pasar por el sistema: la orden sigue pagada aquí
                if ($payment->status === GatewayPayment::REFUNDED && $order->status === Order::STATUS_PAID && $order->payment?->reference === $payment->id && $attempt->status !== PaymentAttempt::STATUS_NEEDS_REVIEW) {
                    $problem = 'La pasarela informa que este pago se devolvió desde su panel, pero la orden sigue pagada: reembólsala también aquí.';
                    $attempt->update(['status' => PaymentAttempt::STATUS_NEEDS_REVIEW, 'external_payment_id' => $payment->id, 'review_reason' => $problem]);
                    $this->audit->record('payments.online_needs_review', $attempt, null, ['order' => $order->number, 'gateway' => $code, 'payment' => $payment->id, 'reason' => $problem]);
                }

                return $order;
            }

            // El mismo pago avisado otra vez
            if ($order->payment?->gateway === $code && $order->payment->reference === $payment->id) {
                return $order;
            }

            $problem = match (true) {
                $payment->currency !== $attempt->currency || $payment->amount !== number_format((float) $attempt->amount, 2, '.', '') => "La pasarela cobró {$payment->currency} {$payment->amount} y se le pidió cobrar {$attempt->currency} {$attempt->amount}.",
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
                // Si se cobró en otra moneda, queda anotado cuánto y a qué tipo de cambio
                'notes' => $attempt->exchange_rate !== null ? (new ChargeQuote($attempt->currency, (string) $attempt->amount, (string) $attempt->exchange_rate))->note() : null,
            ]);
        });
    }

    /**
     * Intento al que corresponde el pago: el del cobro que la pasarela
     * indica o, si no lo indica, el último de esa pasarela para la orden.
     */
    private function attemptFor(Order $order, GatewayPayment $payment, string $code): PaymentAttempt
    {
        /** @var Collection<int, PaymentAttempt> $attempts */
        $attempts = PaymentAttempt::query()->where('order_id', $order->id)->where('gateway', $code)->latest()->orderByDesc('id')->get();

        return $attempts->firstWhere('external_payment_id', $payment->id)
            ?? ($payment->checkoutId ? $attempts->firstWhere('external_id', $payment->checkoutId) : null)
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

    private function connected(string $code): GatewayAccount
    {
        return $this->account($code) ?? throw new ApiException(ErrorCode::GatewayNotAvailable, 'Primero hay que conectar la cuenta.', 409);
    }
}
