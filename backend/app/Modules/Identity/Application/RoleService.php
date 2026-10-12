<?php

namespace App\Modules\Identity\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Catalog\PermissionCatalog;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\Permission;
use App\Modules\Identity\Domain\Models\Role;
use App\Modules\Identity\Domain\Models\User;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Roles propios de cada organización, armados desde el catálogo global.
 * Los roles de sistema no se editan ni eliminan. Nadie puede otorgar un
 * permiso que no tiene (sin escalamiento de privilegios).
 */
class RoleService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array{name: string, description?: ?string, permissions: list<string>}  $data
     */
    public function create(User $actor, array $data): Role
    {
        $this->assertGrantable($actor, $data['permissions']);

        return DB::transaction(function () use ($data) {
            $role = Role::create([
                'code' => 'CUSTOM_'.Str::upper(Str::random(10)),
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'is_system' => false,
            ]);
            $this->syncPermissions($role, $data['permissions']);

            $this->audit->record('roles.created', $role, null, $this->snapshot($role));

            return $role;
        });
    }

    /**
     * @param  array{name: string, description?: ?string, permissions: list<string>}  $data
     */
    public function update(User $actor, Role $role, array $data): Role
    {
        $this->assertNotSystem($role);
        $this->assertGrantable($actor, $data['permissions']);

        return DB::transaction(function () use ($role, $data) {
            $before = $this->snapshot($role);

            $role->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
            ]);
            $this->syncPermissions($role, $data['permissions']);

            $this->audit->record('roles.updated', $role, $before, $this->snapshot($role));

            return $role;
        });
    }

    public function delete(Role $role): void
    {
        $this->assertNotSystem($role);

        if ($role->users()->exists()) {
            throw new ApiException(
                ErrorCode::RoleInUse,
                'El rol tiene usuarios asignados. Reasígnalos antes de eliminarlo.',
                409
            );
        }

        DB::transaction(function () use ($role) {
            $this->audit->record('roles.deleted', $role, $this->snapshot($role), null);
            $role->delete();
        });
    }

    /**
     * @param  list<string>  $roleIds
     */
    public function assignToUser(User $actor, User $target, array $roleIds): User
    {
        $roles = Role::query()->with('permissions')->whereIn('id', $roleIds)->get();

        $this->assertGrantable($actor, $roles->flatMap(fn (Role $role) => $role->permissions->pluck('code'))->all());
        if (! $roles->contains('code', RoleCatalog::ADMIN)) {
            $this->assertAdministratorRemainsWithout($target);
        }

        return DB::transaction(function () use ($target, $roles) {
            $before = $target->roles()->pluck('name')->all();

            $target->roles()->sync($roles->pluck('id')->all());
            $target->unsetRelation('roles');

            $this->audit->record('users.roles_updated', $target, ['roles' => $before], [
                'roles' => $roles->pluck('name')->all(),
            ]);

            return $target;
        });
    }

    /**
     * Suma un rol base (Alumno, Docente) a quien no lo tiene, sin tocar sus
     * otros roles. Lo usa el sistema cuando alguien recibe un papel en un
     * curso; no pasa por la regla de no otorgar más de lo que uno tiene porque
     * el rol es fijo y de alcance acotado.
     */
    public function grantBaseRole(User $target, string $code): void
    {
        $role = Role::query()->where('code', $code)->where('is_system', true)->firstOrFail();

        if ($target->roles()->whereKey($role->id)->exists()) {
            return;
        }

        $before = $target->roles()->pluck('name')->all();
        $target->roles()->attach($role->id);
        $target->unsetRelation('roles');

        $this->audit->record('users.roles_updated', $target, ['roles' => $before], ['roles' => [...$before, $role->name]]);
    }

    /**
     * @return array{name: string, description: ?string, permissions: list<string>}
     */
    private function snapshot(Role $role): array
    {
        return [
            'name' => $role->name,
            'description' => $role->description,
            'permissions' => $role->permissions()->orderBy('code')->pluck('code')->all(),
        ];
    }

    /**
     * @param  list<string>  $codes
     */
    private function syncPermissions(Role $role, array $codes): void
    {
        $role->permissions()->sync(Permission::query()->whereIn('code', $codes)->pluck('id')->all());
        $role->unsetRelation('permissions');
    }

    private function assertNotSystem(Role $role): void
    {
        if ($role->is_system) {
            throw new ApiException(
                ErrorCode::RoleIsSystem,
                'Los roles base de la plataforma no se pueden modificar ni eliminar.',
                409
            );
        }
    }

    /**
     * @param  list<string>  $codes
     */
    private function assertGrantable(User $actor, array $codes): void
    {
        $own = $actor->permissionCodes();
        $denied = collect($codes)->unique()->reject(fn (string $code) => PermissionCatalog::covers($own, $code))->values();

        if ($denied->isNotEmpty()) {
            throw new ApiException(
                ErrorCode::PrivilegeEscalation,
                'No puedes otorgar permisos que tu propio usuario no tiene.',
                403,
                ['permissions' => $denied->all()]
            );
        }
    }

    /**
     * Impide dejar a la organización sin administrador activo cuando el usuario
     * indicado deja de serlo (pierde el rol o se desactiva).
     */
    public function assertAdministratorRemainsWithout(User $target): void
    {
        $isAdministrator = fn ($query) => $query->where('code', RoleCatalog::ADMIN);

        if (! $target->roles()->where($isAdministrator)->exists()) {
            return;
        }

        $anotherAdministrator = User::query()
            ->whereKeyNot($target->id)
            ->where('status', User::STATUS_ACTIVE)
            ->whereHas('roles', $isAdministrator)
            ->exists();

        if (! $anotherAdministrator) {
            throw new ApiException(
                ErrorCode::LastAdministrator,
                'La organización debe conservar al menos un administrador activo.',
                409
            );
        }
    }
}
