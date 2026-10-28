<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Domain\Models\CourseTeacher;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Reports\Application\TeacherDirectory;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\StateCounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Docentes de la academia y la ficha de cada uno. Solo lectura: las altas
 * siguen en Usuarios y la asignación a cursos, en cada curso.
 */
class TeacherController extends Controller
{
    public function __construct(private readonly TeacherDirectory $teachers) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'filter' => ['nullable', Rule::in(TeacherDirectory::FILTERS)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'course_id' => ['nullable', 'uuid'],
        ]);

        // Con course_id: solo quienes dictan ese curso
        $base = $this->teachers->query($filters['search'] ?? null)
            ->when($filters['course_id'] ?? null, fn ($query, string $course) => $query->whereIn('users.id', CourseTeacher::query()->select('user_id')->where('course_id', $course)));

        // Cuántos hay en cada situación con la búsqueda actual: alimenta las tarjetas que filtran
        $counts = StateCounts::of($base, TeacherDirectory::FILTERS, fn ($query, string $filter) => $this->teachers->filter($query, $filter));

        if ($filters['filter'] ?? null) {
            $this->teachers->filter($base, $filters['filter']);
        }

        $teachers = $base
            ->orderBy('users.last_name')
            ->orderBy('users.first_name')
            ->paginate($filters['per_page'] ?? 25);

        return ApiResponse::paginated($teachers, fn (User $teacher) => $this->teachers->row($teacher), ['counts' => $counts]);
    }

    public function show(string $teacher): JsonResponse
    {
        // Quien no es docente no tiene ficha aquí
        $record = $this->teachers->query()->where('users.id', $teacher)->firstOrFail();

        return ApiResponse::success($this->teachers->profile($record), ['recent_days' => TeacherDirectory::RECENT_DAYS]);
    }
}
