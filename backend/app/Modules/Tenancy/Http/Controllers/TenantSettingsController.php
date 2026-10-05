<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TenantSettingsController extends Controller
{
    public function __construct(private readonly TenantSettingsService $settings) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->settings->get($request->attributes->get('tenant')),
            ['currencies' => TenantSettingsService::CURRENCIES]
        );
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'legal_name' => ['nullable', 'string', 'max:200'],
            'tax_id' => ['nullable', 'string', 'max:30'],
            'primary_currency' => ['required', Rule::in(TenantSettingsService::CURRENCIES)],
            'secondary_currency' => ['nullable', Rule::in(TenantSettingsService::CURRENCIES), 'different:primary_currency'],
            'timezone' => ['required', 'timezone:all'],
            'contact_email' => ['nullable', 'email', 'max:180'],
            'contact_phone' => ['nullable', 'string', 'max:40'],
            'contact_address' => ['nullable', 'string', 'max:255'],
            'terms' => ['nullable', 'string', 'max:50000'],
            'privacy_policy' => ['nullable', 'string', 'max:50000'],
        ], ['secondary_currency.different' => 'La moneda secundaria debe ser distinta de la principal.']);

        return ApiResponse::success($this->settings->update($request->attributes->get('tenant'), $data));
    }
}
