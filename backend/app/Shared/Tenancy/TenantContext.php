<?php

namespace App\Shared\Tenancy;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

/**
 * Contexto de tenant de la ejecución actual. Tiene tres estados:
 *  - tenant: las consultas quedan acotadas a ese tenant;
 *  - sistema: sin acotar (consola, migraciones, aprovisionamiento);
 *  - ninguno: no se ve ningún dato de tenant (deny-by-default).
 *
 * El estado se replica en la sesión de PostgreSQL para que las políticas RLS
 * apliquen el mismo criterio que el scope de Eloquent.
 */
class TenantContext
{
    private ?string $tenantId = null;

    private bool $system = false;

    public function tenantId(): ?string
    {
        return $this->tenantId;
    }

    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }

    public function isSystem(): bool
    {
        return $this->system;
    }

    public function setTenant(string $tenantId): void
    {
        $this->apply($tenantId, false);
    }

    public function setSystem(): void
    {
        $this->apply(null, true);
    }

    public function clear(): void
    {
        $this->apply(null, false);
    }

    public function runAsSystem(callable $callback): mixed
    {
        return $this->runWith(null, true, $callback);
    }

    public function runForTenant(string $tenantId, callable $callback): mixed
    {
        return $this->runWith($tenantId, false, $callback);
    }

    /**
     * @return array{0: ?string, 1: bool}
     */
    public function snapshot(): array
    {
        return [$this->tenantId, $this->system];
    }

    /**
     * @param  array{0: ?string, 1: bool}  $snapshot
     */
    public function restore(array $snapshot): void
    {
        $this->apply($snapshot[0], $snapshot[1]);
    }

    /**
     * Replica el estado en una conexión (se invoca al establecerla).
     */
    public function syncConnection(Connection $connection): void
    {
        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $connection->statement(
            "select set_config('app.tenant_id', ?, false), set_config('app.bypass_rls', ?, false)",
            [$this->tenantId ?? '', $this->system ? 'on' : 'off']
        );
    }

    private function runWith(?string $tenantId, bool $system, callable $callback): mixed
    {
        $previous = $this->snapshot();
        $this->apply($tenantId, $system);

        try {
            return $callback();
        } finally {
            $this->restore($previous);
        }
    }

    private function apply(?string $tenantId, bool $system): void
    {
        $this->tenantId = $tenantId;
        $this->system = $system;

        if ($tenantId !== null) {
            Context::add('tenant_id', $tenantId);
        } else {
            Context::forget('tenant_id');
        }

        // Solo conexiones ya abiertas; las demás se sincronizan al establecerse.
        foreach (DB::getConnections() as $connection) {
            if ($connection->getRawPdo() instanceof \PDO) {
                $this->syncConnection($connection);
            }
        }
    }
}
