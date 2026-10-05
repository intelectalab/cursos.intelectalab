<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Catalog\PermissionCatalog;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\Permission;
use App\Modules\Identity\Domain\Models\Role;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Lleva a la base los cambios del catálogo de permisos y actualiza los roles
 * de sistema de todas las organizaciones. Los roles propios no se tocan.
 */
class SyncAccessCatalog
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @return int cantidad de roles de sistema actualizados
     */
    public function handle(): int
    {
        return $this->context->runAsSystem(fn () => DB::transaction(function () {
            PermissionCatalog::sync();

            $permissionIds = Permission::query()->pluck('id', 'code');
            $defaults = RoleCatalog::defaults();
            $updated = 0;

            Role::query()->where('is_system', true)->whereIn('code', array_keys($defaults))->each(
                function (Role $role) use ($defaults, $permissionIds, &$updated) {
                    $changes = $role->permissions()->sync(
                        collect($defaults[$role->code]['permissions'])->map(fn (string $code) => $permissionIds[$code])->all()
                    );

                    if ($changes['attached'] || $changes['detached']) {
                        $updated++;
                    }
                }
            );

            return $updated;
        }));
    }
}
