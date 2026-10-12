<?php

namespace App\Modules\Tenancy\Console;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Borra de una academia de pruebas lo que dejan los recorridos automáticos:
 * cursos, personas, empresas, cupones y bancos de preguntas marcados «E2E»,
 * con todo lo que cuelga de ellos (órdenes, matrículas, certificados…).
 *
 * La auditoría no se toca: es de solo inserción. Una persona que figura en
 * ella (porque inició sesión) no se puede borrar: se le borra todo lo demás
 * y su cuenta queda desactivada.
 *
 * Sin --force solo cuenta lo que borraría. Nunca corre en producción.
 */
class CleanE2eDataCommand extends Command
{
    /** Qué se considera dato de un recorrido automático: tabla, columna y patrón */
    private const ROOTS = [
        ['courses', 'title', 'E2E %'],
        ['users', 'email', 'e2e.%@prueba.test'],
        ['companies', 'name', 'E2E %'],
        ['promotions', 'name', 'E2E %'],
        ['question_banks', 'name', 'E2E %'],
    ];

    protected $signature = 'demo:clean-e2e {tenant=demo : Subdominio de la academia de pruebas} {--force : Borrar de verdad; sin esto solo se cuenta}';

    protected $description = 'Borra de una academia de pruebas los datos que dejan los recorridos automáticos (marcados «E2E»)';

    /** @var array<string, int> filas borradas por tabla */
    private array $deleted = [];

    /** @var array<string, true> filas ya tratadas, para no dar vueltas */
    private array $seen = [];

    /** Cuentas que la auditoría obliga a conservar: quedan desactivadas */
    private int $kept = 0;

    public function handle(TenantContext $context): int
    {
        if (app()->isProduction()) {
            $this->error('Este comando no corre en producción.');

            return self::FAILURE;
        }

        $tenant = Tenant::query()->where('slug', $this->argument('tenant'))->first();

        if ($tenant === null) {
            $this->error('No existe una academia con ese subdominio.');

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        // La misma instancia puede ejecutarse más de una vez (primero para contar, después para borrar)
        [$this->deleted, $this->seen, $this->kept] = [[], [], 0];

        $context->runForTenant($tenant->id, function () use ($force) {
            DB::beginTransaction();

            try {
                foreach (self::ROOTS as [$table, $column, $pattern]) {
                    $this->purge($table, DB::table($table)->where($column, 'like', $pattern)->pluck('id')->all());
                }

                $force ? DB::commit() : DB::rollBack();
            } catch (\Throwable $error) {
                DB::rollBack();

                throw $error;
            }
        });

        ksort($this->deleted);
        $this->table(['Tabla', $force ? 'Filas borradas' : 'Filas que se borrarían'], array_map(fn (string $table, int $rows) => [$table, $rows], array_keys($this->deleted), $this->deleted));
        $total = array_sum($this->deleted);

        $force
            ? $this->info("Listo: {$total} filas borradas de «{$tenant->name}».".($this->kept > 0 ? " {$this->kept} cuentas figuran en la auditoría: se conservan, desactivadas." : ''))
            : $this->warn("No se borró nada. Para borrar estas {$total} filas de «{$tenant->name}», repite el comando con --force.");

        return self::SUCCESS;
    }

    /**
     * Borra esas filas y, antes, las de otras tablas que impedirían borrarlas.
     * Lo que la base ya borra o desliga sola (en cascada) no hace falta tocarlo.
     *
     * @param  list<string>  $ids
     */
    private function purge(string $table, array $ids): void
    {
        $ids = array_values(array_filter($ids, fn (string $id) => ! isset($this->seen["{$table}:{$id}"])));

        if ($ids === []) {
            return;
        }

        foreach ($ids as $id) {
            $this->seen["{$table}:{$id}"] = true;
        }

        foreach ($this->blockers($table) as $blocker) {
            // La auditoría es de solo inserción: no se borra
            if ($blocker->child === 'audit_events') {
                continue;
            }

            foreach (array_chunk($ids, 500) as $chunk) {
                if ($blocker->has_id) {
                    $this->purge($blocker->child, DB::table($blocker->child)->whereIn($blocker->column, $chunk)->pluck('id')->all());
                } else {
                    $this->count($blocker->child, DB::table($blocker->child)->whereIn($blocker->column, $chunk)->delete());
                }
            }
        }

        foreach (array_chunk($ids, 500) as $chunk) {
            $audited = $this->audited($table, $chunk);

            if ($audited !== [] && $table === 'users') {
                $this->kept += DB::table('users')->whereIn('id', $audited)->update(['status' => User::STATUS_INACTIVE]);
            }

            $this->count($table, DB::table($table)->whereIn('id', array_diff($chunk, $audited))->delete());
        }
    }

    /**
     * De esas filas, las que la auditoría referencia: no se pueden borrar.
     *
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function audited(string $table, array $ids): array
    {
        $kept = [];

        foreach ($this->blockers($table) as $reference) {
            if ($reference->child === 'audit_events') {
                $kept = [...$kept, ...DB::table('audit_events')->whereIn($reference->column, $ids)->distinct()->pluck($reference->column)->all()];
            }
        }

        return array_values(array_unique($kept));
    }

    /**
     * Tablas con una referencia a esta que no se resuelve sola al borrar.
     *
     * @return list<object{child: string, column: string, has_id: bool}>
     */
    private function blockers(string $table): array
    {
        return DB::select(<<<'SQL'
            select child.relname as child, col.attname as "column",
                   exists (select 1 from pg_attribute id where id.attrelid = child.oid and id.attname = 'id' and not id.attisdropped) as has_id
            from pg_constraint fk
            join pg_class child on child.oid = fk.conrelid
            join pg_class parent on parent.oid = fk.confrelid
            join pg_namespace schema on schema.oid = parent.relnamespace
            join pg_attribute col on col.attrelid = fk.conrelid and col.attnum = fk.conkey[1]
            where fk.contype = 'f' and schema.nspname = current_schema() and parent.relname = ? and fk.confdeltype in ('a', 'r')
            order by child.relname, col.attname
            SQL, [$table]);
    }

    private function count(string $table, int $rows): void
    {
        if ($rows > 0) {
            $this->deleted[$table] = ($this->deleted[$table] ?? 0) + $rows;
        }
    }
}
