<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Application\ProvisionTenant;
use App\Modules\Tenancy\Application\TenantLogoService;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Http\Requests\RegisterTenantRequest;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TenantController extends Controller
{
    /**
     * Contexto público del host actual: la organización del subdominio,
     * o null en el dominio central.
     */
    public function current(Request $request, TenantLogoService $logo): JsonResponse
    {
        /** @var Tenant|null $tenant */
        $tenant = $request->attributes->get('tenant');

        return ApiResponse::success(
            $tenant ? $this->present($tenant) + ['logo_url' => $logo->url()] : null,
            ['central_domain' => config('tenancy.central_domain')]
        );
    }

    public function register(RegisterTenantRequest $request, ProvisionTenant $provision): JsonResponse
    {
        $data = $request->validated();

        $tenant = $provision->handle(
            ['name' => $data['organization_name'], 'slug' => $data['subdomain']],
            [
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'password' => $data['password'],
            ]
        );

        return ApiResponse::success($this->present($tenant), null, 201);
    }

    /**
     * @return array{id: string, name: string, slug: string}
     */
    private function present(Tenant $tenant): array
    {
        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
        ];
    }
}
