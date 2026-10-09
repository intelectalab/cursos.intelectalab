<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Application\PlanOrderService;
use App\Modules\Billing\Application\PlanPresenter;
use App\Modules\Billing\Application\SubscriptionService;
use App\Modules\Billing\Domain\Models\Plan;
use App\Modules\Billing\Domain\Models\PlanOrder;
use App\Shared\Http\ApiResponse;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Lo que la organización hace con su propio plan: ver en qué situación
 * está, elegir un plan, pedir su orden de pago e informar que pagó.
 */
class SubscriptionController extends Controller
{
    private const HISTORY_LIMIT = 24;

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly PlanOrderService $orders,
        private readonly TenantContext $context,
    ) {}

    public function show(): JsonResponse
    {
        $tenantId = $this->context->tenantId();

        return ApiResponse::success($this->subscriptions->present($this->subscriptions->of($tenantId), $this->subscriptions->studentsIn($tenantId)));
    }

    /**
     * "Mi plan": la situación actual, los planes que se ofrecen, cómo pagar y las órdenes de la organización.
     */
    public function plans(): JsonResponse
    {
        $tenantId = $this->context->tenantId();
        $orders = PlanOrder::query()->where('tenant_id', $tenantId)->latest()->orderByDesc('number')->limit(self::HISTORY_LIMIT)->get();

        return ApiResponse::success([
            'subscription' => $this->subscriptions->present($this->subscriptions->of($tenantId), $this->subscriptions->studentsIn($tenantId)),
            'plans' => Plan::query()->where('status', Plan::STATUS_ACTIVE)->orderBy('price')->orderBy('name')->get()
                ->map(fn (Plan $plan) => PlanPresenter::plan($plan))->values(),
            'payment_instructions' => $this->orders->paymentInstructions(),
            'pending_order' => ($pending = $orders->firstWhere('status', PlanOrder::STATUS_PENDING)) ? $this->orders->present($pending) : null,
            'orders' => $orders->map(fn (PlanOrder $order) => $this->orders->present($order))->values(),
        ]);
    }

    public function storeOrder(Request $request): JsonResponse
    {
        $data = $request->validate(['plan_id' => ['required', 'uuid', Rule::exists('plans', 'id')]]);

        $order = $this->orders->request($request->attributes->get('tenant'), Plan::query()->findOrFail($data['plan_id']), $request->user());

        return ApiResponse::success($this->orders->present($order), null, 201);
    }

    public function informPayment(Request $request, string $order): JsonResponse
    {
        $data = $request->validate(['payer_reference' => ['nullable', 'string', 'max:300']]);

        return ApiResponse::success($this->orders->present($this->orders->informPayment($this->own($order), $data['payer_reference'] ?? null)));
    }

    public function cancelOrder(string $order): JsonResponse
    {
        return ApiResponse::success($this->orders->present($this->orders->cancel($this->own($order))));
    }

    /**
     * Orden de esta organización; la de otra no existe.
     */
    private function own(string $id): PlanOrder
    {
        return PlanOrder::query()->where('tenant_id', $this->context->tenantId())->findOrFail($id);
    }
}
