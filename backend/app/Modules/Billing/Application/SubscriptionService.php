<?php

namespace App\Modules\Billing\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Billing\Domain\Models\Plan;
use App\Modules\Billing\Domain\Models\TenantSubscription;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Suscripción de cada organización a la plataforma: prueba gratuita al
 * registrarse y, después, un plan con tope de alumnos y fecha de vencimiento.
 * "Alumno" es una persona con al menos una matrícula activa.
 */
class SubscriptionService
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Prueba gratuita de una organización recién creada. Debe llamarse con
     * la organización ya puesta en contexto.
     */
    public function startTrial(Tenant $tenant): TenantSubscription
    {
        return TenantSubscription::query()->firstOrCreate(['tenant_id' => $tenant->id], [
            'status' => TenantSubscription::STATUS_TRIAL,
            'trial_ends_at' => now()->addDays(config('billing.trial_days')),
            'max_students' => config('billing.trial_max_students'),
        ]);
    }

    /**
     * Suscripción de la organización; null solo si nunca se le creó una.
     */
    public function of(string $tenantId): ?TenantSubscription
    {
        return TenantSubscription::query()->where('tenant_id', $tenantId)->with('plan')->first();
    }

    /**
     * Alumnos que cuentan para el cupo: personas con al menos una matrícula activa.
     */
    public function studentsIn(string $tenantId): int
    {
        return (int) DB::table('enrollments')->where('tenant_id', $tenantId)->where('status', 'ACTIVE')->distinct()->count('student_id');
    }

    /**
     * La plataforma asigna un plan (o deja a la organización sin límites, con
     * $plan en null). Sin fecha, el periodo empieza hoy y dura lo que el plan.
     *
     * @param  array{max_students?: int|null}  $terms  condiciones compradas, si difieren de las que el plan ofrece hoy
     */
    public function assign(Tenant $tenant, ?Plan $plan, ?CarbonInterface $endsAt, string $platformAdmin, array $terms = []): TenantSubscription
    {
        return $this->context->runForTenant($tenant->id, fn () => DB::transaction(function () use ($tenant, $plan, $endsAt, $platformAdmin, $terms) {
            $subscription = TenantSubscription::query()->firstOrNew(['tenant_id' => $tenant->id]);
            $before = $this->snapshot($subscription);

            $subscription->fill([
                'plan_id' => $plan?->id,
                'status' => TenantSubscription::STATUS_ACTIVE,
                'trial_ends_at' => null,
                'current_period_ends_at' => $plan === null ? null : ($endsAt ?? now()->addMonthsNoOverflow($plan->billing_months)),
                'max_students' => array_key_exists('max_students', $terms) ? $terms['max_students'] : $plan?->max_students,
            ])->save();

            $this->audit->record('platform.subscription_changed', $tenant, $before, $this->snapshot($subscription->load('plan')) + ['platform_admin' => $platformAdmin]);

            return $subscription;
        }));
    }

    /**
     * La suscripción tal como está guardada, para poder volver a ella.
     *
     * @return array{plan_id: ?string, status: string, trial_ends_at: ?string, current_period_ends_at: ?string, max_students: ?int}
     */
    public function raw(TenantSubscription $subscription): array
    {
        return [
            'plan_id' => $subscription->plan_id,
            'status' => $subscription->status,
            'trial_ends_at' => $subscription->trial_ends_at?->toIso8601String(),
            'current_period_ends_at' => $subscription->current_period_ends_at?->toIso8601String(),
            'max_students' => $subscription->max_students,
        ];
    }

    /**
     * Devuelve la suscripción a un estado anterior (ver raw()), al deshacer
     * la confirmación de un pago.
     *
     * @param  array{plan_id: ?string, status: string, trial_ends_at: ?string, current_period_ends_at: ?string, max_students: ?int}|null  $previous  null = no tenía suscripción
     */
    public function restore(Tenant $tenant, ?array $previous, string $platformAdmin): void
    {
        $this->context->runForTenant($tenant->id, fn () => DB::transaction(function () use ($tenant, $previous, $platformAdmin) {
            $subscription = TenantSubscription::query()->where('tenant_id', $tenant->id)->with('plan')->firstOrFail();
            $before = $this->snapshot($subscription);

            if ($previous === null) {
                $subscription->delete();
            } else {
                $subscription->fill($previous)->save();
                $subscription->unsetRelation('plan')->load('plan');
            }

            $this->audit->record('platform.subscription_changed', $tenant, $before, ($previous === null ? [] : $this->snapshot($subscription)) + ['platform_admin' => $platformAdmin, 'reverted' => true]);
        }));
    }

    /**
     * Cambia hasta cuándo dura la prueba de una organización que sigue en ella.
     */
    public function extendTrial(Tenant $tenant, CarbonInterface $until, string $platformAdmin): TenantSubscription
    {
        return $this->context->runForTenant($tenant->id, fn () => DB::transaction(function () use ($tenant, $until, $platformAdmin) {
            $subscription = TenantSubscription::query()->where('tenant_id', $tenant->id)->where('status', TenantSubscription::STATUS_TRIAL)->firstOrFail();
            $before = $this->snapshot($subscription);

            $subscription->update(['trial_ends_at' => $until]);

            $this->audit->record('platform.subscription_changed', $tenant, $before, $this->snapshot($subscription) + ['platform_admin' => $platformAdmin]);

            return $subscription;
        }));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function present(?TenantSubscription $subscription, int $students): ?array
    {
        if ($subscription === null) {
            return null;
        }

        $state = $subscription->state();
        $endsAt = $subscription->endsAt();
        $access = $subscription->access();
        $graceEndsAt = $access === TenantSubscription::ACCESS_FULL ? null : $subscription->graceEndsAt();

        return [
            'state' => $state,
            // FULL, GRACE (vencido, aún opera con aviso) o READ_ONLY (solo consulta)
            'access' => $access,
            // Desde cuándo queda (o quedó) en solo lectura; null mientras no haya vencido
            'grace_ends_at' => $graceEndsAt?->toIso8601String(),
            'grace_days_left' => $graceEndsAt === null ? null : (int) max(0, now()->startOfDay()->diffInDays($graceEndsAt->copy()->startOfDay(), false)),
            'plan' => $subscription->plan ? PlanPresenter::plan($subscription->plan) : null,
            // Fin de la prueba o del periodo pagado; null = sin vencimiento
            'ends_at' => $endsAt?->toIso8601String(),
            // Días de calendario que faltan; el día del vencimiento cuenta como 0
            'days_left' => $endsAt === null ? null : (int) max(0, now()->startOfDay()->diffInDays($endsAt->copy()->startOfDay(), false)),
            'max_students' => $subscription->max_students,
            'students' => $students,
            // Cupo libre; null = sin tope
            'seats_left' => $subscription->max_students === null ? null : max(0, $subscription->max_students - $students),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function snapshot(TenantSubscription $subscription): ?array
    {
        if (! $subscription->exists) {
            return null;
        }

        return [
            'status' => $subscription->status,
            'plan' => $subscription->plan?->name,
            'ends_at' => ($subscription->status === TenantSubscription::STATUS_TRIAL ? $subscription->trial_ends_at : $subscription->current_period_ends_at)?->toIso8601String(),
            'max_students' => $subscription->max_students,
        ];
    }
}
