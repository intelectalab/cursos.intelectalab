<?php

namespace App\Modules\Identity\Http\Requests;

use App\Modules\Identity\Domain\Catalog\PermissionCatalog;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $tenantId = app(TenantContext::class)->tenantId();

        return [
            'name' => [
                'required', 'string', 'max:80',
                Rule::unique('roles', 'name')
                    ->where('tenant_id', $tenantId)
                    ->ignore($this->route('role')),
            ],
            'description' => ['nullable', 'string', 'max:255'],
            'permissions' => ['present', 'array'],
            // Solo permisos del catálogo asignable: nunca "*" ni permisos de plataforma
            'permissions.*' => ['string', 'distinct', Rule::in(array_keys(PermissionCatalog::assignable()))],
        ];
    }

    public function messages(): array
    {
        return [
            'name.unique' => 'Ya existe un rol con ese nombre.',
            'permissions.*.in' => 'El permiso :input no existe en el catálogo.',
        ];
    }
}
