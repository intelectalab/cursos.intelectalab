<?php

namespace Database\Seeders;

use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use App\Modules\Identity\Domain\Models\Role;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\ProvisionTenant;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Organización de demostración para desarrollo local: http://demo.localhost
 */
class DemoTenantSeeder extends Seeder
{
    public function run(ProvisionTenant $provision, TenantContext $context): void
    {
        $password = env('DEMO_ADMIN_PASSWORD', 'Admin123*#');

        $tenant = Tenant::query()->where('slug', 'demo')->first() ?? $provision->handle(
            [
                'name' => 'Organización Demo',
                'slug' => 'demo',
                'legal_name' => 'Organización Demo S.A.C.',
                'tax_id' => '20123456789',
            ],
            [
                'first_name' => 'Administrador',
                'last_name' => 'Principal',
                'email' => 'admin@plataforma.com',
                'password' => $password,
            ]
        );

        // Usuario de prueba sin privilegios administrativos (misma clave que el admin demo)
        $context->runForTenant($tenant->id, function () use ($password) {
            $student = User::query()->firstOrCreate(
                ['email' => 'alumno@plataforma.com'],
                [
                    'password' => $password,
                    'first_name' => 'Alumno',
                    'last_name' => 'De Prueba',
                    'status' => User::STATUS_ACTIVE,
                ]
            );

            $student->roles()->syncWithoutDetaching(
                Role::query()->where('code', RoleCatalog::STUDENT)->value('id')
            );

            // Una cuenta por cada rol del personal que no es el administrador, para probar lo que ve cada uno
            foreach ([
                ['soporte@plataforma.com', 'Soporte', RoleCatalog::SUPPORT],
                ['ventas@plataforma.com', 'Ventas', RoleCatalog::SALES],
            ] as [$email, $name, $role]) {
                $staff = User::query()->firstOrCreate(
                    ['email' => $email],
                    ['password' => $password, 'first_name' => $name, 'last_name' => 'De Prueba', 'status' => User::STATUS_ACTIVE]
                );

                $staff->roles()->syncWithoutDetaching(Role::query()->where('code', $role)->value('id'));
            }
        });
    }
}
