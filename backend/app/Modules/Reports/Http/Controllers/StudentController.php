<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Reports\Application\StudentDirectory;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Export\ExcelExport;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\StateCounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

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

    /**
     * Los alumnos del listado, con la misma búsqueda y filtro, en Excel.
     */
    public function export(Request $request, TenantSettingsService $settings): BinaryFileResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'filter' => ['nullable', Rule::in(StudentDirectory::FILTERS)],
        ]);

        $query = $this->students->query($filters['search'] ?? null);

        if ($filters['filter'] ?? null) {
            $this->students->filter($query, $filters['filter']);
        }

        $timezone = $settings->timezone();

        return ExcelExport::download(
            'alumnos',
            $query->orderBy('users.last_name')->orderBy('users.first_name'),
            ['Nombres', 'Apellidos', 'Correo', 'Teléfono', 'Tipo de documento', 'N.º de documento', 'Estado', 'Cursos activos', 'Cursos completados', 'Último ingreso', 'Registrado el'],
            fn (User $student) => [
                $student->first_name,
                $student->last_name,
                $student->email,
                $student->phone,
                $student->doc_number ? $student->doc_type : null,
                $student->doc_number,
                $student->status === User::STATUS_INACTIVE ? 'Inactivo' : 'Activo',
                (int) $student->active_enrollments,
                (int) $student->completed_courses,
                ExcelExport::dateTime($student->last_login_at, $timezone),
                ExcelExport::date($student->created_at, $timezone),
            ]
        );
    }

    public function show(string $student, TenantSettingsService $settings): JsonResponse
    {
        // Quien no es alumno no tiene ficha aquí
        $record = $this->students->query()->where('users.id', $student)->firstOrFail();

        return ApiResponse::success($this->students->profile($record), ['grade_scale' => $settings->gradeScale()]);
    }
}
