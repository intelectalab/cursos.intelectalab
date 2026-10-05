<?php

namespace App\Modules\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'subdomain' => strtolower(trim((string) $this->input('subdomain'))),
            'email' => strtolower(trim((string) $this->input('email'))),
        ]);
    }

    public function rules(): array
    {
        return [
            'organization_name' => ['required', 'string', 'max:150'],
            'subdomain' => [
                'required',
                'string',
                'min:3',
                'max:40',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::notIn(config('tenancy.reserved_slugs')),
                Rule::unique('tenants', 'slug'),
            ],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:180'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ];
    }

    public function messages(): array
    {
        return [
            'subdomain.regex' => 'El subdominio solo admite minúsculas, números y guiones.',
            'subdomain.not_in' => 'Ese subdominio está reservado.',
            'subdomain.unique' => 'Ese subdominio ya está en uso.',
        ];
    }
}
