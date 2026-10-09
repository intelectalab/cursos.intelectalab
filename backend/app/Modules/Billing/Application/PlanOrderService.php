<?php

namespace App\Modules\Billing\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Billing\Domain\Models\Plan;
use App\Modules\Billing\Domain\Models\PlanOrder;
use App\Modules\Billing\Domain\Models\TenantSubscription;
use App\Modules\Files\Application\FileStorage;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Cómo paga una organización su plan: pide el plan (orden pendiente) y la
 * paga en línea o por fuera. Por fuera, informa su pago (un texto o la
 * constancia adjunta) y el administrador de plataforma lo confirma; en
 * línea, lo confirma la pasarela. Al confirmar, el plan queda vigente. Es
 * el mismo recorrido con el que un alumno le paga un curso a su academia.
 */
class PlanOrderService
{
    public const SETTING_PAYMENT_INSTRUCTIONS = 'plan_payment_instructions';

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly PlanOrderNotifier $notifier,
        private readonly FileStorage $storage,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * La organización pide un plan. Solo puede tener una orden pendiente.
     */
    public function request(Tenant $tenant, Plan $plan, User $requester): PlanOrder
    {
        if (! $plan->isActive()) {
            throw new ApiException(ErrorCode::ValidationError, 'Ese plan ya no se ofrece.', 422, ['plan_id' => ['Elige un plan vigente.']]);
        }

        // Una orden que ya pasó su plazo no debe impedir pedir otra
        $this->expireOverdue($tenant->id);

        $order = DB::transaction(function () use ($tenant, $plan, $requester) {
            // Serializa las solicitudes de la misma organización
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->first();

            if (PlanOrder::query()->where('tenant_id', $tenant->id)->where('status', PlanOrder::STATUS_PENDING)->exists()) {
                throw new ApiException(ErrorCode::OrderAlreadyPending, 'Ya tienes una orden de plan pendiente de pago. Págala o cancélala antes de pedir otra.', 409);
            }

            $order = PlanOrder::create([
                'tenant_id' => $tenant->id,
                'number' => (int) DB::selectOne("SELECT nextval('plan_order_number_seq') AS value")->value,
                'plan_id' => $plan->id,
                'plan_name' => $plan->name,
                'billing_months' => $plan->billing_months,
                'max_students' => $plan->max_students,
                'amount' => $plan->price,
                'currency' => $plan->currency,
                'status' => PlanOrder::STATUS_PENDING,
                'requested_by' => $requester->id,
                'expires_at' => now()->addDays(config('billing.order_expiration_days')),
            ]);

            $this->audit->record('subscription.order_created', $tenant, null, ['number' => $order->number, 'plan' => $order->plan_name, 'amount' => $order->amount]);

            return $order;
        });

        $this->notifier->orderRequested($order->setRelation('tenant', $tenant));

        return $order;
    }

    /**
     * La organización informa cómo pagó, para que la plataforma ubique el pago.
     */
    public function informPayment(PlanOrder $order, ?string $reference): PlanOrder
    {
        $this->assertPending($order);
        $wasInformed = $order->isInformed();

        $order->update(['payer_reference' => $reference]);
        $this->notifyIfJustInformed($order, $wasInformed);

        return $order;
    }

    /**
     * La organización adjunta la constancia de su pago (reemplaza la anterior).
     */
    public function attachProof(PlanOrder $order, UploadedFile $upload): PlanOrder
    {
        $this->assertPending($order);
        $wasInformed = $order->isInformed();
        $previous = $order->proof;

        $order->update(['proof_file_id' => $this->storage->store($upload, PlanOrder::PROOF_PURPOSE)->id]);

        if ($previous !== null) {
            $this->storage->delete($previous);
        }

        $this->notifyIfJustInformed($order, $wasInformed);

        return $order->load('proof');
    }

    public function removeProof(PlanOrder $order): PlanOrder
    {
        $this->assertPending($order);

        if (($proof = $order->proof) !== null) {
            $order->update(['proof_file_id' => null]);
            $this->storage->delete($proof);
        }

        return $order->unsetRelation('proof');
    }

    /**
     * La cancela quien la pidió (la organización) o la plataforma.
     */
    public function cancel(PlanOrder $order, ?string $platformAdmin = null, ?string $reason = null): PlanOrder
    {
        $this->assertPending($order);
        $order->loadMissing('tenant');

        $this->context->runForTenant($order->tenant_id, fn () => DB::transaction(function () use ($order, $platformAdmin, $reason) {
            $order->update([
                'status' => PlanOrder::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'resolved_by' => $platformAdmin,
                'notes' => $reason ?? $order->notes,
                // Si la canceló ella misma, no hay nada que avisarle
                'outcome_seen_at' => $platformAdmin === null ? now() : null,
            ]);

            $this->audit->record(
                $platformAdmin ? 'platform.plan_order_cancelled' : 'subscription.order_cancelled',
                $order->tenant,
                null,
                array_filter(['number' => $order->number, 'reason' => $reason, 'platform_admin' => $platformAdmin])
            );
        }));

        if ($platformAdmin !== null) {
            $this->notifier->cancelledByPlatform($order);
        }

        return $order;
    }

    /**
     * El pago llegó: la orden queda pagada y el plan, vigente. Lo confirma el
     * administrador de plataforma (pago por fuera) o la pasarela que lo cobró
     * (`gateway`). Renovar el mismo plan antes de que venza suma el periodo
     * al vencimiento actual; en cualquier otro caso el periodo empieza hoy.
     *
     * @param  array{method: string, reference?: ?string, notes?: ?string, gateway?: string}  $payment
     * @param  string  $resolvedBy  correo del administrador de plataforma o nombre de la pasarela
     */
    public function confirm(PlanOrder $order, array $payment, string $resolvedBy): PlanOrder
    {
        $order = DB::transaction(function () use ($order, $payment, $resolvedBy) {
            $order = PlanOrder::query()->lockForUpdate()->with(['plan', 'tenant'])->findOrFail($order->id);

            // Un pago en línea vale aunque el plazo de la orden haya pasado: ya se cobró
            if (! (isset($payment['gateway']) && $order->status === PlanOrder::STATUS_EXPIRED)) {
                $this->assertPending($order);
            }

            $current = TenantSubscription::query()->where('tenant_id', $order->tenant_id)->first();
            $renewing = $current?->plan_id === $order->plan_id && $current->state() === TenantSubscription::STATE_ACTIVE && $current->current_period_ends_at !== null;
            $endsAt = ($renewing ? $current->current_period_ends_at->copy() : now())->addMonthsNoOverflow($order->billing_months);

            $order->update([
                'status' => PlanOrder::STATUS_PAID,
                'paid_at' => now(),
                'payment_method' => $payment['method'],
                'payment_reference' => $payment['reference'] ?? null,
                'notes' => $payment['notes'] ?? null,
                'resolved_by' => $resolvedBy,
                'gateway_payment_id' => isset($payment['gateway']) ? ($payment['reference'] ?? null) : null,
                'review_reason' => null,
                // Para poder deshacer: cómo estaba la suscripción y hasta cuándo queda
                'previous_subscription' => $current ? $this->subscriptions->raw($current) : null,
                'applied_period_ends_at' => $endsAt,
                'outcome_seen_at' => null,
            ]);

            // Vale el tope que se compró, aunque el plan haya cambiado después
            $this->subscriptions->assign($order->tenant, $order->plan, $endsAt, $resolvedBy, ['max_students' => $order->max_students]);

            $this->context->runForTenant($order->tenant_id, fn () => $this->audit->record('platform.plan_order_confirmed', $order->tenant, null, [
                'number' => $order->number,
                'plan' => $order->plan_name,
                'amount' => $order->amount,
                'method' => $order->payment_method,
                'platform_admin' => $resolvedBy,
            ]));

            return $order;
        });

        $this->notifier->confirmed($order);

        return $order;
    }

    /**
     * Deshace el pago de una orden: la suscripción vuelve a como estaba. Si
     * fue una confirmación a mano hecha por error, la orden vuelve a
     * pendiente. Si el pago lo cobró una pasarela y ya se devolvió
     * (`$refundId`), la orden queda cancelada: el dinero volvió a la academia.
     * Solo si nada cambió el plan después.
     *
     * @param  string|null  $refundId  devolución hecha en la pasarela
     */
    public function revert(PlanOrder $order, string $reason, string $platformAdmin, ?string $refundId = null): PlanOrder
    {
        $refunded = $refundId !== null;

        $order = DB::transaction(function () use ($order, $reason, $platformAdmin, $refundId, $refunded) {
            $order = PlanOrder::query()->lockForUpdate()->with('tenant')->findOrFail($order->id);

            $this->assertRevertible($order, online: $refunded);

            $this->subscriptions->restore($order->tenant, $order->previous_subscription, $platformAdmin);

            $order->update($refunded ? [
                'status' => PlanOrder::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'resolved_by' => $platformAdmin,
                'previous_subscription' => null,
                'applied_period_ends_at' => null,
                'notes' => "Pago devuelto (devolución n.º {$refundId}): {$reason}",
                'outcome_seen_at' => null,
            ] : [
                'status' => PlanOrder::STATUS_PENDING,
                'paid_at' => null,
                'payment_method' => null,
                'payment_reference' => null,
                'resolved_by' => null,
                'previous_subscription' => null,
                'applied_period_ends_at' => null,
                // La academia lo ve en su orden, que vuelve a esperar su pago
                'notes' => "Confirmación anulada: {$reason}",
                'expires_at' => now()->addDays(config('billing.order_expiration_days')),
                'outcome_seen_at' => null,
            ]);

            $this->context->runForTenant($order->tenant_id, fn () => $this->audit->record($refunded ? 'platform.plan_order_refunded' : 'platform.plan_order_reverted', $order->tenant, null, array_filter([
                'number' => $order->number,
                'plan' => $order->plan_name,
                'reason' => $reason,
                'refund' => $refundId,
                'platform_admin' => $platformAdmin,
            ])));

            return $order;
        });

        if ($refunded) {
            $this->notifier->refunded($order, $reason);
        } else {
            $this->notifier->confirmationReverted($order, $reason);
        }

        return $order;
    }

    /**
     * ¿Se puede deshacer el pago de esta orden? Una confirmación a mano se
     * deshace sin más; lo que cobró una pasarela exige devolver el dinero
     * (`online`). En ambos casos, solo si el plan no cambió después.
     */
    public function assertRevertible(PlanOrder $order, bool $online = false): void
    {
        $blocker = match (true) {
            $order->status !== PlanOrder::STATUS_PAID => 'Solo se puede deshacer el pago de una orden pagada.',
            ! $online && $order->wasPaidOnline() => 'Esta orden la cobró la pasarela: usa «Devolver pago» para devolver el dinero.',
            $online && ! $order->wasPaidOnline() => 'Esta orden no se pagó en línea: no hay nada que devolver en la pasarela.',
            $order->applied_period_ends_at === null => 'Esta orden se confirmó antes de que existiera esta opción. Ajusta el plan desde la ficha de la academia.',
            ! $online && PlanOrder::query()->where('tenant_id', $order->tenant_id)->where('status', PlanOrder::STATUS_PENDING)->exists() => 'La academia ya tiene otra orden pendiente. Cancélala antes de reabrir esta.',
            default => null,
        };

        $subscription = TenantSubscription::query()->where('tenant_id', $order->tenant_id)->first();

        // Otra orden o un cambio a mano en la ficha vinieron después: deshacer pisaría ese cambio
        if ($blocker === null && ! ($subscription?->plan_id === $order->plan_id && $subscription->current_period_ends_at?->equalTo($order->applied_period_ends_at))) {
            $blocker = 'El plan de la academia cambió después de este pago. '.($online ? 'Devuelve el dinero desde la cuenta de la pasarela y ajusta el plan en su ficha.' : 'Ajústalo desde su ficha.');
        }

        if ($blocker !== null) {
            throw new ApiException(ErrorCode::PlanOrderNotRevertible, $blocker, 409);
        }
    }

    /**
     * Vence las órdenes pendientes cuyo plazo pasó. Las que ya informaron un
     * pago no vencen: esperan a que la plataforma las confirme o las cancele.
     * Debe correr con acceso a todas las organizaciones, o acotado a una.
     *
     * @return int cantidad de órdenes vencidas
     */
    public function expireOverdue(?string $tenantId = null): int
    {
        $overdue = PlanOrder::query()
            ->when($tenantId, fn ($query, $id) => $query->where('tenant_id', $id))
            ->where('status', PlanOrder::STATUS_PENDING)
            ->where('expires_at', '<', now())
            ->whereNull('payer_reference')
            ->whereNull('proof_file_id')
            ->with('tenant')
            ->get();

        foreach ($overdue as $order) {
            $this->context->runForTenant($order->tenant_id, function () use ($order) {
                $order->update(['status' => PlanOrder::STATUS_EXPIRED, 'outcome_seen_at' => null]);
                $this->audit->record('subscription.order_expired', $order->tenant, null, ['number' => $order->number, 'plan' => $order->plan_name]);
            });

            $this->notifier->expired($order);
        }

        return $overdue->count();
    }

    /**
     * La pasarela cobró algo que no se pudo aplicar a la orden: queda anotado
     * para que la plataforma lo resuelva.
     */
    public function flagForReview(PlanOrder $order, string $reason): void
    {
        if ($order->review_reason === $reason) {
            return;
        }

        $order->loadMissing('tenant')->update(['review_reason' => $reason]);

        $this->context->runForTenant($order->tenant_id, fn () => $this->audit->record('platform.plan_order_needs_review', $order->tenant, null, ['number' => $order->number, 'reason' => $reason]));
        $this->notifier->needsReview($order);
    }

    /**
     * Orden resuelta por alguien más (confirmada, cancelada por la plataforma
     * o vencida) que la organización todavía no vio.
     */
    public function unseenOutcome(string $tenantId): ?PlanOrder
    {
        return PlanOrder::query()
            ->where('tenant_id', $tenantId)
            ->where('status', '!=', PlanOrder::STATUS_PENDING)
            ->whereNull('outcome_seen_at')
            ->latest('updated_at')
            ->orderByDesc('number')
            ->first();
    }

    public function markOutcomeSeen(string $tenantId): void
    {
        PlanOrder::query()->where('tenant_id', $tenantId)->where('status', '!=', PlanOrder::STATUS_PENDING)->whereNull('outcome_seen_at')->update(['outcome_seen_at' => now()]);
    }

    public function paymentInstructions(): ?string
    {
        $value = DB::table('platform_settings')->where('key', self::SETTING_PAYMENT_INSTRUCTIONS)->value('value');

        return $value ? json_decode($value, true) : null;
    }

    public function setPaymentInstructions(?string $text): void
    {
        DB::table('platform_settings')->updateOrInsert(
            ['key' => self::SETTING_PAYMENT_INSTRUCTIONS],
            ['value' => json_encode($text), 'updated_at' => now(), 'created_at' => now()]
        );
    }

    /**
     * Espera la relación proof cargada.
     *
     * @return array<string, mixed>
     */
    public function present(PlanOrder $order): array
    {
        return [
            'id' => $order->id,
            'number' => $order->number,
            'plan_name' => $order->plan_name,
            'billing_months' => $order->billing_months,
            'max_students' => $order->max_students,
            'amount' => $order->amount,
            'currency' => $order->currency,
            'status' => $order->status,
            'payer_reference' => $order->payer_reference,
            // Constancia que adjuntó la organización
            'proof' => $order->proof ? ['name' => $order->proof->original_name, 'size_bytes' => $order->proof->size_bytes] : null,
            'payment_method' => $order->payment_method,
            'payment_reference' => $order->payment_reference,
            // La cobró una pasarela (no la confirmó una persona)
            'paid_online' => $order->wasPaidOnline(),
            'gateway' => $order->gateway,
            'notes' => $order->notes,
            'created_at' => $order->created_at->toIso8601String(),
            // Hasta cuándo espera su pago; si ya se informó un pago, no vence sola
            'expires_at' => $order->expires_at?->toIso8601String(),
            'paid_at' => $order->paid_at?->toIso8601String(),
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
        ];
    }

    private function notifyIfJustInformed(PlanOrder $order, bool $wasInformed): void
    {
        if (! $wasInformed && $order->isInformed()) {
            $this->notifier->paymentInformed($order->loadMissing('tenant'));
        }
    }

    private function assertPending(PlanOrder $order): void
    {
        if (! $order->isPending()) {
            throw new ApiException(ErrorCode::OrderNotPending, 'Esta orden ya no está pendiente.', 409);
        }
    }
}
