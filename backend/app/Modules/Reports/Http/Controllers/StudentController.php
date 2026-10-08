<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Reports\Application\StudentDirectory;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\StateCounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Alumnos de la academia y la ficha de cada uno. Solo lectura: las altas y
 * los cambios siguen en Usuarios y en Matrículas.
 */
class StudentController extends Controller
{
    public function __construct(private readonly StudentDirectory $students) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'filter' => ['nullable', Rule::in(StudentDirectory::FILTERS)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $base = $this->students->query($filters['search'] ?? null);

        // Cuántos hay en cada situación con la búsqueda actual: alimenta las tarjetas que filtran
        $counts = StateCounts::of($base, StudentDirectory::FILTERS, fn ($query, string $filter) => $this->students->filter($query, $filter));

        if ($filters['filter'] ?? null) {
            $this->students->filter($base, $filters['filter']);
        }

        $students = $base
            ->orderBy('users.last_name')
            ->orderBy('users.first_name')
            ->paginate($filters['per_page'] ?? 25);

        return ApiResponse::paginated($students, fn (User $student) => $this->students->row($student), ['counts' => $counts]);
    }

    public function show(string $student, TenantSettingsService $settings): JsonResponse
    {
        // Quien no es alumno no tiene ficha aquí
        $record = $this->students->query()->where('users.id', $student)->firstOrFail();

        return ApiResponse::success($this->students->profile($record), ['grade_scale' => $settings->gradeScale()]);
    }
}
