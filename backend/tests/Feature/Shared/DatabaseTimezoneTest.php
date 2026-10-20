<?php

namespace Tests\Feature\Shared;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Laravel guarda las fechas sin zona horaria: la sesión de PostgreSQL debe
 * leerlas en UTC aunque el servidor de base de datos tenga otra zona. En el
 * VPS (Europe/Berlin) todas las fechas quedaban 2 horas corridas y los
 * cuestionarios con tiempo vencían al empezar.
 */
class DatabaseTimezoneTest extends TestCase
{
    public function test_the_database_session_works_in_utc_even_if_the_server_does_not(): void
    {
        // Se simula un servidor en otra zona: la del usuario de la base, para las conexiones nuevas
        DB::statement("alter role current_user set timezone to 'Europe/Berlin'");

        try {
            DB::purge();
            DB::reconnect();

            $this->assertSame('UTC', DB::selectOne('show timezone')->TimeZone);
            $stored = DB::selectOne("select to_char(?::timestamptz at time zone 'UTC', 'YYYY-MM-DD HH24:MI') as at", ['2026-10-20 05:41:00'])->at;
            $this->assertSame('2026-10-20 05:41', $stored);
        } finally {
            DB::statement('alter role current_user reset timezone');
            DB::purge();
            DB::reconnect();
        }
    }
}
