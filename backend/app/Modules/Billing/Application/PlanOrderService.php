<?php

namespace App\Modules\Billing\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Billing\Domain\Models\Plan;
use App\Modules\Billing\Domain\Models\PlanOrder;
use App\Modules\Billing\Domain\Models\TenantSubscription;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Cómo paga una organización su plan: pide el plan (orden pendiente), paga
 * por fuera con las instrucciones de la plataforma y el administrador de
 * plataforma confirma el pago recibido; al confirmar, el plan queda vigente.
 * Es el mismo recorrido con el que un alumno le paga un curso a su academia.
 */
class PlanOrderService
{
    public const SETTING_PAYMENT_INSTRUCTIONS = 'plan_payment_instructions';

    public function __construct(
        private readonly SubscriptionService $subscriptions,
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

        return DB::transaction(function () use ($tenant, $plan, $requester) {
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
            ]);

            $this->audit->record('subscription.order_created', $tenant, null, ['number' => $order->number, 'plan' => $order->plan_name, 'amount' => $order->amount]);

            return $order;
        });
    }

    /**
     * La organización informa cómo pagó, para que la plataforma ubique el pago.
     */
    public function informPayment(PlanOrder $order, ?string $reference): PlanOrder
    {
        $this->assertPending($order);
        $order->update(['payer_reference' => $reference]);

        return $order;
    }

    /**
     * La cancela quien la pidió (la organización) o la plataforma.
     */
    public function cancel(PlanOrder $order, ?string $platformAdmin = null, ?string $reason = null): PlanOrder
    {
        $this->assertPending($order);
        $order->loadMissing('tenant');

        return $this->context->runForTenant($order->tenant_id, fn () => DB::transaction(function () use ($order, $platformAdmin, $reason) {
            $order->update([
                'status' => PlanOrder::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'resolved_by' => $platformAdmin,
                'notes' => $reason ?? $order->notes,
            ]);

            $this->audit->record(
                $platformAdmin ? 'platform.plan_order_cancelled' : 'subscription.order_cancelled',
                $order->tenant,
                null,
                array_filter(['number' => $order->number, 'reason' => $reason, 'platform_admin' => $platformAdmin])
            );

            return $order;
        }));
    }

    /**
     * La plataforma confirma que recibió el pago: la orden queda pagada y el
     * plan, vigente. Renovar el mismo plan antes de que venza suma el periodo
     * al vencimiento actual; en cualquier otro caso el periodo empieza hoy.
     *
     * @param  array{method: string, reference?: ?string, notes?: ?string}  $payment
     */
    public function confirm(PlanOrder $order, array $payment, string $platformAdmin): PlanOrder
    {
        $this->assertPending($order);

        return DB::transaction(function () use ($order, $payment, $platformAdmin) {
            $order = PlanOrder::query()->lockForUpdate()->with(['plan', 'tenant'])->findOrFail($order->id);
            $this->assertPending($order);

            $current = TenantSubscription::query()->where('tenant_id', $order->tenant_id)->first();
            $renewing = $current?->plan_id === $order->plan_id && $current->state() === TenantSubscription::STATE_ACTIVE && $current->current_period_ends_at !== null;
            $endsAt = ($renewing ? $current->current_period_ends_at->copy() : now())->addMonthsNoOverflow($order->billing_months);

            $order->update([
                'status' => PlanOrder::STATUS_PAID,
                'paid_at' => now(),
                'payment_method' => $payment['method'],
                'payment_reference' => $payment['reference'] ?? null,
                'notes' => $payment['notes'] ?? null,
                'resolved_by' => $platformAdmin,
            ]);

            // Vale el tope que se compró, aunque el plan haya cambiado después
            $this->subscriptions->assign($order->tenant, $order->plan, $endsAt, $platformAdmin, ['max_students' => $order->max_students]);

            $this->context->runForTenant($order->tenant_id, fn () => $this->audit->record('platform.plan_order_confirmed', $order->tenant, null, [
                'number' => $order->number,
                'plan' => $order->plan_name,
                'amount' => $order->amount,
                'method' => $order->payment_method,
                'platform_admin' => $platformAdmin,
            ]));

            return $order;
        });
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
            'payment_method' => $order->payment_method,
            'payment_reference' => $order->payment_reference,
            'notes' => $order->notes,
            'created_at' => $order->created_at->toIso8601String(),
            'paid_at' => $order->paid_at?->toIso8601String(),
            'cancelled_at' => $order->cancelled_at?->toIso8601String(),
        ];
    }

    private function assertPending(PlanOrder $order): void
    {
        if (! $order->isPending()) {
            throw new ApiException(ErrorCode::OrderNotPending, 'Esta orden ya no está pendiente.', 409);
        }
    }
}
