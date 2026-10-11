<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Sales\Application\OrderService;
use App\Modules\Sales\Application\Payments\Online\OnlinePaymentService;
use App\Modules\Sales\Application\Payments\PaymentGateway;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\Package;
use App\Modules\Sales\Domain\Models\Payment;
use App\Modules\Sales\Http\OrderPresenter;
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
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Órdenes de compra: el comprador crea y consulta las suyas (/me/orders);
 * el personal ve todas, confirma los pagos recibidos y cancela pendientes.
 */
class OrderController extends Controller
{
    private const RELATIONS = ['customer', 'company', 'currency', 'items', 'payment.confirmer', 'payment.refunder', 'attemptsToReview'];

    private const STATUS_LABELS = ['PENDING' => 'Pendiente de pago', 'PAID' => 'Pagada', 'CANCELLED' => 'Cancelada', 'EXPIRED' => 'Vencida', 'REFUNDED' => 'Reembolsada'];

    private const METHOD_LABELS = ['TRANSFER' => 'Transferencia', 'YAPE' => 'Yape', 'CASH' => 'Efectivo', 'OTHER' => 'Otro', 'CARD' => 'Tarjeta', 'WALLET' => 'Saldo en cuenta', 'PAYPAL' => 'PayPal'];

    private const GATEWAY_LABELS = ['MANUAL' => 'Confirmado por el personal', 'MERCADOPAGO' => 'Mercado Pago', 'PAYPAL' => 'PayPal'];

    public function __construct(
        private readonly OrderService $orders,
        private readonly PaymentGateway $gateway,
        private readonly OnlinePaymentService $online,
    ) {}

    /**
     * Checkout (Maestro §7.2, POST /orders): el comprador es quien tiene la sesión.
     */
    public function store(Request $request, TenantContext $context): JsonResponse
    {
        $data = $request->validate([
            // Se compra un curso o un paquete
            'course_id' => ['required_without:package_id', 'nullable', 'uuid', Rule::exists('courses', 'id')->where('tenant_id', $context->tenantId())],
            'package_id' => ['required_without:course_id', 'nullable', 'uuid', 'prohibits:course_id', Rule::exists('packages', 'id')->where('tenant_id', $context->tenantId())],
            // Total que el comprador vio en pantalla; el servidor lo contrasta con el precio vigente (RN-008)
            'expected_total' => ['nullable', 'numeric', 'min:0'],
            'coupon_code' => ['nullable', 'string', 'max:40'],
        ]);

        $expectedTotal = isset($data['expected_total']) ? (string) $data['expected_total'] : null;
        $coupon = $data['coupon_code'] ?? null;

        $order = isset($data['package_id'])
            ? $this->orders->placePackage($request->user(), Package::query()->findOrFail($data['package_id']), $expectedTotal, $coupon)
            : $this->orders->place($request->user(), Course::query()->with('currentVersion')->findOrFail($data['course_id']), $expectedTotal, $coupon);

        return $this->respondToCustomer($order, 201);
    }

    public function mine(Request $request): JsonResponse
    {
        $orders = Order::query()
            ->where('customer_id', $request->user()->id)
            ->with(self::RELATIONS)
            ->orderByDesc('number')
            ->get();

        return ApiResponse::success($orders->map(fn (Order $order) => OrderPresenter::make($order))->values());
    }

    public function showMine(Request $request, string $order): JsonResponse
    {
        return $this->respondToCustomer($this->own($request, $order));
    }

    public function cancelMine(Request $request, string $order): JsonResponse
    {
        return $this->respondToCustomer($this->orders->cancel($this->own($request, $order), $request->user()));
    }

    /**
     * El comprador va a pagar en línea: devuelve a dónde enviarlo. La orden
     * sigue pendiente hasta que la pasarela confirme el pago.
     */
    public function pay(Request $request, string $order): JsonResponse
    {
        $data = $request->validate([
            'gateway' => ['required', Rule::in($this->online->codes())],
            // A dónde vuelve el comprador: la página de su orden, en esta misma organización
            'return_url' => ['required', 'url:http,https', 'max:500'],
        ]);

        $host = $request->attributes->get('tenant')->slug.'.'.config('tenancy.central_domain');

        if (parse_url($data['return_url'], PHP_URL_HOST) !== $host) {
            throw new ApiException(ErrorCode::ValidationError, 'Los datos no son válidos.', 422, ['return_url' => ['La dirección de retorno no es de esta academia.']]);
        }

        $attempt = $this->online->start($this->own($request, $order), $data['gateway'], [
            'return' => $data['return_url'],
            // Los avisos solo llegan a una dirección pública con HTTPS
            'webhook' => str_starts_with($data['return_url'], 'https://') ? "https://{$host}/api/v1/payments/webhooks/".strtolower($data['gateway']) : null,
        ]);

        return ApiResponse::success(['checkout_url' => $attempt->checkout_url], null, 201);
    }

    /**
     * El comprador volvió de la pasarela: se le pregunta a ella si pagó.
     */
    public function syncPayment(Request $request, string $order): JsonResponse
    {
        return $this->respondToCustomer($this->online->sync($this->own($request, $order)));
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(Order::STATUSES)],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $base = $this->search($filters['search'] ?? null);

        // Cuántas hay en cada estado con la búsqueda actual: alimenta las tarjetas que filtran
        $counts = StateCounts::of($base, Order::STATUSES, fn (Builder $q, string $status) => $q->inState($status));

        $orders = $base
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->inState($status))
            ->orderByDesc('number')
            ->paginate($filters['per_page'] ?? 50);

        return ApiResponse::paginated($orders, fn (Order $order) => OrderPresenter::make($order), ['counts' => $counts]);
    }

    /**
     * Las órdenes del listado, con la misma búsqueda y estado, en Excel.
     */
    public function export(Request $request, TenantSettingsService $settings): BinaryFileResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(Order::STATUSES)],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $timezone = $settings->timezone();
        $query = $this->search($filters['search'] ?? null)
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->inState($status))
            ->orderByDesc('number');

        return ExcelExport::download(
            'ordenes',
            $query,
            ['N.º', 'Estado', 'Fecha', 'Comprador', 'Correo', 'Empresa', 'Detalle', 'Moneda', 'Subtotal', 'Descuento', 'Cupón', 'Total', 'Fecha de pago', 'Medio de pago', 'Cobrado por', 'N.º de operación', 'Reembolsada el', 'Motivo del reembolso'],
            fn (Order $order) => [
                $order->number,
                self::STATUS_LABELS[$order->state()] ?? $order->state(),
                ExcelExport::dateTime($order->created_at, $timezone),
                $order->customer ? trim("{$order->customer->first_name} {$order->customer->last_name}") : null,
                $order->customer?->email,
                $order->company?->name,
                $order->package_title ?? $order->items->pluck('title')->implode(', '),
                $order->currency->code,
                (float) $order->subtotal_amount,
                (float) $order->discount_amount,
                $order->coupon_code,
                (float) $order->total_amount,
                ExcelExport::dateTime($order->payment?->paid_at, $timezone),
                $order->payment ? (self::METHOD_LABELS[$order->payment->method] ?? $order->payment->method) : null,
                $order->payment ? (self::GATEWAY_LABELS[$order->payment->gateway] ?? $order->payment->gateway) : null,
                $order->payment?->reference,
                ExcelExport::dateTime($order->payment?->refunded_at, $timezone),
                $order->payment?->refund_reason,
            ]
        );
    }

    /**
     * Catálogos de la pantalla de órdenes.
     */
    public function options(): JsonResponse
    {
        return ApiResponse::success([
            'statuses' => Order::STATUSES,
            'payment_methods' => Payment::METHODS,
        ]);
    }

    public function show(Order $order): JsonResponse
    {
        return ApiResponse::success(OrderPresenter::make($order->load(self::RELATIONS)));
    }

    /**
     * El personal confirma que recibió el pago: la orden queda pagada y el comprador, matriculado.
     */
    public function confirmPayment(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'method' => ['required', Rule::in(Payment::METHODS)],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
            'paid_at' => ['nullable', 'date', 'before_or_equal:now'],
        ]);

        $order = $this->orders->applyPayment($order, $request->user(), [
            'paid_at' => isset($data['paid_at']) ? Carbon::parse($data['paid_at']) : null,
        ] + $data);

        return ApiResponse::success(OrderPresenter::make($order->load(self::RELATIONS)));
    }

    /**
     * Reembolso total: si el pago fue en línea se devuelve en la pasarela;
     * si fue por fuera, se registra que el dinero ya se devolvió. El
     * comprador pierde la matrícula que le dio la compra.
     */
    public function refund(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            // Solo para pagos por fuera: cómo se devolvió el dinero
            'reference' => ['nullable', 'string', 'max:120'],
        ]);

        $order = $this->online->refund($order, $request->user(), $data['reason'], $data['reference'] ?? null);

        return ApiResponse::success(OrderPresenter::make($order->load(self::RELATIONS)));
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        $order = $this->orders->cancel($order, $request->user());

        return ApiResponse::success(OrderPresenter::make($order->load(self::RELATIONS)));
    }

    /**
     * Una orden ajena no existe para el comprador.
     */
    private function own(Request $request, string $order): Order
    {
        return Order::query()->where('customer_id', $request->user()->id)->findOrFail($order);
    }

    /**
     * Mientras la orden espera su pago, el comprador ve cómo pagarla.
     */
    /**
     * Órdenes que coinciden con lo buscado: por número, comprador, empresa o curso.
     *
     * @return Builder<Order>
     */
    private function search(?string $search): Builder
    {
        return Order::query()
            ->with(self::RELATIONS)
            ->when($search, function (Builder $q, string $search) {
                $like = '%'.mb_strtolower($search).'%';
                $number = ctype_digit($search) ? (int) $search : null;

                $q->where(fn (Builder $match) => $match
                    ->when($number, fn (Builder $byNumber) => $byNumber->where('number', $number))
                    ->orWhereHas('customer', fn (Builder $customer) => $customer
                        ->whereRaw('lower(email) like ?', [$like])
                        ->orWhereRaw('lower(first_name) like ?', [$like])
                        ->orWhereRaw('lower(last_name) like ?', [$like]))
                    ->orWhereHas('company', fn (Builder $company) => $company->whereRaw('lower(name) like ?', [$like]))
                    ->orWhereHas('items', fn (Builder $items) => $items->whereRaw('lower(title) like ?', [$like])));
            });
    }

    private function respondToCustomer(Order $order, int $status = 200): JsonResponse
    {
        $order->load(self::RELATIONS);

        return ApiResponse::success(
            OrderPresenter::make($order) + [
                'payment_instructions' => $order->isPayable() ? $this->gateway->instructions($order) : null,
                // Pasarelas con las que puede pagarla en línea ahora mismo
                'online_gateways' => $this->online->availableFor($order),
            ],
            null,
            $status
        );
    }
}
