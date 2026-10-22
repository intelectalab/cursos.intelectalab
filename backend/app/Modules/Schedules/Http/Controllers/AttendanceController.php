<?php

namespace App\Modules\Schedules\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Application\CourseAccess;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Schedules\Application\AttendanceService;
use App\Modules\Schedules\Domain\Models\LiveClassAttendance;
use App\Shared\Http\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Asistencia a las clases en vivo: la toma quien hace el seguimiento del
 * curso (el Administrador o su docente) y el alumno ve la suya.
 */
class AttendanceController extends Controller
{
    public function __construct(
        private readonly AttendanceService $attendance,
        private readonly CourseAccess $access,
    ) {}

    /**
     * Las clases de un día y la lista de alumnos con lo marcado en cada una.
     */
    public function show(Request $request, string $course): JsonResponse
    {
        $record = $this->course($request, $course);
        $date = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']])['date'] ?? $this->attendance->today();

        $marks = LiveClassAttendance::query()
            ->where('course_id', $record->id)
            ->whereDate('session_date', $date)
            ->get(['student_id', 'session_start', 'status'])
            ->groupBy('student_id');

        return ApiResponse::success([
            'course' => ['id' => $record->id, 'title' => $record->title],
            'date' => $date,
            'today' => $this->attendance->today(),
            'sessions' => $this->attendance->sessionsOn($record, $date),
            'students' => $this->attendance->roster($record)->map(fn (User $student) => [
                'id' => $student->id,
                'name' => trim("{$student->first_name} {$student->last_name}"),
                'email' => $student->email,
                'statuses' => ($marks->get($student->id) ?? collect())->pluck('status', 'session_start'),
            ])->values(),
        ]);
    }

    public function update(Request $request, string $course): JsonResponse
    {
        $record = $this->course($request, $course);

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'start' => ['required', 'date_format:H:i'],
            'records' => ['required', 'array', 'min:1', 'max:500'],
            'records.*.student_id' => ['required', 'uuid', 'distinct'],
            'records.*.status' => ['required', Rule::in(LiveClassAttendance::STATUSES)],
        ]);

        $saved = $this->attendance->record($record, $data['date'], $data['start'], $data['records'], $request->user());

        return ApiResponse::success(['saved' => $saved]);
    }

    /**
     * Porcentaje de cada alumno en todas las clases con asistencia tomada.
     */
    public function summary(Request $request, string $course): JsonResponse
    {
        $record = $this->course($request, $course);
        $all = LiveClassAttendance::query()->where('course_id', $record->id)->get(['student_id', 'session_date', 'session_start', 'status']);
        $byStudent = $all->groupBy('student_id');

        return ApiResponse::success([
            'sessions_taken' => $all->map(fn (LiveClassAttendance $mark) => $mark->session_date->toDateString().' '.$mark->session_start)->unique()->count(),
            'students' => $this->attendance->roster($record)->map(fn (User $student) => [
                'id' => $student->id,
                'name' => trim("{$student->first_name} {$student->last_name}"),
                'email' => $student->email,
            ] + AttendanceService::tally($byStudent->get($student->id) ?? []))->values(),
        ]);
    }

    /**
     * La asistencia propia del alumno en un curso suyo.
     */
    public function mine(Request $request, string $course): JsonResponse
    {
        $enrolled = Enrollment::query()->where('course_id', $course)->where('student_id', $request->user()->id)->where('status', Enrollment::STATUS_ACTIVE)->exists();

        if (! $enrolled) {
            throw new ApiException(ErrorCode::NotFound, 'El curso no existe.', 404);
        }

        $records = LiveClassAttendance::query()
            ->where('course_id', $course)
            ->where('student_id', $request->user()->id)
            ->orderByDesc('session_date')
            ->orderByDesc('session_start')
            ->get(['session_date', 'session_start', 'status']);

        return ApiResponse::success([
            'records' => $records->map(fn (LiveClassAttendance $mark) => ['date' => $mark->session_date->toDateString(), 'start' => $mark->session_start, 'status' => $mark->status])->values(),
        ] + AttendanceService::tally($records));
    }

    private function course(Request $request, string $id): Course
    {
        $course = Course::query()->findOrFail($id);
        $this->access->assertCanReview($request->user(), $course);

        return $course;
    }
}
