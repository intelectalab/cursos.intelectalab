<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Application\CrmService;
use App\Modules\Crm\Domain\Models\Lead;
use App\Modules\Crm\Http\CrmPresenter;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Export\ExcelExport;
use App\Shared\Http\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\ErrorCode;
use App\Shared\Http\StateCounts;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Prospectos: personas interesadas que el equipo comercial califica y
 * convierte en empresa, contacto y oportunidad, o descarta.
 */
class LeadController extends Controller
{
    private const RELATIONS = ['owner', 'company'];

    private const STATUS_LABELS = ['NEW' => 'Nuevo', 'CONTACTED' => 'Contactado', 'CONVERTED' => 'Convertido', 'DISCARDED' => 'Descartado'];

    private const SOURCE_LABELS = ['WEB' => 'Sitio web', 'REFERRAL' => 'Referido', 'EVENT' => 'Evento', 'CALL' => 'Llamada', 'SOCIAL' => 'Redes sociales', 'OTHER' => 'Otro'];

    public function __construct(private readonly CrmService $crm) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([...Lead::STATUSES, 'OPEN'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $base = $this->search($filters['search'] ?? null);

        // Cuántas hay en cada estado con la búsqueda actual: alimenta las tarjetas que filtran
        $counts = StateCounts::of($base, Lead::STATUSES, fn (Builder $q, string $status) => $q->where('status', $status));

        $leads = $this->inStatus($base, $filters['status'] ?? null)
            ->orderByDesc('created_at')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 50);

        return ApiResponse::paginated($leads, fn (Lead $lead) => CrmPresenter::lead($lead), ['counts' => $counts]);
    }

    /**
     * Los prospectos del listado, con la misma búsqueda y estado, en Excel.
     */
    public function export(Request $request, TenantSettingsService $settings): BinaryFileResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in([...Lead::STATUSES, 'OPEN'])],
        ]);

        $timezone = $settings->timezone();

        return ExcelExport::download(
            'prospectos',
            $this->inStatus($this->search($filters['search'] ?? null), $filters['status'] ?? null)->orderByDesc('created_at')->orderBy('id'),
            ['Nombres', 'Apellidos', 'Empresa', 'Cargo', 'Correo', 'Teléfono', 'Origen', 'Interés', 'Estado', 'Motivo de descarte', 'A cargo de', 'Registrado el', 'Convertido el'],
            fn (Lead $lead) => [
                $lead->first_name,
                $lead->last_name,
                $lead->company_name,
                $lead->position,
                $lead->email,
                $lead->phone,
                self::SOURCE_LABELS[$lead->source] ?? $lead->source,
                $lead->interest,
                self::STATUS_LABELS[$lead->status] ?? $lead->status,
                $lead->discard_reason,
                $lead->owner ? trim("{$lead->owner->first_name} {$lead->owner->last_name}") : null,
                ExcelExport::date($lead->created_at, $timezone),
                ExcelExport::date($lead->converted_at, $timezone),
            ]
        );
    }

    /**
     * @return Builder<Lead>
     */
    private function search(?string $search): Builder
    {
        return Lead::query()
            ->with(self::RELATIONS)
            ->when($search, function (Builder $q, string $search) {
                $like = '%'.mb_strtolower($search).'%';

                $q->where(fn (Builder $match) => $match
                    ->whereRaw("lower(first_name || ' ' || coalesce(last_name, '')) like ?", [$like])
                    ->orWhereRaw('lower(company_name) like ?', [$like])
                    ->orWhereRaw('lower(email) like ?', [$like]));
            });
    }

    /**
     * @param  Builder<Lead>  $query
     * @return Builder<Lead>
     */
    private function inStatus(Builder $query, ?string $status): Builder
    {
        return $query->when($status, fn (Builder $q, string $status) => $status === 'OPEN'
            ? $q->whereIn('status', Lead::OPEN_STATUSES)
            : $q->where('status', $status));
    }

    /**
     * Personal que puede quedar a cargo de prospectos y oportunidades.
     */
    public function owners(): JsonResponse
    {
        return ApiResponse::success($this->crm->owners()->get()->map(fn (User $user) => CrmPresenter::person($user))->values());
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $this->crm->assertOwner($data['owner_id'] ?? null);

        $lead = Lead::create($data + [
            'owner_id' => $request->user()->id,
            'created_by' => $request->user()->id,
            'status' => Lead::STATUS_NEW,
        ]);

        return ApiResponse::success(CrmPresenter::lead($lead->load(self::RELATIONS)), null, 201);
    }

    public function show(Lead $lead): JsonResponse
    {
        return ApiResponse::success(CrmPresenter::lead($lead->load(self::RELATIONS)));
    }

    public function update(Request $request, Lead $lead): JsonResponse
    {
        if (! $lead->isOpen()) {
            throw new ApiException(ErrorCode::LeadNotOpen, 'Este prospecto ya fue convertido o descartado.', 409);
        }

        $data = $this->validated($request) + $request->validate([
            'status' => ['sometimes', Rule::in(Lead::OPEN_STATUSES)],
        ]);
        $this->crm->assertOwner($data['owner_id'] ?? null);

        $lead->update($data);

        return ApiResponse::success(CrmPresenter::lead($lead->load(self::RELATIONS)));
    }

    public function convert(Request $request, Lead $lead, TenantContext $context): JsonResponse
    {
        $tenant = $context->tenantId();

        $data = $request->validate([
            // Empresa existente o datos para crear una nueva
            'company_id' => ['nullable', 'uuid', Rule::exists('companies', 'id')->where('tenant_id', $tenant)],
            'company' => ['required_without:company_id', 'nullable', 'array'],
            'company.name' => ['required_without:company_id', 'nullable', 'string', 'max:200'],
            'company.tax_id' => ['nullable', 'string', 'max:20', Rule::unique('companies', 'tax_id')->where('tenant_id', $tenant)],
            'opportunity' => ['nullable', 'array'],
            'opportunity.title' => ['required_with:opportunity', 'string', 'max:200'],
            'opportunity.estimated_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'opportunity.expected_close_date' => ['nullable', 'date'],
        ], [
            'company.tax_id.unique' => 'Ya existe una empresa con ese RUC. Elígela de la lista.',
        ]);

        $lead = $this->crm->convertLead($lead, $request->user(), $data);

        return ApiResponse::success(CrmPresenter::lead($lead->load(self::RELATIONS)));
    }

    public function discard(Request $request, Lead $lead): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return ApiResponse::success(CrmPresenter::lead($this->crm->discardLead($lead, $data['reason'])->load(self::RELATIONS)));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'company_name' => ['nullable', 'string', 'max:200'],
            'position' => ['nullable', 'string', 'max:120'],
            // Hace falta al menos una forma de contactarlo
            'email' => ['required_without:phone', 'nullable', 'email', 'max:150'],
            'phone' => ['required_without:email', 'nullable', 'string', 'max:30'],
            'source' => ['required', Rule::in(Lead::SOURCES)],
            'interest' => ['nullable', 'string', 'max:2000'],
            'owner_id' => ['sometimes', 'nullable', 'uuid'],
        ], [
            'email.required_without' => 'Indica un correo o un teléfono para poder contactarlo.',
            'phone.required_without' => 'Indica un correo o un teléfono para poder contactarlo.',
        ]);
    }
}
