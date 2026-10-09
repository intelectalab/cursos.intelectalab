<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Application\PlanOrderService;
use App\Modules\Billing\Domain\Models\PlanOrder;
use App\Modules\Files\Application\FileStorage;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\StateCounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Consola de plataforma: los pagos de plan de todas las organizaciones.
 * El administrador de plataforma confirma los que recibió, como hace cada
 * academia con los pagos de sus alumnos.
 */
class PlatformPlanOrderController extends Controller
{
    public function __construct(
        private readonly PlanOrderService $orders,
        private readonly FileStorage $storage,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(PlanOrder::STATUSES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // Lo que ya pasó su plazo se muestra vencido sin esperar a la tarea programada
        $this->orders->expireOverdue();

        $base = PlanOrder::query();
        $counts = StateCounts::of($base, PlanOrder::STATUSES, fn ($query, string $status) => $query->where('status', $status));

        $orders = $base
            ->with(['tenant', 'proof'])
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            // Primero lo que espera confirmación, de lo más antiguo a lo más nuevo
            ->orderByRaw("status = 'PENDING' desc")
            ->orderByRaw("case when status = 'PENDING' then created_at end asc")
            ->orderByDesc('created_at')
            ->orderByDesc('number')
            ->paginate($filters['per_page'] ?? 25);

        return ApiResponse::paginated($orders, fn (PlanOrder $order) => $this->present($order), [
            'counts' => $counts,
            'methods' => PlanOrder::METHODS,
        ]);
    }

    public function confirm(Request $request, string $order): JsonResponse
    {
        $order = $this->find($order);

        $data = $request->validate([
            'method' => ['required', Rule::in(PlanOrder::METHODS)],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        return ApiResponse::success($this->present($this->orders->confirm($order, $data, $request->user()->email)->load('tenant')));
    }

    /**
     * Deshace una confirmación hecha por error: el plan de la organización
     * vuelve a como estaba y la orden, a pendiente.
     */
    public function revert(Request $request, string $order): JsonResponse
    {
        $order = $this->find($order);

        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:300']]);

        return ApiResponse::success($this->present($this->orders->revert($order, $data['reason'], $request->user()->email)->load('tenant')));
    }

    /**
     * Constancia de pago que adjuntó la organización.
     */
    public function proof(string $order): StreamedResponse
    {
        return $this->storage->response($this->find($order)->proof ?? abort(404));
    }

    /**
     * Lo que espera a la plataforma: alimenta el contador del menú de la consola.
     */
    public function summary(): JsonResponse
    {
        $pending = PlanOrder::query()->where('status', PlanOrder::STATUS_PENDING);

        return ApiResponse::success([
            'pending' => (clone $pending)->count(),
            // La organización dice que ya pagó: es lo que hay que revisar primero
            'informed' => (clone $pending)->where(fn ($query) => $query->whereNotNull('payer_reference')->orWhereNotNull('proof_file_id'))->count(),
            'review' => PlanOrder::query()->whereNotNull('review_reason')->count(),
        ]);
    }

    public function cancel(Request $request, string $order): JsonResponse
    {
        $order = $this->find($order);

        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);

        return ApiResponse::success($this->present($this->orders->cancel($order, $request->user()->email, $data['reason'])->load('tenant')));
    }

    /**
     * Cómo deben pagarle las organizaciones a la plataforma (cuenta, Yape…).
     */
    public function settings(): JsonResponse
    {
        return ApiResponse::success(['payment_instructions' => $this->orders->paymentInstructions()]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate(['payment_instructions' => ['nullable', 'string', 'max:2000']]);

        $this->orders->setPaymentInstructions($data['payment_instructions'] ?? null);

        return $this->settings();
    }

    /**
     * La orden se busca aquí y no en la ruta: para verla hace falta estar ya
     * identificado como plataforma, y eso ocurre después de resolver la ruta.
     */
    private function find(string $id): PlanOrder
    {
        return PlanOrder::query()->findOrFail($id);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PlanOrder $order): array
    {
        return $this->orders->present($order) + [
            'tenant' => ['id' => $order->tenant->id, 'name' => $order->tenant->name, 'slug' => $order->tenant->slug],
            'resolved_by' => $order->resolved_by,
            // La pasarela cobró algo que no se aplicó a la orden: hay que resolverlo a mano
            'review_reason' => $order->review_reason,
            // Confirmada a mano y con lo necesario para volver atrás (el servidor aún comprueba que el plan no cambió después)
            'can_revert' => $order->status === PlanOrder::STATUS_PAID && ! $order->wasPaidOnline() && $order->applied_period_ends_at !== null,
        ];
    }
}
