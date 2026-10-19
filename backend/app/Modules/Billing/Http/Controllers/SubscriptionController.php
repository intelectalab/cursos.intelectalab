<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Application\PlanOnlinePayments;
use App\Modules\Billing\Application\PlanOrderService;
use App\Modules\Billing\Application\PlanPresenter;
use App\Modules\Billing\Application\PlatformGateways;
use App\Modules\Billing\Application\SubscriptionService;
use App\Modules\Billing\Domain\Models\Plan;
use App\Modules\Billing\Domain\Models\PlanOrder;
use App\Modules\Files\Application\FileStorage;
use App\Modules\Sales\Application\Payments\Online\GatewayErrors;
use App\Modules\Tenancy\Application\TenantUrls;
use App\Shared\Http\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\ErrorCode;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lo que la organización hace con su propio plan: ver en qué situación
 * está, elegir un plan, pedir su orden de pago y pagarla en línea o
 * informar que pagó por fuera.
 */
class SubscriptionController extends Controller
{
    private const HISTORY_LIMIT = 24;

    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly PlanOrderService $orders,
        private readonly PlanOnlinePayments $online,
        private readonly PlatformGateways $gateways,
        private readonly FileStorage $storage,
        private readonly TenantContext $context,
    ) {}

    public function show(): JsonResponse
    {
        $tenantId = $this->context->tenantId();
        $outcome = $this->orders->unseenOutcome($tenantId);

        return ApiResponse::success(
            $this->subscriptions->present($this->subscriptions->of($tenantId), $this->subscriptions->studentsIn($tenantId)),
            // Orden que se resolvió y la organización aún no vio: se le avisa hasta que lo marque
            ['order_notice' => $outcome ? [
                'number' => $outcome->number,
                'status' => $outcome->status,
                'plan_name' => $outcome->plan_name,
                'notes' => $outcome->status === PlanOrder::STATUS_CANCELLED ? $outcome->notes : null,
            ] : null]
        );
    }

    /**
     * "Mi plan": la situación actual, los planes que se ofrecen, cómo pagar y las órdenes de la organización.
     */
    public function plans(): JsonResponse
    {
        $tenantId = $this->context->tenantId();

        // Lo que ya pasó su plazo se muestra vencido sin esperar a la tarea programada
        $this->orders->expireOverdue($tenantId);

        $orders = PlanOrder::query()->where('tenant_id', $tenantId)->with('proof')->latest()->orderByDesc('number')->limit(self::HISTORY_LIMIT)->get();
        $pending = $orders->firstWhere('status', PlanOrder::STATUS_PENDING);

        return ApiResponse::success([
            'subscription' => $this->subscriptions->present($this->subscriptions->of($tenantId), $this->subscriptions->studentsIn($tenantId)),
            'plans' => Plan::query()->where('status', Plan::STATUS_ACTIVE)->orderBy('price')->orderBy('name')->get()
                ->map(fn (Plan $plan) => PlanPresenter::plan($plan))->values(),
            'payment_instructions' => $this->orders->paymentInstructions(),
            'pending_order' => $pending ? $this->orders->present($pending) : null,
            // Pasarelas con las que puede pagar la orden pendiente en línea
            'online_gateways' => $this->online->availableFor($pending),
            'proof' => ['max_kb' => config('files.limits.image'), 'extensions' => config('files.purposes.'.PlanOrder::PROOF_PURPOSE.'.extensions')],
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

    /**
     * Adjunta la constancia del pago hecho por fuera (reemplaza la anterior).
     */
    public function uploadProof(Request $request, string $order): JsonResponse
    {
        $request->validate(['file' => $this->storage->rules(PlanOrder::PROOF_PURPOSE)]);

        return ApiResponse::success($this->orders->present($this->orders->attachProof($this->own($order), $request->file('file'))));
    }

    public function removeProof(string $order): JsonResponse
    {
        return ApiResponse::success($this->orders->present($this->orders->removeProof($this->own($order))));
    }

    public function downloadProof(string $order): StreamedResponse
    {
        return $this->storage->response($this->own($order)->proof ?? abort(404));
    }

    public function cancelOrder(string $order): JsonResponse
    {
        return ApiResponse::success($this->orders->present($this->orders->cancel($this->own($order))));
    }

    /**
     * Pago en línea: devuelve a dónde enviar a quien paga. La orden sigue
     * pendiente hasta que la pasarela confirme el pago.
     */
    public function pay(Request $request, string $order): JsonResponse
    {
        $data = $request->validate([
            'gateway' => ['required', Rule::in($this->gateways->codes())],
            // A dónde vuelve: "Mi plan", en esta misma organización
            'return_url' => ['required', 'url:http,https', 'max:500'],
        ]);

        if (! TenantUrls::owns($data['return_url'], $request->attributes->get('tenant'))) {
            throw new ApiException(ErrorCode::ValidationError, 'Los datos no son válidos.', 422, ['return_url' => ['La dirección de retorno no es de esta academia.']]);
        }

        // La cuenta que cobra es de la plataforma: la academia no puede hacer nada con sus fallas
        $checkoutUrl = GatewayErrors::forPayer(fn () => $this->online->start($this->own($order), $data['gateway'], $request->user(), [
            'return' => $data['return_url'],
            // Los avisos van a la plataforma, y solo llegan a una dirección pública con HTTPS
            'webhook' => str_starts_with($data['return_url'], 'https://')
                ? 'https://'.config('tenancy.central_domain').'/api/v1/platform/payments/webhooks/'.strtolower($data['gateway'])
                : null,
        ]), 'No pudimos abrir el pago en este momento. No se te cobró nada. Inténtalo de nuevo en unos minutos; si sigue igual, avisa a IntelectaLab e indica el código de este mensaje.');

        return ApiResponse::success(['checkout_url' => $checkoutUrl], null, 201);
    }

    /**
     * La organización volvió de la pasarela: se le pregunta a ella si pagó.
     */
    public function syncPayment(string $order): JsonResponse
    {
        return ApiResponse::success($this->orders->present(GatewayErrors::forPayer(fn () => $this->online->sync($this->own($order)), 'No pudimos comprobar tu pago en este momento. Si ya pagaste, no vuelvas a pagar: inténtalo de nuevo en unos minutos o avisa a IntelectaLab con el código de este mensaje.')));
    }

    /**
     * La organización ya vio cómo se resolvió su orden: no se le avisa más.
     */
    public function markSeen(): JsonResponse
    {
        $this->orders->markOutcomeSeen($this->context->tenantId());

        return ApiResponse::success(['seen' => true]);
    }

    /**
     * Orden de esta organización; la de otra no existe.
     */
    private function own(string $id): PlanOrder
    {
        return PlanOrder::query()->where('tenant_id', $this->context->tenantId())->findOrFail($id);
    }
}
