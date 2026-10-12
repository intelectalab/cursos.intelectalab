<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Sales\Application\OrderService;
use App\Modules\Sales\Application\Payments\Online\GatewayErrors;
use App\Modules\Sales\Application\Payments\Online\OnlinePaymentService;
use App\Modules\Sales\Application\Payments\PaymentGateway;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\OrderInstallment;
use App\Modules\Sales\Domain\Models\Package;
use App\Modules\Sales\Domain\Models\Payment;
use App\Modules\Sales\Domain\Models\PaymentAttempt;
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
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Órdenes de compra: el comprador crea y consulta las suyas (/me/orders);
 * el personal ve todas, confirma los pagos recibidos y cancela pendientes.
 */
class OrderController extends Controller
{
    private const RELATIONS = ['customer', 'company', 'currency', 'items', 'payment.confirmer', 'payment.refunder', 'attempts', 'installments.payment.confirmer'];

    private const STATUS_LABELS = ['PENDING' => 'Pendiente de pago', 'PARTIAL' => 'En cuotas', 'PAID' => 'Pagada', 'CANCELLED' => 'Cancelada', 'EXPIRED' => 'Vencida', 'REFUNDED' => 'Reembolsada'];

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

        $attempt = GatewayErrors::forPayer(fn () => $this->online->start($this->own($request, $order), $data['gateway'], [
            'return' => $data['return_url'],
            // Los avisos solo llegan a una dirección pública con HTTPS
            'webhook' => str_starts_with($data['return_url'], 'https://') ? "https://{$host}/api/v1/payments/webhooks/".strtolower($data['gateway']) : null,
        ]), 'No pudimos abrir el pago en este momento. No se te cobró nada. Inténtalo de nuevo en unos minutos; si sigue igual, avisa a la academia e indica el código de este mensaje.');

        return ApiResponse::success(['checkout_url' => $attempt->checkout_url], null, 201);
    }

    /**
     * El comprador volvió de la pasarela: se le pregunta a ella si pagó.
     */
    public function syncPayment(Request $request, string $order): JsonResponse
    {
        return $this->respondToCustomer(GatewayErrors::forPayer(fn () => $this->online->sync($this->own($request, $order)), 'No pudimos comprobar tu pago en este momento. Si ya pagaste, no vuelvas a pagar: inténtalo de nuevo en unos minutos o avisa a la academia con el código de este mensaje.'));
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
            ['N.º', 'Estado', 'Fecha', 'Comprador', 'Correo', 'Empresa', 'Detalle', 'Moneda', 'Subtotal', 'Descuento', 'Cupón', 'Total', 'Fecha de pago', 'Medio de pago', 'Cobrado por', 'N.º de operación', 'Reembolsada el', 'Motivo del reembolso', 'Cobrado', 'Por cobrar en cuotas'],
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
                ExcelExport::dateTime($order->payment?->refunded_at ?? $order->refunded_at, $timezone),
                $order->payment?->refund_reason ?? $order->installments->pluck('payment.refund_reason')->filter()->first(),
                (float) OrderPresenter::make($order)['collected']['amount'],
                // Solo debe la orden que ya recibió un pago
                $order->status === Order::STATUS_PARTIAL ? (float) $order->installments->where('status', OrderInstallment::STATUS_PENDING)->sum('amount') : 0.0,
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
        $order->load(self::RELATIONS);

        // Solo en el detalle: avisa si la orden se cobró con otra cuenta de la pasarela que la conectada hoy
        return ApiResponse::success(OrderPresenter::make($order) + ['account_warning' => $this->online->accountWarning($order)]);
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
     * Arma el plan de pagos de una orden pendiente: en cuántas cuotas, de
     * cuánto y hasta cuándo. Pagada la primera, el comprador entra al curso.
     */
    public function setInstallments(Request $request, Order $order): JsonResponse
    {
        $today = OrderPresenter::today();
        $data = $request->validate([
            'installments' => ['required', 'array', 'min:'.OrderInstallment::MIN, 'max:'.OrderInstallment::MAX],
            'installments.*.amount' => ['required', 'numeric', 'min:0.01', 'max:9999999'],
            'installments.*.due_date' => ['required', 'date_format:Y-m-d', "after_or_equal:{$today}"],
        ], [
            'installments.min' => 'Un plan de pagos tiene al menos '.OrderInstallment::MIN.' cuotas.',
            'installments.max' => 'Un plan de pagos tiene como máximo '.OrderInstallment::MAX.' cuotas.',
            'installments.*.due_date.after_or_equal' => 'La fecha de una cuota no puede ser anterior a hoy.',
        ]);

        // Cada cuota vence después de la anterior
        $dates = array_column($data['installments'], 'due_date');
        $sorted = $dates;
        sort($sorted);

        if ($dates !== $sorted || count(array_unique($dates)) !== count($dates)) {
            throw ValidationException::withMessages(['installments' => 'Cada cuota debe vencer después de la anterior.']);
        }

        $order = $this->orders->setInstallments($order, $request->user(), $data['installments']);

        return ApiResponse::success(OrderPresenter::make($order->load(self::RELATIONS)));
    }

    /**
     * Quita el plan de pagos de una orden que todavía no recibió ningún pago.
     */
    public function removeInstallments(Request $request, Order $order): JsonResponse
    {
        $order = $this->orders->removeInstallments($order, $request->user());

        return ApiResponse::success(OrderPresenter::make($order->load(self::RELATIONS)));
    }

    /**
     * Cuotas por cobrar de toda la organización, las vencidas primero.
     */
    public function installments(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'filter' => ['nullable', Rule::in(['OVERDUE', 'UPCOMING'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $today = OrderPresenter::today();
        $base = fn () => OrderInstallment::query()
            ->where('status', OrderInstallment::STATUS_PENDING)
            // Solo cuentan las órdenes que ya recibieron un pago: una pendiente todavía no debe nada, puede vencer
            ->whereIn('order_id', Order::query()->where('status', Order::STATUS_PARTIAL)->select('id'));

        $installments = $base()
            ->with(['order.customer', 'order.currency', 'order.items'])
            ->when(($filters['filter'] ?? null) === 'OVERDUE', fn (Builder $q) => $q->where('due_date', '<', $today))
            ->when(($filters['filter'] ?? null) === 'UPCOMING', fn (Builder $q) => $q->where('due_date', '>=', $today))
            ->orderBy('due_date')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 50);

        return ApiResponse::paginated($installments, fn (OrderInstallment $installment) => self::presentDue($installment, $today), [
            'counts' => [
                'ALL' => $base()->count(),
                'OVERDUE' => $base()->where('due_date', '<', $today)->count(),
                'UPCOMING' => $base()->where('due_date', '>=', $today)->count(),
            ],
            'amounts' => [
                'pending' => number_format((float) $base()->sum('amount'), 2, '.', ''),
                'overdue' => number_format((float) $base()->where('due_date', '<', $today)->sum('amount'), 2, '.', ''),
            ],
            'currency' => app(TenantSettingsService::class)->currency(),
            'today' => $today,
        ]);
    }

    /**
     * Cuotas vencidas, para avisar al personal apenas entra. Pocas y ligeras:
     * se consulta a menudo.
     */
    public function installmentAlerts(): JsonResponse
    {
        $today = OrderPresenter::today();

        $overdue = OrderInstallment::query()
            ->where('status', OrderInstallment::STATUS_PENDING)
            ->where('due_date', '<', $today)
            ->whereIn('order_id', Order::query()->where('status', Order::STATUS_PARTIAL)->select('id'))
            ->with(['order.customer', 'order.currency', 'order.items'])
            ->orderBy('due_date')
            ->orderBy('id')
            ->limit(20)
            ->get();

        return ApiResponse::success($overdue->map(fn (OrderInstallment $installment) => self::presentDue($installment, $today))->values());
    }

    /**
     * Una cuota por cobrar, con lo necesario para ubicar a quien debe.
     *
     * @return array<string, mixed>
     */
    private static function presentDue(OrderInstallment $installment, string $today): array
    {
        $order = $installment->order;

        return OrderPresenter::installment($installment, $today) + [
            'days_overdue' => $installment->isOverdue($today) ? (int) $installment->due_date->diffInDays($today) : 0,
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'currency' => $order->currency->code,
                'title' => $order->package_title ?? $order->items->pluck('title')->implode(', '),
                'customer' => $order->customer ? [
                    'id' => $order->customer->id,
                    'name' => trim("{$order->customer->first_name} {$order->customer->last_name}"),
                    'email' => $order->customer->email,
                    'phone' => $order->customer->phone,
                ] : null,
            ],
        ];
    }

    /**
     * Cobros que una pasarela hizo y no se aplicaron a su orden: esperan que
     * una persona los resuelva.
     */
    public function paymentReviews(): JsonResponse
    {
        $attempts = PaymentAttempt::query()
            ->where('status', PaymentAttempt::STATUS_NEEDS_REVIEW)
            ->with(['order.customer', 'order.currency', 'order.items'])
            ->orderBy('updated_at')
            ->orderBy('id')
            ->limit(100)
            ->get();

        return ApiResponse::success($attempts->map(fn (PaymentAttempt $attempt) => OrderPresenter::review($attempt) + [
            'order' => [
                'id' => $attempt->order->id,
                'number' => $attempt->order->number,
                'status' => $attempt->order->state(),
                'title' => $attempt->order->package_title ?? $attempt->order->items->pluck('title')->implode(', '),
                'customer' => $attempt->order->customer ? [
                    'name' => trim("{$attempt->order->customer->first_name} {$attempt->order->customer->last_name}"),
                    'email' => $attempt->order->customer->email,
                ] : null,
            ],
        ])->values());
    }

    /**
     * Una persona ya resolvió el cobro en revisión: deja de avisar.
     */
    public function resolvePaymentReview(Request $request, string $attempt): JsonResponse
    {
        $data = $request->validate([
            'note' => ['required', 'string', 'min:5', 'max:300'],
        ]);

        $attempt = $this->online->resolveReview(PaymentAttempt::query()->with('order')->findOrFail($attempt), $request->user(), $data['note']);

        return ApiResponse::success(OrderPresenter::make($attempt->order->load(self::RELATIONS)));
    }

    /**
     * El personal vuelve a preguntar a las pasarelas por una orden: aplica un
     * pago que no se había registrado y deja en revisión lo que no cuadra.
     */
    public function recheckPayment(Order $order): JsonResponse
    {
        $order = $this->online->recheck($order)->load(self::RELATIONS);

        return ApiResponse::success(OrderPresenter::make($order) + ['account_warning' => $this->online->accountWarning($order)]);
    }

    /**
     * Reembolso total: lo cobrado en línea se devuelve en la pasarela; lo
     * recibido por fuera se registra como ya devuelto. En una venta en cuotas
     * se devuelve lo pagado hasta ahora. El comprador pierde la matrícula que
     * le dio la compra.
     */
    public function refund(Request $request, Order $order): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            // Cómo se devolvió el dinero que no devuelve la pasarela
            'reference' => ['nullable', 'string', 'max:120'],
            // El pago fue en línea, pero la pasarela ya no deja devolverlo: se devolvió por otro medio
            'outside_gateway' => ['nullable', 'boolean'],
        ]);

        $outside = (bool) ($data['outside_gateway'] ?? false);

        if ($outside && blank($data['reference'] ?? null)) {
            throw ValidationException::withMessages(['reference' => 'Indica cómo devolviste el dinero: queda como constancia del reembolso.']);
        }

        $order = $this->online->refund($order, $request->user(), $data['reason'], $data['reference'] ?? null, $outside);

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
