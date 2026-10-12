<?php

namespace App\Modules\Sales\Application\Payments\Online;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Sales\Application\Exchange\ExchangeRateService;
use App\Modules\Sales\Application\OrderService;
use App\Modules\Sales\Domain\Models\GatewayAccount;
use App\Modules\Sales\Domain\Models\GatewayError;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\OrderInstallment;
use App\Modules\Sales\Domain\Models\Payment;
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
        private readonly ExchangeRateService $rates,
        private readonly GatewayErrorLog $errors,
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
                'exchange_rate' => $account ? $this->rateFor($account) : null,
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
        $display = $this->errors->watch(GatewayError::SCOPE_TENANT, $code, GatewayError::OPERATION_CONNECT, null, fn () => $gateway->describeAccount($credentials));

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
    public function setExchangeRate(string $code, float $penPerUsd, User $actor): GatewayAccount
    {
        $account = $this->connected($code);

        // Queda en el historial de tipos de cambio de la organización, que es de donde se lee
        $this->rates->recordManual($penPerUsd, $actor);

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
            $quote = $account ? ChargeQuote::for($gateway, $this->amountDue($order), $order->currency->code, $this->rateFor($account)) : null;

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
        $quote = ChargeQuote::for($gateway, $this->amountDue($order), $order->currency->code, $this->rateFor($account));
        // En una orden en cuotas se cobra la siguiente
        $installment = $this->nextInstallment($order);

        $checkout = $this->errors->watch(GatewayError::SCOPE_TENANT, $code, GatewayError::OPERATION_CHECKOUT, $this->subject($order), fn () => $gateway->startCheckout($account->forGateway(), new Charge(
            reference: $order->id,
            title: 'Orden '.str_pad((string) $order->number, 5, '0', STR_PAD_LEFT).' · '.($order->package_title ?? $order->items->first()?->title)
                .($installment ? " · cuota {$installment->number} de ".$order->installments()->count() : ''),
            amount: $quote->amount,
            currency: $quote->currency,
            payerEmail: $order->customer->email,
            payerFirstName: $order->customer->first_name,
            payerLastName: $order->customer->last_name,
            // Pasado el plazo de la orden, la pasarela ya no la cobra. Una cuota posterior no vence: se cobra aunque llegue tarde
            expiresAt: $order->expires_at,
        ), $urls));

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
            // Con qué cuenta se cobra: si después se conecta otra, este pago no se podrá consultar ni devolver con ella
            'account_ref' => $account->reference(),
            'account_name' => $account->display['name'] ?? null,
        ]);
    }

    /**
     * Pregunta a las pasarelas si la orden ya se pagó (el comprador volvió
     * del checkout, o el aviso nunca llegó) y, si es así, aplica el pago.
     */
    public function sync(Order $order): Order
    {
        if (! in_array($order->status, [Order::STATUS_PENDING, Order::STATUS_PARTIAL], true)) {
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

            foreach ($this->paymentsAt($order, $attempt, $account) as $payment) {
                // En una orden en cuotas la pasarela devuelve también los pagos de las cuotas anteriores, ya aplicados
                if ($payment->isApproved() && ! $this->alreadyApplied($order, $payment, $attempt->gateway)) {
                    return $this->apply($order, $payment, $attempt->gateway);
                }
            }
        }

        return $order;
    }

    /**
     * Lo que la pasarela tiene de ese cobro, con la cuenta conectada hoy.
     *
     * @return list<GatewayPayment>
     */
    private function paymentsAt(Order $order, PaymentAttempt $attempt, GatewayAccount $account): array
    {
        return $this->errors->watch(
            GatewayError::SCOPE_TENANT,
            $attempt->gateway,
            GatewayError::OPERATION_SYNC,
            $this->subject($order),
            fn () => $this->gateway($attempt->gateway)->paymentsFor($account->forGateway(), $order->id, $attempt->external_id)
        );
    }

    private function subject(Order $order): string
    {
        return 'Orden '.str_pad((string) $order->number, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Aviso para el personal cuando algún cobro de la orden se hizo con una
     * cuenta distinta de la conectada hoy: con esta no se puede consultar ni
     * devolver. Null si todo se cobró con la cuenta actual, o si no se sabe.
     * Espera attempts cargados.
     */
    public function accountWarning(Order $order): ?string
    {
        $accounts = null;

        foreach ($order->attempts as $attempt) {
            if ($attempt->account_ref === null || ! in_array($attempt->status, [PaymentAttempt::STATUS_APPROVED, PaymentAttempt::STATUS_NEEDS_REVIEW], true)) {
                continue;
            }

            $accounts ??= GatewayAccount::query()->get()->keyBy('gateway');
            $current = $accounts->get($attempt->gateway);
            $label = $this->gateway($attempt->gateway)->label();
            $was = $attempt->account_name ?? $attempt->account_ref;

            if ($current === null) {
                return "Este pago lo cobró la cuenta «{$was}» de {$label}, que ya no está conectada. Para consultarlo o devolverlo desde aquí hay que volver a conectarla en Configuración.";
            }

            if ($current->reference() !== $attempt->account_ref) {
                $now = $current->display['name'] ?? $current->reference();

                return "Este pago lo cobró la cuenta «{$was}» de {$label} y hoy está conectada «{$now}». Con la cuenta actual no se puede consultar ni devolver: vuelve a conectar «{$was}» en Configuración, o gestiona el pago desde esa cuenta de {$label}.";
            }
        }

        return null;
    }

    /**
     * Devolver con otra cuenta que la que cobró no funciona, y la pasarela
     * solo responde que no encuentra el pago: se dice antes y con claridad.
     */
    private function assertCollectedBy(Order $order, Payment $payment, GatewayAccount $account): void
    {
        $attempt = PaymentAttempt::query()->where('order_id', $order->id)->where('gateway', $payment->gateway)->where('external_payment_id', $payment->reference)->first();

        if ($attempt?->account_ref === null || $attempt->account_ref === $account->reference()) {
            return;
        }

        $label = $this->gateway($payment->gateway)->label();
        $was = $attempt->account_name ?? $attempt->account_ref;
        $now = $account->display['name'] ?? $account->reference();

        throw new ApiException(
            ErrorCode::GatewayNotAvailable,
            "Este pago lo cobró la cuenta «{$was}» de {$label} y hoy está conectada «{$now}»: con esta no se puede devolver. Vuelve a conectar «{$was}» en Configuración, o devuelve el dinero desde esa cuenta de {$label} y marca aquí que ya se devolvió por otro medio.",
            409
        );
    }

    /**
     * Lo que toca cobrar ahora: la siguiente cuota o, sin plan de pagos, el total.
     */
    private function amountDue(Order $order): string
    {
        return (string) ($this->nextInstallment($order)?->amount ?? $order->total_amount);
    }

    private function nextInstallment(Order $order): ?OrderInstallment
    {
        return $order->installments()->where('status', OrderInstallment::STATUS_PENDING)->first();
    }

    private function alreadyApplied(Order $order, GatewayPayment $payment, string $code): bool
    {
        return Payment::query()->where('order_id', $order->id)->where('gateway', $code)->where('reference', $payment->id)->exists();
    }

    /**
     * Reembolso total de una orden pagada. Si la cobró una pasarela, el
     * dinero se devuelve ahí con la cuenta de la organización; si el pago fue
     * por fuera, la organización ya devolvió el dinero y aquí queda registrado.
     *
     * @param  string|null  $manualReference  cómo se devolvió el dinero de un pago por fuera
     * @param  bool  $outsideGateway  no pedir la devolución a la pasarela: el dinero ya se devolvió por otro medio
     */
    public function refund(Order $order, User $actor, string $reason, ?string $manualReference = null, bool $outsideGateway = false): Order
    {
        $this->orders->assertRefundable($order);

        // La pasarela ya no deja devolver (pasó su plazo, la cuenta se cerró): la organización devolvió el dinero
        // por otro medio y aquí solo queda registrado, con la referencia que indique
        if ($outsideGateway) {
            return $this->orders->markRefunded($order, $actor, $reason, $manualReference);
        }

        // Lo cobrado en línea que falta devolver: uno si la orden se pagó de una vez, uno por cuota si fue en cuotas
        $online = $order->payments()->where('status', Payment::STATUS_CONFIRMED)->orderBy('paid_at')->orderBy('id')->get()
            ->filter(fn (Payment $payment) => $payment->isOnline())->values();

        // Antes de mover dinero: las cuentas con las que se cobró deben seguir conectadas
        $accounts = [];

        foreach ($online->pluck('gateway')->unique() as $code) {
            $accounts[$code] = $this->account($code) ?? throw new ApiException(
                ErrorCode::GatewayNotAvailable,
                'La cuenta con la que se cobró este pago ya no está conectada. Vuelve a conectarla en Configuración para poder devolverlo.',
                409
            );
        }

        foreach ($online as $payment) {
            $this->assertCollectedBy($order, $payment, $accounts[$payment->gateway]);
        }

        foreach ($online as $index => $payment) {
            $gateway = $this->gateway($payment->gateway);
            $account = $accounts[$payment->gateway];

            try {
                // Si el dinero ya se devolvió desde la cuenta de la pasarela, aquí solo falta registrarlo
                $refundId = $this->alreadyRefundedAtGateway($gateway, $account, $order, (string) $payment->reference)
                    ? 'Devuelto desde la cuenta de '.$gateway->label()
                    : $this->errors->watch(GatewayError::SCOPE_TENANT, $payment->gateway, GatewayError::OPERATION_REFUND, $this->subject($order), fn () => $gateway->refund($account->forGateway(), (string) $payment->reference));
            } catch (ApiException $error) {
                // Primero el dinero: si la pasarela no devuelve, la orden queda como estaba. Lo ya devuelto queda registrado
                throw $index === 0 ? $error : new ApiException(
                    $error->errorCode,
                    "Se devolvieron {$index} de {$online->count()} pagos en línea y el siguiente no se pudo devolver: {$error->getMessage()} La orden sigue como estaba: vuelve a pulsar «Reembolsar» para continuar con lo que falta.",
                    $error->status,
                    $error->details
                );
            }

            $this->orders->markPaymentRefunded($payment, $actor, $reason, $refundId);
        }

        // Los pagos recibidos por fuera los devuelve la organización: aquí quedan registrados
        return $this->orders->markRefunded($order, $actor, $reason, $manualReference);
    }

    /**
     * El personal pide volver a preguntar a las pasarelas por una orden, esté
     * como esté: aplica un pago que no se había registrado y deja en revisión
     * lo que no cuadra (un cobro que llegó tarde, una devolución hecha desde
     * el panel de la pasarela).
     */
    public function recheck(Order $order): Order
    {
        $attempts = PaymentAttempt::query()->where('order_id', $order->id)->latest()->orderByDesc('id')->limit(self::ATTEMPTS_TO_CHECK)->get()
            ->unique(fn (PaymentAttempt $attempt) => $this->gateway($attempt->gateway)->tracksByCheckout() ? $attempt->id : $attempt->gateway);

        foreach ($attempts as $attempt) {
            $account = $this->account($attempt->gateway);

            if ($account === null) {
                continue;
            }

            foreach ($this->paymentsAt($order, $attempt, $account) as $payment) {
                if ($payment->isApproved() ? ! $this->alreadyApplied($order, $payment, $attempt->gateway) : $payment->status === GatewayPayment::REFUNDED) {
                    $this->apply($order, $payment, $attempt->gateway);
                }
            }
        }

        return $order->refresh();
    }

    /**
     * Una persona ya resolvió un cobro en revisión (lo devolvió en la
     * pasarela o lo aplicó a mano): deja de avisar y queda quién y cómo.
     */
    public function resolveReview(PaymentAttempt $attempt, User $actor, string $note): PaymentAttempt
    {
        if ($attempt->status !== PaymentAttempt::STATUS_NEEDS_REVIEW) {
            throw new ApiException(ErrorCode::PaymentReviewClosed, 'Este cobro ya no está en revisión.', 409);
        }

        $attempt->update([
            'status' => PaymentAttempt::STATUS_RESOLVED,
            'resolved_at' => now(),
            'resolved_by' => $actor->id,
            'resolution_note' => $note,
        ]);

        $this->audit->record('payments.online_review_resolved', $attempt, ['status' => PaymentAttempt::STATUS_NEEDS_REVIEW], [
            'status' => PaymentAttempt::STATUS_RESOLVED,
            'order' => $attempt->order->number,
            'gateway' => $attempt->gateway,
            'payment' => $attempt->external_payment_id,
            'note' => $note,
        ]);

        return $attempt;
    }

    /**
     * La pasarela informa que ese pago ya está devuelto por completo. Si no
     * se puede consultar, se sigue con la devolución normal.
     */
    private function alreadyRefundedAtGateway(OnlineGateway $gateway, GatewayAccount $account, Order $order, string $paymentId): bool
    {
        $checkoutId = PaymentAttempt::query()->where('order_id', $order->id)->where('external_payment_id', $paymentId)->value('external_id');

        try {
            foreach ($gateway->paymentsFor($account->forGateway(), $order->id, $checkoutId) as $found) {
                if ($found->id === $paymentId) {
                    return $found->status === GatewayPayment::REFUNDED;
                }
            }
        } catch (ApiException) {
            // La devolución dirá qué pasa con la pasarela
        }

        return false;
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

        $payment = $this->errors->watch(GatewayError::SCOPE_TENANT, $code, GatewayError::OPERATION_WEBHOOK, null, fn () => $this->gateway($code)->paymentFromWebhook($account->forGateway(), $request));

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

                // Alguien devolvió el pago en la pasarela sin pasar por el sistema: aquí sigue contando como cobrado
                if ($payment->status === GatewayPayment::REFUNDED && $this->stillCounted($order, $payment, $code) && ! $this->underReview($attempt, $payment)) {
                    $problem = 'La pasarela informa que este pago se devolvió desde su panel, pero la orden sigue pagada: reembólsala también aquí.';
                    $attempt->update(['status' => PaymentAttempt::STATUS_NEEDS_REVIEW, 'external_payment_id' => $payment->id, 'review_reason' => $problem]);
                    $this->audit->record('payments.online_needs_review', $attempt, null, ['order' => $order->number, 'gateway' => $code, 'payment' => $payment->id, 'reason' => $problem]);
                }

                return $order;
            }

            // El mismo pago avisado otra vez
            if ($this->alreadyApplied($order, $payment, $code)) {
                return $order;
            }

            $problem = match (true) {
                $payment->currency !== $attempt->currency || $payment->amount !== number_format((float) $attempt->amount, 2, '.', '') => "La pasarela cobró {$payment->currency} {$payment->amount} y se le pidió cobrar {$attempt->currency} {$attempt->amount}.",
                $order->status === Order::STATUS_PAID => 'La orden ya estaba pagada por otro medio: hay un cobro de más.',
                ! in_array($order->status, [Order::STATUS_PENDING, Order::STATUS_PARTIAL], true) => 'La orden ya no estaba pendiente cuando llegó el pago.',
                default => null,
            };

            if ($problem !== null) {
                if (! $this->underReview($attempt, $payment)) {
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
     * El pago sigue contando como cobrado en una orden que no se ha reembolsado.
     */
    private function stillCounted(Order $order, GatewayPayment $payment, string $code): bool
    {
        return in_array($order->status, [Order::STATUS_PAID, Order::STATUS_PARTIAL], true)
            && Payment::query()->where('order_id', $order->id)->where('gateway', $code)->where('reference', $payment->id)->where('status', Payment::STATUS_CONFIRMED)->exists();
    }

    /**
     * El intento ya está en revisión, o una persona ya resolvió ese mismo cobro: no se vuelve a avisar.
     */
    private function underReview(PaymentAttempt $attempt, GatewayPayment $payment): bool
    {
        return $attempt->status === PaymentAttempt::STATUS_NEEDS_REVIEW
            || ($attempt->status === PaymentAttempt::STATUS_RESOLVED && $attempt->external_payment_id === $payment->id);
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

    /**
     * Soles por dólar con los que cobra la organización: el vigente de su
     * historial; si aún no tiene ninguno, el que quedó escrito en la cuenta.
     */
    private function rateFor(GatewayAccount $account): ?float
    {
        return $this->rates->currentRate() ?? $account->exchangeRate();
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
