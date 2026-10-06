<?php

namespace App\Modules\Identity\Application;

use App\Modules\Identity\Domain\Catalog\PermissionCatalog;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\Permission;
use App\Modules\Identity\Domain\Models\Role;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Lleva a la base los cambios del catálogo de permisos y actualiza los roles
 * de sistema de todas las organizaciones, creando los que el catálogo haya
 * incorporado. Los roles propios no se tocan.
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

            // Un rol base nuevo en el catálogo se crea en las organizaciones que ya existían
            Tenant::query()->pluck('id')->each(function (string $tenantId) use ($defaults) {
                $taken = Role::query()->where('tenant_id', $tenantId)->get(['code', 'name']);

                foreach ($defaults as $code => $definition) {
                    // Si la organización ya usó ese código o nombre en un rol propio, se respeta el suyo
                    if ($taken->contains('code', $code) || $taken->contains('name', $definition['name'])) {
                        continue;
                    }

                    Role::create([
                        'tenant_id' => $tenantId,
                        'code' => $code,
                        'name' => $defaults[$code]['name'],
                        'description' => $defaults[$code]['description'],
                        'is_system' => true,
                    ]);
                }
            });

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
