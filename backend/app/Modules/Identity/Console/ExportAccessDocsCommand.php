<?php

namespace App\Modules\Identity\Console;

use App\Modules\Identity\Domain\Catalog\PermissionCatalog;
use App\Modules\Identity\Domain\Catalog\RoleCatalog;
use Illuminate\Console\Command;

/**
 * Genera la matriz de roles y permisos (entregable del Maestro §25) a partir
 * de los catálogos del código, para que el documento nunca quede desfasado.
 */
class ExportAccessDocsCommand extends Command
{
    protected $signature = 'access:docs {--path= : Ruta del archivo .md a generar}';

    protected $description = 'Genera docs/roles-y-permisos.md desde los catálogos de roles y permisos';

    public function handle(): int
    {
        $path = $this->option('path') ?: base_path('../docs/roles-y-permisos.md');

        file_put_contents($path, $this->render());

        $this->info('Documento generado: '.realpath($path));

        return self::SUCCESS;
    }

    private function render(): string
    {
        $roles = RoleCatalog::defaults();
        $modules = PermissionCatalog::modules();
        $total = count(PermissionCatalog::assignable());

        $lines = [
            '# Roles y accesos',
            '',
            '> Documento generado con `php artisan access:docs` a partir de `RoleCatalog` y `PermissionCatalog`.',
            '> No lo edites a mano: cambia los catálogos, ejecuta `php artisan access:sync` y vuelve a generarlo.',
            '',
            '## 1. Niveles de acceso',
            '',
            '| Nivel | Quién | Dónde entra | Alcance |',
            '| --- | --- | --- | --- |',
            '| Plataforma | Administrador de plataforma (IntelectaLab) | Dominio central | Lista, suspende y reactiva academias. No pertenece a ninguna academia y su sesión no sirve dentro de ellas. |',
            '| Academia | Usuarios de cada academia, según su rol | Subdominio de la academia (`{academia}.dominio`) | Solo los datos de su academia. |',
            '| Público | Visitantes sin sesión | Subdominio de la academia | Inicio de sesión. En el dominio central, registro de nuevas academias. |',
            '',
            'El aislamiento entre academias no depende de los permisos: lo aplican el subdominio, el filtro por organización en cada consulta y la seguridad por filas de la base de datos.',
            '',
            '## 2. Roles base',
            '',
            'Cada academia recibe estos roles al crearse. Son fijos: no se editan ni se eliminan.',
            '',
            '| Rol | Código | Descripción | Permisos |',
            '| --- | --- | --- | --- |',
        ];

        foreach ($roles as $code => $role) {
            $count = in_array(PermissionCatalog::ALL, $role['permissions'], true)
                ? 'Acceso total'
                : count($role['permissions']);
            $lines[] = "| {$role['name']} | `{$code}` | {$role['description']} | {$count} |";
        }

        $lines = array_merge($lines, [
            '',
            '### Roles propios',
            '',
            'Además de los roles base, cada academia puede crear sus propios roles y elegir sus permisos por módulo (pantalla **Roles y permisos**). Reglas:',
            '',
            '- Un rol propio solo puede incluir permisos del catálogo de la sección 4; nunca el acceso total.',
            '- Nadie puede otorgar un permiso que su propio usuario no tiene.',
            '- Un rol con usuarios asignados no se puede eliminar.',
            '- La academia siempre conserva al menos un administrador activo.',
            '- Todo cambio de roles y asignaciones queda en la auditoría.',
            '',
            '### Responsable de empresa (B2B)',
            '',
            'Es el contacto externo de una empresa cliente (uno o varios por empresa). Registra a sus trabajadores, les asigna licencias, ve su avance y certificados, responde cotizaciones y ve las compras de su empresa. Todo lo que ve está acotado a su propia empresa: el permiso `company.portal` no sirve sin estar vinculado a una.',
            '',
            '## 3. Detalle por rol',
            '',
        ]);

        $descriptions = PermissionCatalog::all();

        foreach ($roles as $role) {
            $lines[] = "### {$role['name']}";
            $lines[] = '';

            if (in_array(PermissionCatalog::ALL, $role['permissions'], true)) {
                $lines[] = "Acceso total a todos los módulos de su academia ({$total} permisos). No alcanza las operaciones de plataforma.";
            } else {
                foreach ($role['permissions'] as $permission) {
                    $lines[] = "- `{$permission}`: {$descriptions[$permission]}";
                }
            }

            $lines[] = '';
        }

        $lines = array_merge($lines, [
            '## 4. Matriz de permisos por módulo',
            '',
            'Una marca indica que el rol base tiene ese permiso, ya sea directamente o por un comodín del mismo recurso.',
            '',
        ]);

        $header = '| Permiso | Descripción | '.implode(' | ', array_column($roles, 'name')).' |';
        $divider = '| --- | --- | '.implode(' | ', array_fill(0, count($roles), ':---:')).' |';

        foreach ($modules as $module) {
            $lines[] = "### {$module['name']}";
            $lines[] = '';
            $lines[] = $header;
            $lines[] = $divider;

            foreach ($module['permissions'] as $permission => $description) {
                $cells = array_map(
                    fn (array $role) => PermissionCatalog::covers($role['permissions'], $permission) ? '✔' : '',
                    $roles
                );
                $lines[] = "| `{$permission}` | {$description} | ".implode(' | ', $cells).' |';
            }

            $lines[] = '';
        }

        return implode("\n", array_merge($lines, [
            '## 5. Cómo leer un permiso',
            '',
            'Los permisos siguen la forma `recurso.acción`. Algunos llevan un alcance que limita los datos:',
            '',
            '| Forma | Significado |',
            '| --- | --- |',
            '| `recurso.acción` | La acción sobre todos los registros de la academia. |',
            '| `recurso.*` | Todas las acciones sobre ese recurso. |',
            '| `recurso.self…` | Solo los registros propios del usuario. |',
            '| `recurso.assigned…` | Solo los cursos asignados al profesor. |',
            '| `recurso.support.read` | Solo lectura, para atención de soporte. |',
            '',
            'El catálogo crece con cada módulo: un permiso se agrega cuando existe la función que lo exige.',
            '',
            '## 6. Accesos de prueba (solo entorno local)',
            '',
            'Los crea `php artisan db:seed`. La contraseña de los tres está definida en `backend/database/seeders/DemoTenantSeeder.php` (variable `DEMO_ADMIN_PASSWORD`). No existen fuera del entorno local.',
            '',
            '| Acceso | Dirección | Correo | Rol |',
            '| --- | --- | --- | --- |',
            '| Academia demo | `http://demo.localhost:5173` | `admin@plataforma.com` | Administrador |',
            '| Academia demo | `http://demo.localhost:5173` | `alumno@plataforma.com` | Alumno |',
            '| Consola de plataforma | `http://localhost:5173/platform` | `plataforma@intelectalab.test` | Administrador de plataforma |',
            '',
            'En otros ambientes el administrador de plataforma se crea con `php artisan platform:create-admin`.',
            '',
        ]));
    }
}
