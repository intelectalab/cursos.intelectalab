<?php

namespace App\Modules\B2b\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\B2b\Application\AccountOverview;
use App\Modules\B2b\Application\LicenseService;
use App\Modules\B2b\Application\MemberService;
use App\Modules\B2b\Application\QuotationService;
use App\Modules\B2b\Domain\Models\CorporateAccount;
use App\Modules\B2b\Domain\Models\CorporateMember;
use App\Modules\B2b\Domain\Models\License;
use App\Modules\B2b\Domain\Models\LicenseAssignment;
use App\Modules\B2b\Domain\Models\Quotation;
use App\Modules\B2b\Http\QuotationPresenter;
use App\Modules\Crm\Domain\Models\Company;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Http\OrderPresenter;
use App\Shared\Http\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\ErrorCode;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cuenta de una empresa cliente: licencias, personas y compras.
 *
 * Sirve a dos públicos con las mismas reglas: el Responsable de empresa en su
 * portal (/company: la cuenta sale de su propia vinculación, nunca de un
 * parámetro) y el personal de la academia (/corporate-accounts/{company}).
 */
class CorporateAccountController extends Controller
{
    public function __construct(
        private readonly AccountOverview $overview,
        private readonly LicenseService $licenses,
        private readonly MemberService $members,
        private readonly QuotationService $quotations,
    ) {}

    public function show(Request $request): JsonResponse
    {
        return $this->respond($this->account($request));
    }

    /**
     * Registra a un trabajador. El personal puede además crear responsables y
     * vincular cuentas que ya existen en la academia.
     */
    public function storeMember(Request $request): JsonResponse
    {
        $account = $this->account($request);
        $staff = $this->isStaff($request);

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:150'],
            'password' => ['nullable', 'string', 'min:8'],
            'is_manager' => ['sometimes', 'boolean'],
        ]);

        $this->members->add($account, $request->user(), ['is_manager' => $staff && ($data['is_manager'] ?? false)] + $data, canLinkExisting: $staff);

        return $this->respond($account, 201);
    }

    /**
     * Asigna licencias disponibles de un curso a varios trabajadores. Los
     * parámetros se leen de la ruta por nombre: el portal y el personal no
     * comparten la misma cantidad de segmentos.
     */
    public function assign(Request $request): JsonResponse
    {
        $account = $this->account($request);

        $data = $request->validate([
            'student_ids' => ['required', 'array', 'min:1', 'max:200'],
            'student_ids.*' => ['uuid', 'distinct'],
        ]);

        $this->licenses->assignFromPool($account, $request->route('course'), $data['student_ids'], $request->user());

        return $this->respond($account, 201);
    }

    /**
     * Maestro §7.2 (POST /licenses/{id}/assign): asigna una licencia concreta.
     */
    public function assignLicense(Request $request, License $license, TenantContext $context): JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'uuid', Rule::exists('users', 'id')->where('tenant_id', $context->tenantId())],
        ]);

        $this->licenses->assign($license, User::query()->findOrFail($data['student_id']), $request->user());

        return $this->respond($license->account, 201);
    }

    public function revoke(Request $request): JsonResponse
    {
        $account = $this->account($request);

        // Una asignación de otra empresa no existe para esta cuenta
        $assignment = LicenseAssignment::query()
            ->whereHas('license', fn (Builder $license) => $license->where('corporate_account_id', $account->id))
            ->findOrFail($request->route('assignment'));

        $this->licenses->revoke($assignment, $request->user());

        return $this->respond($account);
    }

    /**
     * El personal entrega las licencias de una venta que aún no se paga.
     */
    public function releaseUnpaid(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);

        $order = $this->licenses->releaseUnpaid($order, $request->user(), $data['reason']);

        return ApiResponse::success(OrderPresenter::make($order->load(['customer', 'company', 'currency', 'items', 'payment.confirmer'])));
    }

    /**
     * Portal: cotizaciones que la academia le envió a la empresa. Los borradores no se muestran.
     */
    public function quotations(Request $request): JsonResponse
    {
        $quotations = $this->visibleQuotations($this->account($request))
            ->with(['company', 'currency'])
            ->orderByDesc('number')
            ->get();

        return ApiResponse::success($quotations->map(fn (Quotation $quotation) => QuotationPresenter::summary($quotation))->values());
    }

    public function quotation(Request $request): JsonResponse
    {
        return $this->respondQuotation($this->ownQuotation($request));
    }

    /**
     * Portal: el responsable acepta la cotización en línea.
     */
    public function acceptQuotation(Request $request): JsonResponse
    {
        return $this->respondQuotation(
            $this->quotations->accept($this->ownQuotation($request), $request->user(), Quotation::VIA_PORTAL)
        );
    }

    public function rejectQuotation(Request $request): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->respondQuotation($this->quotations->reject($this->ownQuotation($request), $data['reason']));
    }

    /**
     * La cuenta sobre la que se opera. En el portal sale de la vinculación de
     * quien tiene la sesión; el personal la indica en la ruta.
     */
    private function account(Request $request): CorporateAccount
    {
        if ($this->isStaff($request)) {
            return CorporateAccount::forCompany(Company::query()->findOrFail($request->route('company'))->id);
        }

        $member = CorporateMember::query()
            ->where('user_id', $request->user()->id)
            ->where('is_manager', true)
            ->with('account')
            ->first();

        if (! $member) {
            throw new ApiException(ErrorCode::Forbidden, 'Tu cuenta no administra ninguna empresa.', 403);
        }

        return $member->account;
    }

    private function isStaff(Request $request): bool
    {
        return $request->route('company') !== null;
    }

    /**
     * @return Builder<Quotation>
     */
    private function visibleQuotations(CorporateAccount $account): Builder
    {
        return Quotation::query()
            ->where('company_id', $account->company_id)
            ->where('status', '!=', Quotation::STATUS_DRAFT);
    }

    private function ownQuotation(Request $request): Quotation
    {
        return $this->visibleQuotations($this->account($request))->findOrFail($request->route('quotation'));
    }

    private function respondQuotation(Quotation $quotation): JsonResponse
    {
        $data = QuotationPresenter::make($quotation->load(QuotationController::RELATIONS));

        // La empresa ve la cotización vigente; el historial interno y quién la armó no le corresponden
        unset($data['versions'], $data['created_by'], $data['opportunity'], $data['acceptance_note']);

        return ApiResponse::success($data);
    }

    private function respond(CorporateAccount $account, int $status = 200): JsonResponse
    {
        return ApiResponse::success($this->overview->build($account->load('company')), null, $status);
    }
}
