<?php

namespace App\Modules\Audit\Application;

use App\Modules\Audit\Domain\Models\AuditEvent;
use App\Modules\Identity\Domain\Models\User;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;

/**
 * Registro de operaciones sensibles (Maestro RN-015 y FP-23):
 * actor, tenant, acción, entidad y datos relevantes.
 */
class AuditLogger
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  Model|string  $entity  modelo afectado o nombre del tipo de entidad
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        string $action,
        Model|string $entity,
        ?array $before = null,
        ?array $after = null,
        ?string $actorUserId = null,
        ?string $entityId = null,
    ): AuditEvent {
        $request = request();

        return AuditEvent::create([
            'tenant_id' => $this->context->tenantId(),
            // Solo usuarios de la organización; un administrador de plataforma no lo es
            'actor_user_id' => $actorUserId ?? (Auth::user() instanceof User ? Auth::id() : null),
            'action' => $action,
            'entity_type' => $entity instanceof Model ? $entity->getTable() : $entity,
            'entity_id' => $entity instanceof Model ? $entity->getKey() : $entityId,
            'before' => $before,
            'after' => $after,
            'ip_address' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 500) ?: null,
            'request_id' => Context::get('request_id'),
        ]);
    }
}
