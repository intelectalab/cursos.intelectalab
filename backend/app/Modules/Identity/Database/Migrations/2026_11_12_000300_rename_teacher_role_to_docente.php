<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El rol base de quien dicta cursos pasa a llamarse «Docente», como la
 * pantalla «Docentes». Solo cambia el nombre que se muestra: el código
 * (TEACHER) y sus permisos siguen igual. Si una organización ya tiene un
 * rol propio llamado «Docente», el suyo se respeta y el base no se toca.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rename('Profesor', 'Docente');
    }

    public function down(): void
    {
        $this->rename('Docente', 'Profesor');
    }

    private function rename(string $from, string $to): void
    {
        DB::table('roles')
            ->where('code', 'TEACHER')
            ->where('is_system', true)
            ->where('name', $from)
            ->whereNotIn('tenant_id', DB::table('roles')->where('name', $to)->select('tenant_id'))
            ->update(['name' => $to]);
    }
};
