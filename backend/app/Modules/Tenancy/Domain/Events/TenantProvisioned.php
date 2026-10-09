<?php

namespace App\Modules\Tenancy\Domain\Events;

use App\Modules\Tenancy\Domain\Models\Tenant;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se creó una organización, con sus roles y su administrador. Se emite con
 * la organización ya en contexto: quien escucha puede crear sus registros.
 */
class TenantProvisioned
{
    use Dispatchable;

    public function __construct(public readonly Tenant $tenant) {}
}
