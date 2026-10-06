<?php

namespace App\Modules\Claims\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Claims\Application\ClaimService;
use App\Modules\Claims\Domain\Models\Claim;
use App\Modules\Claims\Http\ClaimPresenter;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Http\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Libro de Reclamaciones virtual: lo llena cualquier consumidor, sin cuenta,
 * en el subdominio de la organización.
 */
class PublicClaimController extends Controller
{
    public function __construct(
        private readonly ClaimService $claims,
        private readonly TenantSettingsService $settings,
    ) {}

    /**
     * Lo que muestra el formulario: datos del proveedor, plazo de respuesta y moneda.
     */
    public function form(Request $request): JsonResponse
    {
        return ApiResponse::success([
            'provider' => $this->claims->provider($request->attributes->get('tenant')),
            'response_days' => $this->settings->claimResponseDays(),
            'currency' => $this->settings->currency(),
            'doc_types' => Claim::DOC_TYPES,
        ]);
    }

    /**
     * Maestro §7.2 (POST /claims).
     */
    public function store(Request $request): JsonResponse
    {
        $docRule = User::DOCUMENT_TYPES[$request->input('doc_type')]['rule'] ?? 'regex:/^[A-Za-z0-9]{6,15}$/';

        $data = $request->validate([
            'type' => ['required', Rule::in(Claim::TYPES)],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'doc_type' => ['required', Rule::in(Claim::DOC_TYPES)],
            'doc_number' => ['required', 'string', $request->input('doc_type') === 'RUC' ? 'digits:11' : $docRule],
            'address' => ['required', 'string', 'max:250'],
            'email' => ['required', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'is_minor' => ['sometimes', 'boolean'],
            'guardian_name' => ['required_if:is_minor,true', 'nullable', 'string', 'max:200'],
            'guardian_doc_number' => ['required_if:is_minor,true', 'nullable', 'string', 'max:20'],
            'good_type' => ['required', Rule::in(Claim::GOOD_TYPES)],
            'good_description' => ['required', 'string', 'max:500'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'order_reference' => ['nullable', 'string', 'max:60'],
            'detail' => ['required', 'string', 'min:10', 'max:5000'],
            'request' => ['required', 'string', 'min:5', 'max:3000'],
            // El consumidor declara que los datos son ciertos
            'accepted' => ['accepted'],
        ], [
            'guardian_name.required_if' => 'Indica el nombre del padre, madre o apoderado.',
            'guardian_doc_number.required_if' => 'Indica el documento del padre, madre o apoderado.',
            'accepted.accepted' => 'Debes confirmar que los datos son correctos.',
        ]);

        unset($data['accepted']);
        $data['doc_number'] = mb_strtoupper($data['doc_number']);
        $data['email'] = mb_strtolower($data['email']);

        if (! ($data['is_minor'] ?? false)) {
            $data['guardian_name'] = $data['guardian_doc_number'] = null;
        }

        $claim = $this->claims->register($request->attributes->get('tenant'), $data, $request->ip(), $request->userAgent());

        return ApiResponse::success(ClaimPresenter::make($claim->load(['history', 'messages']), forStaff: false), null, 201);
    }

    /**
     * Seguimiento: código de la hoja y número de documento. Va por POST para
     * que el documento no quede en la dirección ni en los registros del servidor.
     */
    public function lookup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'doc_number' => ['required', 'string', 'max:20'],
        ]);

        $claim = $this->claims->find($data['code'], $data['doc_number']);

        if (! $claim) {
            throw new ApiException(ErrorCode::NotFound, 'No encontramos una hoja con ese código y documento. Revisa los datos de tu constancia.', 404);
        }

        return ApiResponse::success(ClaimPresenter::make($claim->load(['history', 'messages']), forStaff: false));
    }
}
