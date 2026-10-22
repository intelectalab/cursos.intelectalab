<?php

namespace App\Modules\Schedules\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Reports\Application\TeacherDirectory;
use App\Modules\Schedules\Application\ScheduleService;
use App\Modules\Schedules\Domain\Models\TeacherSchedule;
use App\Modules\Schedules\Http\SchedulePresenter;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Horarios de todos los docentes (vista del personal) y el editor de la
 * semana tipo de cada uno.
 */
class ScheduleController extends Controller
{
    /** Docentes sin horario todavía */
    public const FILTER_NONE = 'NONE';

    public const FILTERS = [TeacherSchedule::STATUS_PENDING, TeacherSchedule::STATUS_CONFIRMED, TeacherSchedule::STATUS_OBSERVED, self::FILTER_NONE];

    public function __construct(
        private readonly TeacherDirectory $teachers,
        private readonly ScheduleService $schedules,
        private readonly TenantSettingsService $settings,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'filter' => ['nullable', Rule::in(self::FILTERS)],
        ]);

        $teachers = $this->teachers->query($filters['search'] ?? null)
            ->where('users.status', User::STATUS_ACTIVE)
            ->orderBy('users.last_name')
            ->orderBy('users.first_name')
            ->get();

        $schedules = TeacherSchedule::query()->whereIn('teacher_id', $teachers->pluck('id'))->with('sender')->get()->keyBy('teacher_id');
        $titles = SchedulePresenter::courseTitles($schedules);

        $rows = $teachers->map(function (User $teacher) use ($schedules, $titles) {
            $schedule = $schedules->get($teacher->id);

            return [
                'teacher' => ['id' => $teacher->id, 'name' => SchedulePresenter::name($teacher), 'email' => $teacher->email],
                'courses_count' => (int) $teacher->courses_count,
                'schedule' => SchedulePresenter::schedule($schedule, $titles),
                'hours' => SchedulePresenter::hours($schedule->blocks ?? []),
            ];
        });

        // Cuántos hay en cada situación con la búsqueda actual: alimenta las tarjetas que filtran
        $status = fn (array $row) => $row['schedule']['status'] ?? self::FILTER_NONE;
        $counts = ['ALL' => $rows->count()] + collect(self::FILTERS)->mapWithKeys(fn (string $filter) => [$filter => $rows->filter(fn ($row) => $status($row) === $filter)->count()])->all();

        if ($filters['filter'] ?? null) {
            $rows = $rows->filter(fn ($row) => $status($row) === $filters['filter']);
        }

        return ApiResponse::success($rows->values(), ['counts' => $counts, 'timezone' => $this->settings->timezone()]);
    }

    public function show(string $teacher): JsonResponse
    {
        $record = $this->teacher($teacher);
        $schedule = TeacherSchedule::query()->where('teacher_id', $record->id)->with('sender')->first();

        return ApiResponse::success([
            'teacher' => ['id' => $record->id, 'name' => SchedulePresenter::name($record), 'email' => $record->email],
            'schedule' => SchedulePresenter::schedule($schedule, SchedulePresenter::courseTitles(collect([$schedule])->filter())),
            'courses' => $this->schedules->coursesOf($record)->map(fn (Course $course) => ['id' => $course->id, 'title' => $course->title]),
        ], ['timezone' => $this->settings->timezone()]);
    }

    public function update(Request $request, string $teacher): JsonResponse
    {
        $record = $this->teacher($teacher);

        $data = $request->validate([
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'blocks' => ['required', 'array', 'min:1', 'max:80'],
            'blocks.*.day' => ['required', 'integer', 'between:1,7'],
            'blocks.*.start' => ['required', 'date_format:H:i'],
            'blocks.*.end' => ['required', 'date_format:H:i'],
            'blocks.*.type' => ['required', Rule::in(TeacherSchedule::TYPES)],
            'blocks.*.course_id' => ['nullable', 'uuid'],
            'blocks.*.meeting_url' => ['nullable', 'url:http,https', 'max:500'],
            'blocks.*.note' => ['nullable', 'string', 'max:200'],
        ], [], [
            'valid_from' => 'fecha desde la que vale',
            'blocks' => 'bloques del horario',
            'blocks.*.start' => 'hora de inicio',
            'blocks.*.end' => 'hora de fin',
            'blocks.*.meeting_url' => 'enlace de la clase',
            'blocks.*.note' => 'nota',
        ]);

        $schedule = $this->schedules->send($record, $data['valid_from'], array_values($data['blocks']), $request->user());
        $schedule->load('sender');

        return ApiResponse::success(SchedulePresenter::schedule($schedule, SchedulePresenter::courseTitles(collect([$schedule]))));
    }

    public function exceptions(string $teacher): JsonResponse
    {
        return ApiResponse::success($this->schedules->exceptionsOf($this->teacher($teacher)));
    }

    /**
     * Un día sin clase del docente (permiso, viaje…). Los feriados de la academia ya cuentan solos.
     */
    public function addException(Request $request, string $teacher): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['nullable', 'string', 'max:150'],
        ], [], ['date' => 'fecha', 'reason' => 'motivo']);

        $this->schedules->addException($this->teacher($teacher), $data['date'], $data['reason'] ?? null, $request->user());

        return ApiResponse::success($this->schedules->exceptionsOf($this->teacher($teacher)), null, 201);
    }

    public function removeException(string $teacher, string $exception): JsonResponse
    {
        $record = $this->teacher($teacher);
        $this->schedules->removeException($record, $exception);

        return ApiResponse::success($this->schedules->exceptionsOf($record));
    }

    private function teacher(string $id): User
    {
        // Quien no es docente no tiene horario
        return $this->teachers->query()->where('users.id', $id)->firstOrFail();
    }
}
