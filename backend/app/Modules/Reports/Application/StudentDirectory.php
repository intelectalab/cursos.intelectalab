<?php

namespace App\Modules\Reports\Application;

use App\Modules\Academic\Application\GradebookService;
use App\Modules\B2b\Domain\Models\CorporateMember;
use App\Modules\Certificates\Domain\Models\Certificate;
use App\Modules\Enrollments\Application\ProgressService;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Enrollments\Http\ClassroomPresenter;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\Group;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\Payment;
use App\Modules\Support\Domain\Models\Ticket;
use App\Modules\Tenancy\Application\EmailPreferences;
use Illuminate\Database\Eloquent\Builder;

/**
 * Alumnos de la academia (YC-ALU-001) y su ficha completa, "Alumno 360"
 * (YC-ALU-002): lo que hoy está repartido entre matrículas, ventas,
 * certificados y soporte, reunido en una sola consulta de solo lectura.
 */
class StudentDirectory
{
    public const FILTER_ENROLLED = 'ENROLLED';

    public const FILTER_NOT_ENROLLED = 'NOT_ENROLLED';

    public const FILTER_INACTIVE = 'INACTIVE';

    public const FILTERS = [self::FILTER_ENROLLED, self::FILTER_NOT_ENROLLED, self::FILTER_INACTIVE];

    /** Cuántos registros de cada tipo muestra la ficha, del más reciente al más antiguo */
    private const RECENT = 50;

    public function __construct(
        private readonly ProgressService $progress,
        private readonly GradebookService $gradebook,
        private readonly EmailPreferences $emails,
    ) {}

    /**
     * Es alumno quien tiene el rol Alumno o alguna matrícula, con cuántos
     * cursos lleva y cuántos completó.
     *
     * @return Builder<User>
     */
    public function query(?string $search = null): Builder
    {
        $enrollments = fn () => Enrollment::query()
            ->selectRaw('count(*)')
            ->whereColumn('enrollments.student_id', 'users.id')
            ->where('enrollments.status', Enrollment::STATUS_ACTIVE);

        return User::query()
            ->select('users.*')
            ->selectSub($enrollments(), 'active_enrollments')
            ->selectSub($enrollments()->whereNotNull('enrollments.completed_at'), 'completed_courses')
            ->where(fn (Builder $query) => $query
                ->whereHas('roles', fn (Builder $roles) => $roles->where('code', RoleCatalog::STUDENT))
                ->orWhereIn('users.id', Enrollment::query()->select('student_id')))
            ->when($search, function (Builder $query, string $search) {
                $like = '%'.mb_strtolower($search).'%';

                $query->where(fn (Builder $q) => $q
                    ->whereRaw('lower(users.email) like ?', [$like])
                    ->orWhereRaw('lower(users.first_name) like ?', [$like])
                    ->orWhereRaw('lower(users.last_name) like ?', [$like])
                    ->orWhereRaw('lower(users.doc_number) like ?', [$like]));
            });
    }

    /**
     * @param  Builder<User>  $query
     */
    public function filter(Builder $query, string $filter): void
    {
        $withActive = Enrollment::query()->select('student_id')->where('status', Enrollment::STATUS_ACTIVE);

        match ($filter) {
            self::FILTER_ENROLLED => $query->whereIn('users.id', $withActive),
            self::FILTER_NOT_ENROLLED => $query->whereNotIn('users.id', $withActive),
            self::FILTER_INACTIVE => $query->where('users.status', User::STATUS_INACTIVE),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function row(User $student): array
    {
        return [
            'id' => $student->id,
            'name' => trim("{$student->first_name} {$student->last_name}"),
            'email' => $student->email,
            'phone' => $student->phone,
            'document' => $student->doc_number ? trim("{$student->doc_type} {$student->doc_number}") : null,
            'status' => $student->status,
            'last_login_at' => $student->last_login_at?->toIso8601String(),
            'created_at' => $student->created_at->toIso8601String(),
            'active_enrollments' => (int) $student->active_enrollments,
            'completed_courses' => (int) $student->completed_courses,
        ];
    }

    /**
     * Ficha completa del alumno.
     *
     * @return array<string, mixed>
     */
    public function profile(User $student): array
    {
        $orders = Order::query()
            ->where('customer_id', $student->id)
            ->with(['currency', 'items', 'payments'])
            ->latest()
            ->orderByDesc('id')
            ->limit(self::RECENT)
            ->get();

        return [
            'student' => $this->row($student) + [
                'must_change_password' => (bool) $student->must_change_password,
                'groups' => Group::query()
                    ->whereHas('members', fn (Builder $members) => $members->whereKey($student->id))
                    ->orderBy('name')
                    ->pluck('name')
                    ->all(),
                'company' => CorporateMember::query()
                    ->where('user_id', $student->id)
                    ->with('account.company')
                    ->first()?->account?->company?->name,
                // Tipos de correo de los que se dio de baja
                'email_opt_outs' => $this->emails->optedOutNames($student->email),
            ],
            'enrollments' => $this->enrollments($student),
            'orders' => $orders->map(fn (Order $order) => [
                'id' => $order->id,
                'number' => $order->number,
                'title' => $order->package_title ?? $order->items->first()?->title,
                'state' => $order->state(),
                'total_amount' => $order->total_amount,
                'currency' => $order->currency?->code,
                'created_at' => $order->created_at->toIso8601String(),
                'paid_at' => $order->paid_at?->toIso8601String(),
            ])->values(),
            // Lo pagado, por moneda: una academia puede haber cobrado en más de una
            // Cuenta lo cobrado de verdad: también las cuotas de una compra que aún no termina de pagarse, y no lo reembolsado
            'paid_totals' => $orders
                ->groupBy(fn (Order $order) => (string) $order->currency?->code)
                ->map(fn ($group) => round((float) $group->sum(fn (Order $order) => $order->payments->where('status', Payment::STATUS_CONFIRMED)->sum('amount')), 2))
                ->filter(fn (float $total) => $total > 0)
                ->all(),
            'certificates' => Certificate::query()
                ->where('student_id', $student->id)
                ->latest('issued_at')
                ->limit(self::RECENT)
                ->get()
                ->map(fn (Certificate $certificate) => [
                    'id' => $certificate->id,
                    'course_title' => $certificate->snapshot['course_title'] ?? null,
                    'code' => $certificate->verification_code,
                    'status' => $certificate->status,
                    'issued_at' => $certificate->issued_at->toIso8601String(),
                ])->values(),
            'tickets' => Ticket::query()
                ->where('requester_id', $student->id)
                ->latest()
                ->limit(self::RECENT)
                ->get()
                ->map(fn (Ticket $ticket) => [
                    'id' => $ticket->id,
                    'number' => $ticket->number,
                    'subject' => $ticket->subject,
                    'status' => $ticket->status,
                    'created_at' => $ticket->created_at->toIso8601String(),
                ])->values(),
        ];
    }

    /**
     * Matrículas con su situación de hoy, avance y nota del curso.
     *
     * @return list<array<string, mixed>>
     */
    private function enrollments(User $student): array
    {
        $enrollments = Enrollment::query()
            ->where('student_id', $student->id)
            ->with('course.currentVersion')
            ->latest('enrolled_at')
            // Los ids crecen con el tiempo: desempatan lo creado en el mismo segundo
            ->orderByDesc('id')
            ->get();

        $summaries = $this->progress->summariesAcrossCourses($enrollments->filter(fn (Enrollment $enrollment) => $enrollment->isActive()));

        return $enrollments->map(function (Enrollment $enrollment) use ($summaries) {
            $version = $enrollment->course->currentVersion;
            $graded = $enrollment->isActive() && $version !== null;

            return [
                'id' => $enrollment->id,
                'course_id' => $enrollment->course_id,
                'course_title' => $enrollment->course->title,
                'state' => $enrollment->state(),
                'source' => $enrollment->source,
                'enrolled_at' => $enrollment->enrolled_at->toIso8601String(),
                'ends_at' => $enrollment->ends_at?->toDateString(),
                'completed_at' => $enrollment->completed_at?->toIso8601String(),
                'progress_percent' => $summaries[$enrollment->id]['percent'] ?? null,
                'final_percent' => $graded
                    ? $this->gradebook->rows($enrollment->course_id, ClassroomPresenter::activitiesOf($version), collect([$enrollment]))[$enrollment->id]['final_percent']
                    : null,
            ];
        })->values()->all();
    }
}
