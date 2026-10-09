<?php

namespace App\Modules\Billing\Application;

use App\Modules\Billing\Domain\Models\TenantSubscription;
use App\Modules\Enrollments\Application\EnrollmentQuota;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * El cupo de alumnos lo pone el plan (o la prueba) de la organización. Sin
 * suscripción o sin tope, cabe cualquiera.
 */
class PlanQuota implements EnrollmentQuota
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SubscriptionService $subscriptions,
    ) {}

    public function hasRoomFor(array $studentIds): bool
    {
        return $this->shortfall($studentIds, lock: false) === null;
    }

    public function assertRoomFor(array $studentIds): void
    {
        $shortfall = $this->shortfall($studentIds, lock: true);

        if ($shortfall === null) {
            return;
        }

        ['max' => $max, 'students' => $students, 'new' => $new] = $shortfall;

        throw new ApiException(
            ErrorCode::StudentLimitReached,
            "La academia tiene {$students} de los {$max} alumnos que permite su plan"
                .($new === 1 ? ' y no queda lugar para uno más.' : " y no queda lugar para {$new} más.")
                .' Para matricular a más personas hay que pasar a un plan mayor en Configuración → Mi plan.',
            409,
            ['max_students' => $max, 'students' => $students, 'requested' => $new]
        );
    }

    /**
     * @param  list<string>  $studentIds
     * @return array{max: int, students: int, new: int}|null null si caben
     */
    private function shortfall(array $studentIds, bool $lock): ?array
    {
        $tenantId = $this->context->tenantId();

        if ($tenantId === null || $studentIds === []) {
            return null;
        }

        // La fila de la suscripción hace de candado: dos matrículas simultáneas cuentan una tras otra
        $max = TenantSubscription::query()->where('tenant_id', $tenantId)->when($lock, fn ($query) => $query->lockForUpdate())->value('max_students');

        if ($max === null) {
            return null;
        }

        $current = DB::table('enrollments')
            ->where('tenant_id', $tenantId)
            ->where('status', 'ACTIVE')
            ->whereIn('student_id', $studentIds)
            ->distinct()
            ->pluck('student_id')
            ->all();
        $new = count(array_diff(array_unique($studentIds), $current));
        $students = $this->subscriptions->studentsIn($tenantId);

        return $new > 0 && $students + $new > $max ? ['max' => (int) $max, 'students' => $students, 'new' => $new] : null;
    }
}
