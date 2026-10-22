<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Files\Application\FileStorage;
use App\Modules\Tenancy\Application\GradeScaleService;
use App\Modules\Tenancy\Application\TenantLogoService;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TenantSettingsController extends Controller
{
    public function __construct(
        private readonly TenantSettingsService $settings,
        private readonly TenantLogoService $logo,
        private readonly GradeScaleService $scales,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->settings->get($request->attributes->get('tenant')) + ['logo_url' => $this->logo->url()],
            [
                'logo' => [
                    'max_kb' => config('files.limits.image'),
                    'extensions' => config('files.purposes.'.TenantLogoService::PURPOSE.'.extensions'),
                ],
                'currencies' => TenantSettingsService::CURRENCIES,
                'grade_scales' => $this->scales->all(),
                'grade_scale_max_score' => [
                    'min' => GradeScaleService::MAX_SCORE_MIN,
                    'max' => GradeScaleService::MAX_SCORE_MAX,
                ],
                'session_minutes' => [
                    'min' => config('identity.session_lifetime_min'),
                    'max' => config('identity.session_lifetime_max'),
                ],
                'order_expiration_hours' => [
                    'min' => TenantSettingsService::ORDER_EXPIRATION_MIN,
                    'max' => TenantSettingsService::ORDER_EXPIRATION_MAX,
                ],
            ]
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
            'grade_scale' => ['required', 'integer', Rule::in($this->scales->availableMaxScores())],
            'payment_instructions' => ['nullable', 'string', 'max:5000'],
            'order_expiration_hours' => [
                'nullable', 'integer',
                'min:'.TenantSettingsService::ORDER_EXPIRATION_MIN,
                'max:'.TenantSettingsService::ORDER_EXPIRATION_MAX,
            ],
            'claim_response_days' => [
                'nullable', 'integer',
                'min:'.TenantSettingsService::CLAIM_RESPONSE_MIN,
                'max:'.TenantSettingsService::CLAIM_RESPONSE_MAX,
            ],
            'require_two_factor' => ['sometimes', 'boolean'],
            'session_minutes' => [
                'required', 'integer',
                'min:'.config('identity.session_lifetime_min'),
                'max:'.config('identity.session_lifetime_max'),
            ],
        ], ['secondary_currency.different' => 'La moneda secundaria debe ser distinta de la principal.']);

        // Quien la exige debe tenerla: si no, se quedaría fuera de su propia academia
        if (($data['require_two_factor'] ?? false) && $request->user()->two_factor_confirmed_at === null) {
            throw ValidationException::withMessages(['require_two_factor' => 'Activa primero tu propia verificación en dos pasos en «Mi cuenta».']);
        }

        return ApiResponse::success(
            $this->settings->update($request->attributes->get('tenant'), $data) + ['logo_url' => $this->logo->url()]
        );
    }

    public function uploadLogo(Request $request, FileStorage $storage): JsonResponse
    {
        $request->validate(['file' => $storage->rules(TenantLogoService::PURPOSE)]);

        return ApiResponse::success([
            'logo_url' => $this->logo->replace($request->attributes->get('tenant'), $request->file('file')),
        ]);
    }

    public function removeLogo(Request $request): JsonResponse
    {
        $this->logo->remove($request->attributes->get('tenant'));

        return ApiResponse::success(['logo_url' => null]);
    }
}
