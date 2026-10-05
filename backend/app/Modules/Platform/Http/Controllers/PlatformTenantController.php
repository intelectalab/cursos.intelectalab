<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Http\ApiResponse;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlatformTenantController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'max:30'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $tenants = Tenant::query()
            ->withCount('users')
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 25);

        return ApiResponse::paginated($tenants, fn (Tenant $tenant) => $this->present($tenant));
    }

    /**
     * Suspende o reactiva una organización.
     */
    public function updateStatus(Request $request, Tenant $tenant, TenantContext $context, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([Tenant::STATUS_ACTIVE, Tenant::STATUS_SUSPENDED])],
        ]);

        $before = $tenant->status;

        if ($before !== $data['status']) {
            $tenant->update(['status' => $data['status']]);

            // El evento queda en la auditoría de la organización afectada
            $context->runForTenant($tenant->id, fn () => $audit->record(
                'tenants.status_changed',
                $tenant,
                ['status' => $before],
                ['status' => $tenant->status, 'platform_admin_id' => $request->user()->id],
            ));
        }

        return ApiResponse::success($this->present($tenant->loadCount('users')));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Tenant $tenant): array
    {
        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'status' => $tenant->status,
            'users_count' => $tenant->users_count,
            'created_at' => $tenant->created_at?->toIso8601String(),
        ];
    }
}
