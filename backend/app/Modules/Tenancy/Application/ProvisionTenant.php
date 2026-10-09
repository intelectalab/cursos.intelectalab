<?php

namespace App\Modules\Tenancy\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Catalog\PermissionCatalog;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\Permission;
use App\Modules\Identity\Domain\Models\Role;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Events\TenantProvisioned;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Alta de una organización: tenant, roles base con sus permisos y usuario
 * administrador inicial, en una sola transacción.
 */
class ProvisionTenant
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{name: string, slug: string, legal_name?: ?string, tax_id?: ?string}  $tenantData
     * @param  array{first_name: string, last_name: string, email: string, password: string}  $adminData
     */
    public function handle(array $tenantData, array $adminData, bool $mustChangePassword = false): Tenant
    {
        return DB::transaction(function () use ($tenantData, $adminData, $mustChangePassword) {
            PermissionCatalog::sync();

            $tenant = Tenant::create([
                'name' => $tenantData['name'],
                'slug' => strtolower($tenantData['slug']),
                'legal_name' => $tenantData['legal_name'] ?? null,
                'tax_id' => $tenantData['tax_id'] ?? null,
                'status' => Tenant::STATUS_ACTIVE,
            ]);

            $this->context->runForTenant($tenant->id, function () use ($tenant, $adminData, $mustChangePassword) {
                $permissionIds = Permission::query()->pluck('id', 'code');
                $adminRole = null;

                foreach (RoleCatalog::defaults() as $code => $definition) {
                    $role = Role::create([
                        'code' => $code,
                        'name' => $definition['name'],
                        'description' => $definition['description'],
                        'is_system' => true,
                    ]);

                    $role->permissions()->attach(
                        collect($definition['permissions'])->map(fn (string $p) => $permissionIds[$p])->all()
                    );

                    if ($code === RoleCatalog::ADMIN) {
                        $adminRole = $role;
                    }
                }

                $admin = User::create([
                    'email' => strtolower($adminData['email']),
                    'password' => $adminData['password'],
                    'first_name' => $adminData['first_name'],
                    'last_name' => $adminData['last_name'],
                    'status' => User::STATUS_ACTIVE,
                    // Si otra persona le puso la contraseña (alta desde la consola), debe cambiarla al entrar
                    'must_change_password' => $mustChangePassword,
                ]);
                $admin->roles()->attach($adminRole->id);

                $this->audit->record('tenants.provisioned', $tenant, null, [
                    'name' => $tenant->name,
                    'slug' => $tenant->slug,
                    'admin_user_id' => $admin->id,
                ], $admin->id);

                // Otros módulos crean aquí lo suyo para la organización nueva (p. ej. su prueba gratuita)
                TenantProvisioned::dispatch($tenant);
            });

            return $tenant;
        });
    }
}
