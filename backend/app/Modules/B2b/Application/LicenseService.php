<?php

namespace App\Modules\B2b\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\B2b\Domain\Models\CorporateAccount;
use App\Modules\B2b\Domain\Models\CorporateMember;
use App\Modules\B2b\Domain\Models\License;
use App\Modules\B2b\Domain\Models\LicenseAssignment;
use App\Modules\Enrollments\Application\EnrollmentService;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Enrollments\Domain\Models\EnrollmentProgress;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\OrderItem;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Licencias corporativas (RF-B2B-001, RN-010): la venta pagada entrega los
 * cupos; asignar uno a un trabajador lo matricula en el curso.
 */
class LicenseService
{
    public function __construct(
        private readonly EnrollmentService $enrollments,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Entrega las licencias de una venta corporativa: una por cada cupo
     * comprado. Repetirlo no entrega de nuevo.
     */
    public function issueForOrder(Order $order): void
    {
        if ($order->company_id === null || License::query()->where('order_id', $order->id)->exists()) {
            return;
        }

        $account = CorporateAccount::forCompany($order->company_id);
        $expiresAt = now()->addMonths(License::MONTHS_TO_ASSIGN);
        $now = now();

        $rows = $order->items()->get()->flatMap(fn (OrderItem $item) => Collection::times($item->quantity, fn () => [
            'id' => (string) Str::uuid7(),
            'tenant_id' => $this->context->tenantId(),
            'corporate_account_id' => $account->id,
            'course_id' => $item->course_id,
            'order_id' => $order->id,
            'status' => License::STATUS_AVAILABLE,
            'expires_at' => $expiresAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]));

        $rows->chunk(500)->each(fn (Collection $chunk) => License::query()->insert($chunk->all()));

        $this->audit->record('licenses.issued', $order, null, [
            'order' => $order->number,
            'licenses' => $rows->count(),
            'expires_at' => $expiresAt->toDateString(),
        ]);
    }

    /**
     * Entrega las licencias de una venta que aún no se paga (p. ej. una
     * empresa que paga a 30 días). Queda quién lo autorizó y por qué.
     */
    public function releaseUnpaid(Order $order, User $actor, string $reason): Order
    {
        return DB::transaction(function () use ($order, $actor, $reason) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->company_id === null) {
                throw new ApiException(ErrorCode::OrderNotCorporate, 'Solo una venta a empresa entrega licencias.', 409);
            }

            if ($order->state() !== Order::STATUS_PENDING || $order->licenses_released_at !== null) {
                throw new ApiException(ErrorCode::OrderNotPending, 'Solo se pueden adelantar las licencias de una orden pendiente de pago.', 409, ['status' => $order->state()]);
            }

            $order->update([
                'licenses_released_at' => now(),
                'licenses_released_by' => $actor->id,
                'licenses_release_reason' => $reason,
            ]);

            $this->issueForOrder($order);

            $this->audit->record('licenses.released_unpaid', $order, null, [
                'order' => $order->number,
                'total' => $order->total_amount,
                'reason' => $reason,
            ]);

            return $order;
        });
    }

    /**
     * Venta cancelada: sus licencias sin asignar dejan de estar disponibles.
     * Las ya asignadas se conservan, porque el trabajador ya está cursando.
     */
    public function cancelForOrder(Order $order): void
    {
        $cancelled = License::query()
            ->where('order_id', $order->id)
            ->where('status', License::STATUS_AVAILABLE)
            ->update(['status' => License::STATUS_CANCELLED]);

        if ($cancelled > 0) {
            $this->audit->record('licenses.cancelled', $order, null, ['order' => $order->number, 'licenses' => $cancelled]);
        }
    }

    /**
     * Asigna una licencia disponible a un trabajador de la empresa y lo
     * matricula (TC-B2B-001). Una licencia ya usada se rechaza (TC-B2B-002).
     */
    public function assign(License $license, User $student, User $actor): LicenseAssignment
    {
        return DB::transaction(function () use ($license, $student, $actor) {
            $license = License::query()->lockForUpdate()->findOrFail($license->id);

            if ($license->state() !== License::STATUS_AVAILABLE) {
                throw new ApiException(ErrorCode::LicenseNotAvailable, 'Esta licencia ya no está disponible.', 409, ['status' => $license->state()]);
            }

            $isMember = CorporateMember::query()
                ->where('corporate_account_id', $license->corporate_account_id)
                ->where('user_id', $student->id)
                ->exists();

            if (! $isMember) {
                throw new ApiException(ErrorCode::ValidationError, 'La persona no pertenece a esta empresa.', 422, [
                    'student_ids' => ["{$student->email} no es un trabajador registrado de la empresa."],
                ]);
            }

            $alreadyEnrolled = Enrollment::query()
                ->where('course_id', $license->course_id)
                ->where('student_id', $student->id)
                ->where('status', Enrollment::STATUS_ACTIVE)
                ->exists();

            if ($alreadyEnrolled) {
                throw new ApiException(ErrorCode::AlreadyEnrolled, trim("{$student->first_name} {$student->last_name}").' ya tiene una matrícula en este curso.', 409);
            }

            // La vigencia de la matrícula corre desde hoy con los días de acceso del curso
            $enrollment = $this->enrollments
                ->enroll($license->course, [$student->id], $actor, source: Enrollment::SOURCE_LICENSE, requirePublished: false)
                ->first();

            $assignment = LicenseAssignment::create([
                'license_id' => $license->id,
                'student_id' => $student->id,
                'enrollment_id' => $enrollment->id,
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
            ]);

            $license->update(['status' => License::STATUS_ASSIGNED]);

            $this->audit->record('licenses.assigned', $license, ['status' => License::STATUS_AVAILABLE], [
                'status' => License::STATUS_ASSIGNED,
                'student' => $student->email,
                'course' => $license->course->title,
            ]);

            return $assignment;
        });
    }

    /**
     * Asigna a varios trabajadores tomando licencias disponibles del curso,
     * primero las que vencen antes. Si no alcanzan para todos, no asigna ninguna.
     *
     * @param  list<string>  $studentIds
     * @return Collection<int, LicenseAssignment>
     */
    public function assignFromPool(CorporateAccount $account, string $courseId, array $studentIds, User $actor): Collection
    {
        $studentIds = array_values(array_unique($studentIds));

        return DB::transaction(function () use ($account, $courseId, $studentIds, $actor) {
            $licenses = License::query()
                ->where('corporate_account_id', $account->id)
                ->where('course_id', $courseId)
                ->assignable()
                ->orderBy('expires_at')
                ->orderBy('id')
                ->limit(count($studentIds))
                ->lockForUpdate()
                ->get();

            if ($licenses->count() < count($studentIds)) {
                throw new ApiException(
                    ErrorCode::LicenseNotAvailable,
                    $licenses->isEmpty()
                        ? 'No quedan licencias disponibles de este curso.'
                        : "Solo quedan {$licenses->count()} licencias disponibles de este curso.",
                    409,
                    ['available' => $licenses->count()]
                );
            }

            $students = User::query()->whereIn('id', $studentIds)->get()->keyBy('id');

            return collect($studentIds)->values()->map(function (string $studentId, int $index) use ($licenses, $students, $actor) {
                $student = $students->get($studentId) ?? throw new ApiException(ErrorCode::ValidationError, 'La persona no existe.', 422);

                return $this->assign($licenses[$index], $student, $actor);
            });
        });
    }

    /**
     * Retira la licencia a un trabajador que todavía no empezó el curso: se
     * cancela su matrícula y el cupo vuelve a estar disponible.
     */
    public function revoke(LicenseAssignment $assignment, User $actor): License
    {
        return DB::transaction(function () use ($assignment, $actor) {
            $assignment = LicenseAssignment::query()->lockForUpdate()->findOrFail($assignment->id);

            if ($assignment->revoked_at !== null) {
                throw new ApiException(ErrorCode::LicenseNotAvailable, 'Esta asignación ya fue retirada.', 409);
            }

            $enrollment = $assignment->enrollment;
            $started = $enrollment && ($enrollment->completed_at !== null
                || EnrollmentProgress::query()->where('enrollment_id', $enrollment->id)->exists());

            if ($started) {
                throw new ApiException(ErrorCode::LicenseInUse, 'El trabajador ya empezó el curso: la licencia no se puede retirar.', 409);
            }

            if ($enrollment?->isActive()) {
                $this->enrollments->cancel($enrollment);
            }

            $assignment->update(['revoked_at' => now(), 'revoked_by' => $actor->id]);

            $license = $assignment->license;
            $license->update(['status' => License::STATUS_AVAILABLE]);

            $this->audit->record('licenses.revoked', $license, ['status' => License::STATUS_ASSIGNED], [
                'status' => License::STATUS_AVAILABLE,
                'student' => $assignment->student?->email,
            ]);

            return $license;
        });
    }
}
