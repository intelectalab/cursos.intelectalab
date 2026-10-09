<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Application\PlanPresenter;
use App\Modules\Billing\Application\SubscriptionService;
use App\Modules\Billing\Domain\Models\Plan;
use App\Modules\Billing\Domain\Models\TenantSubscription;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Http\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * Consola de plataforma: el catálogo de planes y la suscripción de cada
 * organización (asignarle un plan o extender su prueba).
 */
class PlatformPlanController extends Controller
{
    public function __construct(private readonly SubscriptionService $subscriptions) {}

    public function index(): JsonResponse
    {
        $inUse = TenantSubscription::query()->whereNotNull('plan_id')->selectRaw('plan_id, count(*) as total')->groupBy('plan_id')->pluck('total', 'plan_id');

        return ApiResponse::success(
            Plan::query()
                ->orderByRaw("status = 'ACTIVE' desc")
                ->orderBy('price')
                ->orderBy('name')
                ->get()
                ->map(fn (Plan $plan) => PlanPresenter::plan($plan) + ['tenants_count' => (int) ($inUse[$plan->id] ?? 0)])
                ->values(),
            ['trial_days' => config('billing.trial_days'), 'trial_max_students' => config('billing.trial_max_students')]
        );
    }

    public function store(Request $request): JsonResponse
    {
        return ApiResponse::success(PlanPresenter::plan(Plan::create($this->validated($request))) + ['tenants_count' => 0], null, 201);
    }

    /**
     * Cambiar un plan no altera a quienes ya lo tienen: conservan el tope con
     * el que se les asignó hasta su próxima asignación.
     */
    public function update(Request $request, Plan $plan): JsonResponse
    {
        $plan->update($this->validated($request));

        return ApiResponse::success(PlanPresenter::plan($plan));
    }

    /**
     * Asigna un plan a la organización, o la deja sin límites (plan_id vacío).
     */
    public function assign(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'plan_id' => ['nullable', 'uuid', Rule::exists('plans', 'id')],
            // Hasta cuándo queda pagado; vacío = un periodo del plan desde hoy
            'ends_at' => ['nullable', 'date_format:Y-m-d', 'after:today'],
        ]);

        $plan = ($data['plan_id'] ?? null) ? Plan::query()->findOrFail($data['plan_id']) : null;

        if ($plan !== null && ! $plan->isActive()) {
            throw new ApiException(ErrorCode::ValidationError, 'Ese plan está archivado y ya no se ofrece.', 422, ['plan_id' => ['Elige un plan vigente.']]);
        }

        $subscription = $this->subscriptions->assign(
            $tenant,
            $plan,
            ($data['ends_at'] ?? null) ? Carbon::createFromFormat('Y-m-d', $data['ends_at'])->endOfDay() : null,
            $request->user()->email
        );

        return ApiResponse::success($this->subscriptions->present($subscription->load('plan'), $this->subscriptions->studentsIn($tenant->id)));
    }

    public function extendTrial(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate(['trial_ends_at' => ['required', 'date_format:Y-m-d', 'after:today']]);

        $subscription = $this->subscriptions->extendTrial(
            $tenant,
            Carbon::createFromFormat('Y-m-d', $data['trial_ends_at'])->endOfDay(),
            $request->user()->email
        );

        return ApiResponse::success($this->subscriptions->present($subscription->load('plan'), $this->subscriptions->studentsIn($tenant->id)));
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'description' => ['nullable', 'string', 'max:300'],
            'price' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:99999999.99'],
            'currency' => ['required', Rule::in(['PEN', 'USD'])],
            'billing_months' => ['required', Rule::in(Plan::BILLING_MONTHS)],
            'max_students' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'status' => ['nullable', Rule::in(Plan::STATUSES)],
        ]);
    }
}
