<?php

namespace App\Shared\Tenancy;

use Illuminate\Support\Facades\DB;

/**
 * Row Level Security por tenant: segunda barrera del aislamiento, por debajo
 * del scope de Eloquent. Se usa desde las migraciones de tablas con tenant_id.
 */
class Rls
{
    public static function enable(string $table): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        // FORCE: la política también aplica al rol dueño de la tabla.
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement(<<<SQL
            CREATE POLICY tenant_isolation ON {$table}
            USING (
                current_setting('app.bypass_rls', true) = 'on'
                OR tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::uuid
            )
        SQL);
    }
}
