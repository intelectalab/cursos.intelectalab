<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class InitialSetupSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Crear el Tenant inicial
        $tenant = Tenant::firstOrCreate(
            ['name' => 'Organización Demo'],
            [
                'legal_name' => 'Organización Demo S.A.C.',
                'tax_id' => '20123456789',
                'status' => 'ACTIVE',
            ]
        );

        // Fijar el contexto de tenant para el seeder
        app()->instance('current_tenant_id', $tenant->tenant_id);

        // 2. Crear los 4 Roles Base
        $rolesData = [
            'Administrador' => 'Acceso integral a la administración de la plataforma',
            'Profesor'      => 'Gestión académica de cursos asignados',
            'Alumno'        => 'Consumo de cursos, actividades y certificados',
            'Soporte'       => 'Atención y seguimiento de tickets',
        ];

        $roles = [];
        foreach ($rolesData as $roleName => $desc) {
            $roles[$roleName] = Role::firstOrCreate(
                ['tenant_id' => $tenant->tenant_id, 'name' => $roleName],
                ['description' => $desc, 'status' => 'ACTIVE']
            );
        }

        // 3. Asignar todos los permisos al Administrador
        $allPermissions = Permission::all();
        $roles['Administrador']->permissions()->sync($allPermissions->pluck('permission_id'));

        // 4. Crear Usuario Administrador inicial
        $adminEmail = 'admin@plataforma.com';
        $adminUser = User::withoutGlobalScopes()->where('email', $adminEmail)->first();

        if (!$adminUser) {
            $adminUser = User::create([
                'tenant_id'     => $tenant->tenant_id,
                'email'         => $adminEmail,
                'password_hash' => Hash::make('Admin123*#'),
                'first_name'    => 'Administrador',
                'last_name'     => 'Principal',
                'status'        => 'ACTIVE',
            ]);

            $adminUser->roles()->attach($roles['Administrador']->role_id);
        }
    }
}