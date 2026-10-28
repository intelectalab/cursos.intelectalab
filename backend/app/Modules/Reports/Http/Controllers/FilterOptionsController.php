<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Crm\Domain\Models\Company;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\Group;
use App\Modules\Identity\Domain\Models\Role;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\Package;
use App\Shared\Http\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Opciones de los filtros de los listados del personal (cursos, grupos,
 * empresas, roles, docentes y vendedores). Solo nombres: lo que cada
 * pantalla muestra sigue protegido por el permiso de su módulo.
 */
class FilterOptionsController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $can = fn (string ...$permissions) => collect($permissions)->contains(fn (string $permission) => $user->hasPermission($permission));
        $people = fn (Builder $query) => $query->orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'last_name'])
            ->map(fn (User $person) => ['id' => $person->id, 'name' => trim("{$person->first_name} {$person->last_name}")])->values();

        return ApiResponse::success([
            'courses' => Course::query()->orderBy('title')->get(['id', 'title'])->map(fn (Course $course) => ['id' => $course->id, 'title' => $course->title])->values(),
            'packages' => $can('orders.read', 'packages.read') ? Package::query()->orderBy('title')->get(['id', 'title'])->map(fn (Package $package) => ['id' => $package->id, 'title' => $package->title])->values() : [],
            'groups' => $can('students.read', 'users.read', 'enrollments.read') ? Group::query()->orderBy('name')->get(['id', 'name'])->map(fn (Group $group) => ['id' => $group->id, 'name' => $group->name])->values() : [],
            'companies' => $can('students.read', 'crm.read') ? Company::query()->orderBy('name')->get(['id', 'name'])->map(fn (Company $company) => ['id' => $company->id, 'name' => $company->name])->values() : [],
            'roles' => $can('users.read') ? Role::query()->orderBy('name')->get(['id', 'name'])->map(fn (Role $role) => ['id' => $role->id, 'name' => $role->name])->values() : [],
            'teachers' => $can('courses.read', 'teachers.read') ? $people(User::query()->whereHas('roles', fn (Builder $roles) => $roles->where('code', RoleCatalog::TEACHER))) : [],
            // Quienes registraron alguna venta asistida
            'sellers' => $can('orders.read') ? $people(User::query()->whereIn('id', Order::query()->select('sold_by')->whereNotNull('sold_by'))) : [],
        ]);
    }
}
