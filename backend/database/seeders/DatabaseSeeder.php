<?php

namespace Database\Seeders;

use App\Modules\Identity\Application\SyncAccessCatalog;
use App\Modules\Platform\Domain\Models\PlatformAdmin;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(SyncAccessCatalog $sync): void
    {
        $sync->handle();

        if (app()->environment('local')) {
            $this->call(DemoTenantSeeder::class);

            // Administrador de plataforma para desarrollo local (dominio central).
            // En otros ambientes: php artisan platform:create-admin
            PlatformAdmin::query()->firstOrCreate(
                ['email' => 'plataforma@intelectalab.test'],
                ['name' => 'Administrador de plataforma', 'password' => env('DEMO_ADMIN_PASSWORD', 'Admin123*#')]
            );
        }
    }
}
