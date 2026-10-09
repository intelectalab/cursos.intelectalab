<?php

namespace App\Modules\Billing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Billing\Application\PlanOrderService;
use App\Modules\Billing\Domain\Models\PlanOrder;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\StateCounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Consola de plataforma: los pagos de plan de todas las organizaciones.
 * El administrador de plataforma confirma los que recibió, como hace cada
 * academia con los pagos de sus alumnos.
 */
class PlatformPlanOrderController extends Controller
{
    public function __construct(private readonly PlanOrderService $orders) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(PlanOrder::STATUSES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $base = PlanOrder::query();
        $counts = StateCounts::of($base, PlanOrder::STATUSES, fn ($query, string $status) => $query->where('status', $status));

        $orders = $base
            ->with('tenant')
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
        ];
    }
}
