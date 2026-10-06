<?php

namespace App\Modules\B2b\Application;

use App\Modules\B2b\Domain\Models\CorporateAccount;
use App\Modules\B2b\Domain\Models\CorporateMember;
use App\Modules\B2b\Domain\Models\License;
use App\Modules\B2b\Domain\Models\Quotation;
use App\Modules\Certificates\Domain\Models\Certificate;
use App\Modules\Enrollments\Application\ProgressService;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Sales\Domain\Models\Order;
use Illuminate\Support\Collection;

/**
 * Lo que una empresa cliente tiene con la academia: licencias por curso y a
 * quién están asignadas, sus personas, sus compras y sus cotizaciones por responder.
 * Es la misma vista para el responsable (su portal) y para el personal.
 */
class AccountOverview
{
    public function __construct(private readonly ProgressService $progress) {}

    /**
     * @return array<string, mixed>
     */
    public function build(CorporateAccount $account): array
    {
        $company = $account->company;

        $licenses = License::query()
            ->where('corporate_account_id', $account->id)
            ->with(['course.currentVersion', 'assignment.student', 'assignment.enrollment'])
            ->orderBy('expires_at')
            ->get();

        $members = CorporateMember::query()
            ->where('corporate_account_id', $account->id)
            ->with('user')
            ->get()
            ->sortBy(fn (CorporateMember $member) => mb_strtolower("{$member->user->first_name} {$member->user->last_name}"))
            ->values();

        $assignedTo = $licenses->where('status', License::STATUS_ASSIGNED)->countBy(fn (License $license) => $license->assignment?->student_id);

        return [
            'company' => ['id' => $company->id, 'name' => $company->name, 'tax_id' => $company->tax_id],
            'courses' => $licenses->groupBy('course_id')->map(fn (Collection $pool) => $this->pool($pool))->sortBy('course.title')->values(),
            'members' => $members->map(fn (CorporateMember $member) => [
                'id' => $member->user->id,
                'name' => trim("{$member->user->first_name} {$member->user->last_name}"),
                'email' => $member->user->email,
                'is_manager' => $member->is_manager,
                'active' => $member->user->isActive(),
                'licenses' => (int) ($assignedTo[$member->user_id] ?? 0),
            ]),
            'orders' => Order::query()
                ->where('company_id', $company->id)
                ->with('currency')
                ->orderByDesc('number')
                ->get()
                ->map(fn (Order $order) => [
                    'id' => $order->id,
                    'number' => $order->number,
                    'status' => $order->state(),
                    'currency' => $order->currency->code,
                    'total_amount' => $order->total_amount,
                    'created_at' => $order->created_at->toIso8601String(),
                    'paid_at' => $order->paid_at?->toIso8601String(),
                    'licenses_released_at' => $order->licenses_released_at?->toIso8601String(),
                ]),
            'pending_quotations' => Quotation::query()->where('company_id', $company->id)->inState(Quotation::STATUS_SENT)->count(),
        ];
    }

    /**
     * Licencias de un curso: cuántas hay, cuántas quedan y quién usa cada una.
     *
     * @param  Collection<int, License>  $pool
     * @return array<string, mixed>
     */
    private function pool(Collection $pool): array
    {
        $course = $pool->first()->course;
        $assigned = $pool->where('status', License::STATUS_ASSIGNED)->filter(fn (License $license) => $license->assignment !== null)->values();
        $available = $pool->filter(fn (License $license) => $license->state() === License::STATUS_AVAILABLE);

        $enrollments = $assigned->map(fn (License $license) => $license->assignment->enrollment)->filter()->values();
        $progress = $course->currentVersion ? $this->progress->summaries($course->currentVersion, $enrollments) : [];
        $certificates = Certificate::query()
            ->whereIn('enrollment_id', $enrollments->pluck('id'))
            ->where('status', Certificate::STATUS_ISSUED)
            ->pluck('verification_code', 'enrollment_id');

        return [
            'course' => ['id' => $course->id, 'title' => $course->title, 'access_days' => $course->access_days],
            'total' => $pool->where('status', '!=', License::STATUS_CANCELLED)->count(),
            'assigned' => $assigned->count(),
            'available' => $available->count(),
            'expired' => $pool->filter(fn (License $license) => $license->state() === License::STATE_EXPIRED)->count(),
            // Fecha límite más próxima para asignar lo que queda
            'assign_before' => $available->min('expires_at')?->toIso8601String(),
            'assignments' => $assigned
                ->sortBy(fn (License $license) => mb_strtolower((string) $license->assignment->student?->first_name))
                ->values()
                ->map(function (License $license) use ($progress, $certificates) {
                    $assignment = $license->assignment;
                    $enrollment = $assignment->enrollment;
                    $summary = $enrollment ? ($progress[$enrollment->id] ?? null) : null;

                    return [
                        'id' => $assignment->id,
                        'license_id' => $license->id,
                        'student' => [
                            'id' => $assignment->student_id,
                            'name' => trim("{$assignment->student?->first_name} {$assignment->student?->last_name}"),
                            'email' => $assignment->student?->email,
                        ],
                        'assigned_at' => $assignment->assigned_at->toIso8601String(),
                        // Solo el avance general: las notas de cada actividad no se muestran a la empresa
                        'progress' => $summary['percent'] ?? 0,
                        'completed_at' => $enrollment?->completed_at?->toIso8601String(),
                        'access_until' => $enrollment?->ends_at?->toIso8601String(),
                        'enrollment_status' => $enrollment instanceof Enrollment ? $enrollment->state() : null,
                        'certificate_code' => $enrollment ? ($certificates[$enrollment->id] ?? null) : null,
                        // Se puede retirar mientras el trabajador no haya empezado
                        'can_revoke' => ($summary['completed'] ?? 0) === 0 && $enrollment?->completed_at === null,
                    ];
                }),
        ];
    }
}
