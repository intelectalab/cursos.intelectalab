<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Application\CrmService;
use App\Modules\Crm\Domain\Models\Opportunity;
use App\Modules\Crm\Domain\Models\OpportunityActivity;
use App\Modules\Crm\Http\CrmPresenter;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Http\ApiResponse;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Oportunidades: el embudo comercial por etapas y el seguimiento de cada una.
 */
class OpportunityController extends Controller
{
    private const RELATIONS = ['company', 'contact', 'owner'];

    /** Cuántas oportunidades muestra el tablero por etapa */
    private const BOARD_LIMIT = 50;

    public function __construct(
        private readonly CrmService $crm,
        private readonly TenantSettingsService $settings,
    ) {}

    /**
     * Tablero del embudo: por etapa, cuántas hay, cuánto suman y las más recientes.
     */
    public function board(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'owner_id' => ['nullable', 'uuid'],
        ]);

        $filtered = fn () => $this->filtered($filters);

        $totals = $filtered()
            ->selectRaw('stage, count(*) as total, coalesce(sum(estimated_amount), 0) as amount')
            ->groupBy('stage')
            ->get()
            ->keyBy('stage');

        $stages = collect(Opportunity::STAGES)->map(fn (string $label, string $stage) => [
            'stage' => $stage,
            'label' => $label,
            'count' => (int) ($totals[$stage]->total ?? 0),
            'amount' => number_format((float) ($totals[$stage]->amount ?? 0), 2, '.', ''),
            'items' => $filtered()
                ->with(self::RELATIONS)
                ->where('stage', $stage)
                ->orderByDesc('updated_at')
                ->orderBy('id')
                ->limit(self::BOARD_LIMIT)
                ->get()
                ->map(fn (Opportunity $opportunity) => CrmPresenter::opportunity($opportunity))
                ->values(),
        ])->values();

        return ApiResponse::success($stages, ['currency' => $this->settings->currency()]);
    }

    public function store(Request $request, TenantContext $context): JsonResponse
    {
        $data = $this->validated($request, $context);
        $this->crm->assertOwner($data['owner_id'] ?? null);

        $opportunity = $this->crm->createOpportunity($request->user(), $data);

        return $this->respond($opportunity, 201);
    }

    public function show(Opportunity $opportunity): JsonResponse
    {
        return $this->respond($opportunity);
    }

    public function update(Request $request, Opportunity $opportunity, TenantContext $context): JsonResponse
    {
        $data = $this->validated($request, $context);
        $this->crm->assertOwner($data['owner_id'] ?? null);
        $this->crm->assertContactOfCompany($data['contact_id'] ?? null, $data['company_id']);

        $opportunity->update($data);

        return $this->respond($opportunity);
    }

    public function move(Request $request, Opportunity $opportunity): JsonResponse
    {
        $data = $request->validate([
            'stage' => ['required', Rule::in(array_keys(Opportunity::STAGES))],
            'lost_reason' => ['required_if:stage,'.Opportunity::STAGE_LOST, 'nullable', 'string', 'min:3', 'max:500'],
        ], [
            'lost_reason.required_if' => 'Indica por qué se perdió la oportunidad.',
        ]);

        $lostReason = $data['stage'] === Opportunity::STAGE_LOST ? $data['lost_reason'] : null;

        return $this->respond($this->crm->moveOpportunity($opportunity, $request->user(), $data['stage'], $lostReason));
    }

    public function storeActivity(Request $request, Opportunity $opportunity): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(OpportunityActivity::MANUAL_TYPES)],
            'notes' => ['required', 'string', 'max:5000'],
            'occurred_at' => ['nullable', 'date', 'before_or_equal:now'],
        ]);

        $opportunity->activities()->create([
            'type' => $data['type'],
            'notes' => $data['notes'],
            'occurred_at' => $data['occurred_at'] ?? now(),
            'created_by' => $request->user()->id,
        ]);

        return $this->respond($opportunity, 201);
    }

    /**
     * @param  array{search?: ?string, owner_id?: ?string}  $filters
     * @return Builder<Opportunity>
     */
    private function filtered(array $filters): Builder
    {
        return Opportunity::query()
            ->when($filters['owner_id'] ?? null, fn (Builder $q, string $owner) => $q->where('owner_id', $owner))
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $like = '%'.mb_strtolower($search).'%';

                $q->where(fn (Builder $match) => $match
                    ->whereRaw('lower(title) like ?', [$like])
                    ->orWhereHas('company', fn (Builder $company) => $company->whereRaw('lower(name) like ?', [$like])));
            });
    }

    /**
     * Detalle con su seguimiento.
     */
    private function respond(Opportunity $opportunity, int $status = 200): JsonResponse
    {
        $opportunity->load([...self::RELATIONS, 'activities.author']);

        return ApiResponse::success(CrmPresenter::opportunity($opportunity) + [
            'activities' => $opportunity->activities->map(fn (OpportunityActivity $activity) => CrmPresenter::activity($activity))->values(),
        ], ['currency' => $this->settings->currency()], $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, TenantContext $context): array
    {
        $tenant = $context->tenantId();

        return $request->validate([
            'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')->where('tenant_id', $tenant)],
            'contact_id' => ['nullable', 'uuid', Rule::exists('contacts', 'id')->where('tenant_id', $tenant)],
            'title' => ['required', 'string', 'max:200'],
            'estimated_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'expected_close_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'owner_id' => ['sometimes', 'nullable', 'uuid'],
        ]);
    }
}
