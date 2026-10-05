<?php

namespace App\Modules\Audit\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Domain\Models\AuditEvent;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'action' => ['nullable', 'string', 'max:100'],
            'entity_type' => ['nullable', 'string', 'max:100'],
            'actor_user_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $events = AuditEvent::query()
            ->with('actor')
            // "roles" encuentra roles.created, roles.updated, etc.
            ->when($filters['action'] ?? null, fn ($query, $action) => $query->where('action', 'like', $action.'%'))
            ->when($filters['entity_type'] ?? null, fn ($query, $type) => $query->where('entity_type', $type))
            ->when($filters['actor_user_id'] ?? null, fn ($query, $actor) => $query->where('actor_user_id', $actor))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->whereDate('created_at', '<=', $to))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 25);

        return ApiResponse::paginated($events, fn (AuditEvent $event) => [
            'id' => $event->id,
            'action' => $event->action,
            'entity_type' => $event->entity_type,
            'entity_id' => $event->entity_id,
            'actor' => $event->actor ? [
                'id' => $event->actor->id,
                'name' => trim($event->actor->first_name.' '.$event->actor->last_name),
                'email' => $event->actor->email,
            ] : null,
            'before' => $event->before,
            'after' => $event->after,
            'ip_address' => $event->ip_address,
            'request_id' => $event->request_id,
            'created_at' => $event->created_at?->toIso8601String(),
        ]);
    }
}
