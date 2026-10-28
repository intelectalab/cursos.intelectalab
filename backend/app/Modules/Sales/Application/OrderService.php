<?php

namespace App\Modules\Sales\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Enrollments\Application\EnrollmentQuota;
use App\Modules\Enrollments\Application\EnrollmentService;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Promotions\Application\CouponService;
use App\Modules\Promotions\Domain\Models\Promotion;
use App\Modules\Sales\Application\Payments\PaymentGateway;
use App\Modules\Sales\Domain\Events\OrderCancelled;
use App\Modules\Sales\Domain\Events\OrderPaid;
use App\Modules\Sales\Domain\Models\Currency;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\OrderInstallment;
use App\Modules\Sales\Domain\Models\OrderItem;
use App\Modules\Sales\Domain\Models\Package;
use App\Modules\Sales\Domain\Models\Payment;
use App\Modules\Tenancy\Application\TenantNotifier;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Venta a personas (RF-B2C-001, FP-02): checkout → validar precio → orden →
 * pago → matrícula. Una orden lleva un curso; los ítems admiten más para
 * cuando lleguen el carrito y los paquetes (S11).
 */
class OrderService
{
    /** Venta asistida: ya pagó */
    public const SALE_PAID = 'PAID';

    /** Venta asistida: pagará después, hasta la fecha que elige el personal */
    public const SALE_LATER = 'LATER';

    /** Venta asistida: en cuotas */
    public const SALE_INSTALLMENTS = 'INSTALLMENTS';

    public const SALE_MODES = [self::SALE_PAID, self::SALE_LATER, self::SALE_INSTALLMENTS];

    public function __construct(
        private readonly TenantNotifier $notifier,
        private readonly EnrollmentService $enrollments,
        private readonly EnrollmentQuota $quota,
        private readonly TenantSettingsService $settings,
        private readonly PaymentGateway $gateway,
        private readonly CouponService $coupons,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Crea la orden de compra de un curso. El precio se toma del servidor
     * (RN-008): si no coincide con el que vio el comprador, se rechaza para
     * que lo confirme. Un curso gratuito queda pagado y matriculado al instante.
     *
     * @param  string|null  $expectedTotal  total que el comprador vio en pantalla, ya con el descuento
     * @param  string|null  $couponCode  cupón que quiere usar
     */
    public function place(User $customer, Course $course, ?string $expectedTotal = null, ?string $couponCode = null): Order
    {
        $this->assertSellable($course, $customer);

        return $this->checkout($customer, Promotion::PRODUCT_COURSE, $course->id, $course->title, $course->price, $expectedTotal, $couponCode, self::courseItems($course));
    }

    /**
     * Venta asistida: el personal registra la compra a nombre de un alumno,
     * por el mismo camino que una compra en línea (precio, cupón, cupo,
     * avisos). Lleva un cupón o un descuento manual con su motivo, y se cobra
     * de una de tres formas:
     *
     * - PAID: ya pagó (efectivo, transferencia, Yape, POS…): queda matriculado.
     * - LATER: pagará después, hasta la fecha que elige el personal; recibe las instrucciones.
     * - INSTALLMENTS: en cuotas; si ya pagó la primera, entra al curso.
     *
     * @param  array{coupon_code?: ?string, discount?: ?array{amount: string, reason: string}, mode: string, expires_at?: ?CarbonInterface, payment?: ?array{method: string, reference?: ?string, notes?: ?string}, installments?: ?list<array{amount: string|float|int, due_date: string}>, expected_total?: ?string}  $sale
     */
    public function placeAssisted(User $seller, User $customer, Course|Package $product, array $sale): Order
    {
        return DB::transaction(function () use ($seller, $customer, $product, $sale) {
            $assisted = [
                'seller' => $seller,
                'discount' => $sale['discount'] ?? null,
                'expires_at' => $sale['mode'] === self::SALE_LATER ? ($sale['expires_at'] ?? null) : null,
            ];

            if ($product instanceof Course) {
                $this->assertSellable($product, $customer);
                $order = $this->checkout($customer, Promotion::PRODUCT_COURSE, $product->id, $product->title, $product->price, $sale['expected_total'] ?? null, $sale['coupon_code'] ?? null, self::courseItems($product), [], $assisted);
            } else {
                $order = $this->packageOrder($customer, $product, $sale['expected_total'] ?? null, $sale['coupon_code'] ?? null, $assisted);
            }

            // Gratis (por ejemplo, con el descuento del total): ya quedó matriculado
            if ($order->status !== Order::STATUS_PENDING) {
                return $order;
            }

            if ($sale['mode'] === self::SALE_INSTALLMENTS) {
                $order = $this->setInstallments($order, $seller, $sale['installments'] ?? []);
            }

            if ($sale['mode'] !== self::SALE_LATER && ! empty($sale['payment'])) {
                return $this->applyPayment($order, $seller, $sale['payment']);
            }

            // Queda esperando el pago: el alumno recibe la orden con el plazo y cómo pagar
            $this->notifyReceived($order->refresh(), $customer, $order->package_title ?? $order->items()->value('title'));

            return $order;
        });
    }

    /**
     * Crea la orden de un paquete (FP-03): una compra, varias matrículas. El
     * precio del paquete se reparte entre sus cursos en proporción a su
     * precio de lista, para que la orden sume exacto. Quien ya tiene alguno
     * de los cursos solo queda matriculado en los que le faltan.
     */
    public function placePackage(User $customer, Package $package, ?string $expectedTotal = null, ?string $couponCode = null): Order
    {
        return $this->packageOrder($customer, $package, $expectedTotal, $couponCode);
    }

    /**
     * @param  array{seller: User, discount: ?array{amount: string, reason: string}, expires_at: ?CarbonInterface}|null  $assisted
     */
    private function packageOrder(User $customer, Package $package, ?string $expectedTotal, ?string $couponCode, ?array $assisted = null): Order
    {
        $package->loadMissing('courses.currentVersion');

        if (! $package->isForSale()) {
            throw new ApiException(ErrorCode::CourseNotForSale, 'Este paquete no está en venta.', 409);
        }

        $this->assertStudent($customer);

        $courses = $package->courses->values();
        $listTotal = (float) $package->listPrice();
        $remaining = (float) $package->price;
        $items = [];

        foreach ($courses as $index => $course) {
            // El último curso absorbe el redondeo
            $share = $index === $courses->count() - 1
                ? $remaining
                : round((float) $package->price * ($listTotal > 0 ? (float) $course->price / $listTotal : 1 / $courses->count()), 2);
            $remaining = round($remaining - $share, 2);
            $amount = self::money((string) $share);

            $items[] = [
                'course_id' => $course->id,
                'title' => $course->currentVersion->snapshot['title'] ?? $course->title,
                'unit_price' => $amount,
                'quantity' => 1,
                'total_amount' => $amount,
            ];
        }

        return $this->checkout($customer, Promotion::PRODUCT_PACKAGE, $package->id, $package->title, $package->price, $expectedTotal, $couponCode, $items, [
            'package_id' => $package->id,
            'package_title' => $package->title,
        ], $assisted);
    }

    /**
     * @return list<array{course_id: string, title: string, unit_price: string, quantity: int, total_amount: string}>
     */
    private static function courseItems(Course $course): array
    {
        $title = $course->currentVersion->snapshot['title'] ?? $course->title;

        return [['course_id' => $course->id, 'title' => $title, 'unit_price' => $course->price, 'quantity' => 1, 'total_amount' => $course->price]];
    }

    private function assertSellable(Course $course, User $customer): void
    {
        if (! $course->isForSale()) {
            throw new ApiException(ErrorCode::CourseNotForSale, 'Este curso no está en venta.', 409);
        }

        $this->assertStudent($customer);
    }

    private function assertStudent(User $customer): void
    {
        if (! $customer->hasPermission(EnrollmentService::STUDENT_PERMISSION)) {
            throw new ApiException(ErrorCode::Forbidden, 'Solo una cuenta de alumno puede comprar cursos.', 403);
        }
    }

    /**
     * Crea la orden de una compra personal. El precio y el descuento salen
     * del servidor (RN-008): si el total no coincide con el que vio el
     * comprador, se rechaza para que lo confirme. Con total 0 queda pagada y
     * matriculada al instante.
     *
     * @param  string  $productType  Promotion::PRODUCT_COURSE o PRODUCT_PACKAGE
     * @param  list<array{course_id: string, title: string, unit_price: string, quantity: int, total_amount: string}>  $items
     * @param  array<string, mixed>  $attributes  columnas adicionales de la orden
     * @param  array{seller: User, discount: ?array{amount: string, reason: string}, expires_at: ?CarbonInterface}|null  $assisted  venta registrada por el personal
     */
    private function checkout(
        User $customer,
        string $productType,
        string $productId,
        string $productName,
        string $subtotal,
        ?string $expectedTotal,
        ?string $couponCode,
        array $items,
        array $attributes = [],
        ?array $assisted = null,
    ): Order {
        $currency = Currency::byCode($this->settings->currency());

        return DB::transaction(function () use ($customer, $productType, $productId, $productName, $subtotal, $expectedTotal, $couponCode, $items, $attributes, $assisted, $currency) {
            $this->lockOrderNumbers();

            $this->assertCanBuy($customer, $productType, $productId, array_column($items, 'course_id'), $assisted !== null);

            // Sin cupo de alumnos no se vende: la orden pagada sí se respeta, por eso se revisa aquí
            if (! $this->quota->hasRoomFor([$customer->id])) {
                throw new ApiException(
                    ErrorCode::StudentLimitReached,
                    $assisted !== null
                        ? 'La academia llegó al tope de alumnos de su plan: no se pueden sumar alumnos nuevos.'
                        : 'Por ahora la academia no está recibiendo nuevos alumnos. Comunícate con ella para inscribirte.',
                    409
                );
            }

            // Un solo cupón por orden; el servidor decide si aplica y cuánto descuenta
            $applied = $couponCode !== null && trim($couponCode) !== ''
                ? $this->coupons->evaluate($couponCode, $customer, $productType, $productId, $subtotal, lock: true)
                : null;

            $discount = $applied['discount'] ?? '0.00';

            // Descuento manual del personal, en lugar de un cupón: no puede pasar del precio
            $manual = $assisted['discount'] ?? null;
            if ($manual !== null) {
                if ($applied !== null) {
                    throw new ApiException(ErrorCode::ValidationError, 'Usa un cupón o un descuento manual, no los dos.', 422, ['discount' => ['Los descuentos no se acumulan.']]);
                }
                if ((float) $manual['amount'] > (float) $subtotal) {
                    throw new ApiException(ErrorCode::ValidationError, 'El descuento no puede ser mayor que el precio.', 422, ['discount.amount' => ['El precio es '.self::money($subtotal).'.']]);
                }
                $discount = self::money((string) $manual['amount']);
            }
            $total = self::money((string) ((float) $subtotal - (float) $discount));

            if ($expectedTotal !== null && self::money($expectedTotal) !== $total) {
                throw new ApiException(
                    ErrorCode::PriceChanged,
                    'El total cambió. Revisa el nuevo importe antes de continuar.',
                    409,
                    ['total' => $total, 'subtotal' => self::money($subtotal), 'currency' => $currency->code]
                );
            }

            $order = Order::create($attributes + [
                'number' => (int) Order::query()->max('number') + 1,
                'customer_id' => $customer->id,
                'currency_id' => $currency->id,
                'subtotal_amount' => $subtotal,
                'discount_amount' => $discount,
                'total_amount' => $total,
                'coupon_code' => $applied ? $applied['coupon']->code : null,
                'status' => Order::STATUS_PENDING,
                'expires_at' => $assisted['expires_at'] ?? now()->addHours($this->settings->orderExpirationHours()),
                'sold_by' => $assisted['seller']->id ?? null,
                'discount_reason' => $manual['reason'] ?? null,
            ]);

            $order->items()->createMany($items);

            if ($applied) {
                $this->coupons->redeem($applied, $order, $customer);
            }

            $this->audit->record('orders.created', $order, null, [
                'number' => $order->number,
                'customer' => $customer->email,
                'product' => $productName,
                'total' => $order->total_amount,
                'currency' => $currency->code,
            ] + ($applied ? ['coupon' => $applied['coupon']->code, 'discount' => $discount] : [])
              + ($manual ? ['discount' => $discount, 'discount_reason' => $manual['reason']] : [])
              + ($assisted ? ['sold_by' => $assisted['seller']->email] : []));

            if ($total === self::money('0')) {
                $this->settle($order, $assisted['seller'] ?? $customer);
                $this->notifier->notify($customer, 'inscripcion_gratuita', ['curso' => $productName], '/my-courses');
            } elseif ($assisted === null) {
                // En la venta asistida el aviso sale después, según cómo se cobra
                $this->notifyReceived($order, $customer, $productName);
            }

            return $order;
        });
    }

    /**
     * El comprador recibe su orden pendiente: importe, plazo y dónde verla.
     */
    private function notifyReceived(Order $order, User $customer, string $productName): void
    {
        $order->loadMissing('currency');

        $this->notifier->notify($customer, 'orden_recibida', [
            'orden' => self::code($order),
            'curso' => $productName,
            'importe' => TenantNotifier::money($order->currency->code, $order->total_amount),
            'fecha_limite' => $order->expires_at->timezone($this->settings->timezone())->format('d/m/Y, H:i'),
        ], "/my-orders/{$order->id}");
    }

    /**
     * Crea la orden de una venta corporativa (FP-04): la compra una empresa,
     * con los cursos, cantidades y precios ya pactados en su cotización
     * (RN-011). No vence sola: la empresa paga según lo acordado.
     *
     * @param  list<array{course_id: string, title: string, unit_price: string, quantity: int, total_amount: string}>  $items
     */
    public function placeForCompany(string $companyId, string $currencyId, array $items): Order
    {
        return DB::transaction(function () use ($companyId, $currencyId, $items) {
            $this->lockOrderNumbers();

            $total = self::money((string) array_sum(array_map(fn (array $item) => (float) $item['total_amount'], $items)));

            $order = Order::create([
                'number' => (int) Order::query()->max('number') + 1,
                'company_id' => $companyId,
                'currency_id' => $currencyId,
                'subtotal_amount' => $total,
                'discount_amount' => 0,
                'total_amount' => $total,
                'status' => Order::STATUS_PENDING,
            ]);

            $order->items()->createMany($items);

            $this->audit->record('orders.created', $order, null, [
                'number' => $order->number,
                'company' => $order->company->name,
                'total' => $order->total_amount,
            ]);

            return $order;
        });
    }

    /**
     * Aplica el pago de una orden pendiente: la deja pagada y matricula al
     * comprador. Es el punto de entrada de cualquier pasarela. Idempotente
     * (RN-009): una orden que ya no está pendiente no recibe otro pago.
     *
     * Con `gateway` el pago lo cobró una pasarela en línea: nadie lo confirma
     * a mano y vale aunque el plazo de la orden haya pasado entretanto,
     * porque el comprador ya pagó.
     *
     * @param  array{method: string, reference?: ?string, notes?: ?string, paid_at?: ?CarbonInterface, gateway?: string}  $data
     */
    public function applyPayment(Order $order, User $actor, array $data): Order
    {
        return DB::transaction(function () use ($order, $actor, $data) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);
            $online = isset($data['gateway']);
            // Con plan de pagos, cada pago cubre la siguiente cuota
            $installment = $order->installments()->where('status', OrderInstallment::STATUS_PENDING)->lockForUpdate()->first();

            if ($order->status === Order::STATUS_PARTIAL) {
                if ($installment === null) {
                    throw new ApiException(ErrorCode::OrderNotPending, 'La orden ya está pagada.', 409, ['status' => $order->status]);
                }
            } elseif (! ($online && $order->status === Order::STATUS_PENDING)) {
                $this->assertPending($order);
            }

            $payment = Payment::create([
                'order_id' => $order->id,
                'installment_id' => $installment?->id,
                'currency_id' => $order->currency_id,
                'amount' => $installment?->amount ?? $order->total_amount,
                'method' => $data['method'],
                'gateway' => $data['gateway'] ?? $this->gateway->code(),
                'status' => Payment::STATUS_CONFIRMED,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'confirmed_by' => $online ? null : $actor->id,
                'paid_at' => $data['paid_at'] ?? now(),
            ]);

            $this->audit->record('payments.confirmed', $payment, null, [
                'order' => $order->number,
                'gateway' => $payment->gateway,
                'amount' => $payment->amount,
                'method' => $payment->method,
                'reference' => $payment->reference,
                'installment' => $installment?->number,
            ]);

            $order->loadMissing(['customer', 'currency']);
            $money = TenantNotifier::money($order->currency->code, $payment->amount);

            if ($installment === null) {
                $this->settle($order, $actor);
                $this->notifier->notify($order->customer, 'pago_confirmado', ['orden' => self::code($order), 'importe' => $money], '/my-courses');

                return $order;
            }

            $installment->update(['status' => OrderInstallment::STATUS_PAID, 'paid_at' => $payment->paid_at]);

            // La primera cuota da el acceso; la última deja la orden pagada
            if ($order->status === Order::STATUS_PENDING) {
                $order->update(['status' => Order::STATUS_PARTIAL, 'expires_at' => null]);
                $this->enroll($order, $actor);
                $this->audit->record('orders.partially_paid', $order, ['status' => Order::STATUS_PENDING], ['status' => Order::STATUS_PARTIAL, 'number' => $order->number, 'installment' => $installment->number]);
            }

            $next = $order->installments()->where('status', OrderInstallment::STATUS_PENDING)->first();

            if ($next === null) {
                $this->markPaid($order, $actor, Order::STATUS_PARTIAL);
            }

            // La primera cuota da el acceso y la última cierra la compra: cada una tiene su propio correo
            $this->notifier->notify($order->customer, match (true) {
                $next === null => 'ultima_cuota_pagada',
                $installment->number === 1 => 'primera_cuota_pagada',
                default => 'cuota_pagada',
            }, [
                'orden' => self::code($order),
                'cuota' => $installment->number,
                'importe' => $money,
                'siguiente_importe' => $next ? TenantNotifier::money($order->currency->code, $next->amount) : null,
                'siguiente_vencimiento' => $next?->due_date->format('d/m/Y'),
            ], "/my-orders/{$order->id}");

            return $order;
        });
    }

    /**
     * Deja reembolsada una orden: el dinero ya se devolvió (por la pasarela o
     * por fuera). Las matrículas que dio la compra se cancelan. En una venta
     * en cuotas se devuelve lo cobrado hasta ahora y lo que faltaba ya no se
     * debe. Solo compras personales con importe: una venta a empresa o una
     * inscripción gratuita no pasan por aquí.
     *
     * @param  string|null  $reference  cómo se devolvió el dinero de los pagos que aún no figuran devueltos
     */
    public function markRefunded(Order $order, User $actor, string $reason, ?string $reference): Order
    {
        return DB::transaction(function () use ($order, $actor, $reason, $reference) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            $this->assertRefundable($order);

            $from = $order->status;

            $order->payments()->where('status', Payment::STATUS_CONFIRMED)->get()
                ->each(fn (Payment $payment) => $this->markPaymentRefunded($payment, $actor, $reason, $reference));

            $order->update(['status' => Order::STATUS_REFUNDED, 'refunded_at' => now()]);

            // Devuelto el dinero, se retira el acceso que dio esta compra
            $order->items()->with('enrollment.student')->get()->each(function (OrderItem $item) {
                if ($item->enrollment?->isActive()) {
                    $this->enrollments->cancel($item->enrollment);
                }
            });

            $refunded = $order->payments()->where('status', Payment::STATUS_REFUNDED)->get();

            $this->audit->record('orders.refunded', $order, ['status' => $from], [
                'status' => Order::STATUS_REFUNDED,
                'number' => $order->number,
                'total' => $order->total_amount,
                'refunded' => self::money((string) $refunded->sum('amount')),
                'payments' => $refunded->count(),
                'gateway' => $refunded->pluck('gateway')->unique()->implode(', '),
                'reason' => $reason,
                'reference' => $refunded->pluck('refund_reference')->filter()->unique()->implode(', ') ?: null,
            ]);

            $order->loadMissing(['customer', 'currency']);
            $this->notifier->notify($order->customer, 'reembolso', [
                'orden' => self::code($order),
                'importe' => TenantNotifier::money($order->currency->code, $refunded->sum('amount')),
            ], "/my-orders/{$order->id}");

            return $order;
        });
    }

    /**
     * Deja devuelto un pago: el dinero ya salió. En una venta en cuotas se
     * registra pago a pago, para que una devolución que se corta a la mitad
     * se pueda retomar sin devolver dos veces.
     *
     * @param  string|null  $reference  devolución en la pasarela, o cómo se devolvió el dinero
     */
    public function markPaymentRefunded(Payment $payment, User $actor, string $reason, ?string $reference): void
    {
        $payment->update([
            'status' => Payment::STATUS_REFUNDED,
            'refunded_at' => now(),
            'refunded_by' => $actor->id,
            'refund_reason' => $reason,
            'refund_reference' => $reference,
        ]);
    }

    /**
     * Arma o reemplaza el plan de pagos de una orden que todavía no recibió
     * ningún pago. El plazo de la orden pasa a ser el de la primera cuota.
     *
     * @param  list<array{amount: string|float|int, due_date: string}>  $rows  en el orden en que se pagan
     */
    public function setInstallments(Order $order, User $actor, array $rows): Order
    {
        return DB::transaction(function () use ($order, $actor, $rows) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            $this->assertPlannable($order);

            $total = array_sum(array_map(fn (array $row) => (int) round((float) $row['amount'] * 100), $rows));

            if ($total !== (int) round((float) $order->total_amount * 100)) {
                throw new ApiException(ErrorCode::ValidationError, 'Las cuotas deben sumar el total de la orden.', 422, [
                    'installments' => ['Las cuotas suman '.self::money((string) ($total / 100)).' y la orden es de '.self::money((string) $order->total_amount).'.'],
                ]);
            }

            $order->installments()->delete();

            foreach (array_values($rows) as $index => $row) {
                $order->installments()->create([
                    'number' => $index + 1,
                    'amount' => self::money((string) $row['amount']),
                    'due_date' => $row['due_date'],
                    'status' => OrderInstallment::STATUS_PENDING,
                    'created_by' => $actor->id,
                ]);
            }

            // La orden espera su primera cuota hasta el final de ese día, en la hora de la organización
            $order->update(['expires_at' => CarbonImmutable::parse($rows[0]['due_date'], $this->settings->timezone())->endOfDay()->utc()]);

            $this->audit->record('orders.installments_set', $order, null, [
                'number' => $order->number,
                'installments' => array_map(fn (array $row) => ['amount' => self::money((string) $row['amount']), 'due_date' => $row['due_date']], $rows),
            ]);

            return $order;
        });
    }

    /**
     * Quita el plan de pagos: la orden vuelve a pagarse de una vez, con el plazo normal desde ahora.
     */
    public function removeInstallments(Order $order, User $actor): Order
    {
        return DB::transaction(function () use ($order) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            $this->assertPlannable($order);

            if ($order->installments()->exists()) {
                $order->installments()->delete();
                $order->update(['expires_at' => now()->addHours($this->settings->orderExpirationHours())]);
                $this->audit->record('orders.installments_removed', $order, null, ['number' => $order->number]);
            }

            return $order;
        });
    }

    /**
     * El plan de pagos se arma mientras la orden espera su primer pago, en compras personales con importe.
     */
    private function assertPlannable(Order $order): void
    {
        $message = match (true) {
            $order->customer_id === null => 'Las ventas a empresas no se pagan en cuotas desde aquí.',
            (float) $order->total_amount <= 0 => 'Esta inscripción es gratuita: no hay nada que pagar en cuotas.',
            $order->status === Order::STATUS_PARTIAL => 'Esta orden ya recibió un pago: su plan de pagos no se puede cambiar.',
            $order->status !== Order::STATUS_PENDING => 'Solo se arma un plan de pagos para una orden pendiente.',
            default => null,
        };

        if ($message !== null) {
            throw new ApiException(ErrorCode::OrderNotPending, $message, 409, ['status' => $order->status]);
        }
    }

    /**
     * Solo se reembolsa una compra personal con importe y con algo cobrado:
     * pagada, o en cuotas con al menos una pagada.
     */
    public function assertRefundable(Order $order): void
    {
        $message = match (true) {
            $order->status === Order::STATUS_REFUNDED => 'Esta orden ya fue reembolsada.',
            ! in_array($order->status, [Order::STATUS_PAID, Order::STATUS_PARTIAL], true) || ! $order->payments()->exists() => 'Solo se reembolsa una orden pagada.',
            $order->customer_id === null => 'Las ventas a empresas no se reembolsan desde aquí: tienen licencias entregadas y se resuelven con la empresa.',
            (float) $order->total_amount <= 0 => 'Esta inscripción fue gratuita: no hay dinero que devolver. Para retirar el acceso, cancela la matrícula.',
            default => null,
        };

        if ($message !== null) {
            throw new ApiException(ErrorCode::OrderNotRefundable, $message, 409, ['status' => $order->status]);
        }
    }

    public function cancel(Order $order, User $actor): Order
    {
        return DB::transaction(function () use ($order, $actor) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            $this->assertPending($order);

            $order->update([
                'status' => Order::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancelled_by' => $actor->id,
            ]);

            $this->audit->record('orders.cancelled', $order, ['status' => Order::STATUS_PENDING], [
                'status' => Order::STATUS_CANCELLED,
                'number' => $order->number,
            ]);

            OrderCancelled::dispatch($order);

            return $order;
        });
    }

    /**
     * Marca como vencidas las órdenes pendientes de la organización actual
     * cuyo plazo de pago ya pasó.
     *
     * @return int cantidad de órdenes vencidas
     */
    public function expireOverdue(): int
    {
        $overdue = Order::query()
            ->where('status', Order::STATUS_PENDING)
            ->where('expires_at', '<', now())
            ->get();

        foreach ($overdue as $order) {
            $order->update(['status' => Order::STATUS_EXPIRED]);

            $this->audit->record('orders.expired', $order, ['status' => Order::STATUS_PENDING], [
                'status' => Order::STATUS_EXPIRED,
                'number' => $order->number,
            ]);
        }

        return $overdue->count();
    }

    /**
     * Orden pendiente y dentro de plazo del comprador para ese curso, si existe.
     */
    public function pendingFor(User $customer, string $courseId): ?Order
    {
        return Order::query()
            ->where('customer_id', $customer->id)
            ->inState(Order::STATUS_PENDING)
            // La orden de un paquete que incluye el curso no cuenta como orden de ese curso
            ->whereNull('package_id')
            ->whereHas('items', fn ($items) => $items->where('course_id', $courseId))
            ->first();
    }

    /**
     * Orden pendiente y dentro de plazo del comprador para ese paquete, si existe.
     */
    public function pendingForPackage(User $customer, string $packageId): ?Order
    {
        return Order::query()
            ->where('customer_id', $customer->id)
            ->where('package_id', $packageId)
            ->inState(Order::STATUS_PENDING)
            ->first();
    }

    /**
     * Sin compra duplicada: ni con matrícula en el curso ni con otra orden esperando pago.
     */
    /**
     * @param  list<string>  $courseIds  cursos que entrega la compra
     * @param  bool  $assisted  la registra el personal: los mensajes hablan del alumno
     */
    private function assertCanBuy(User $customer, string $productType, string $productId, array $courseIds, bool $assisted = false): void
    {
        $who = $assisted ? 'El alumno ya tiene' : 'Ya tienes';

        // Serializa las compras simultáneas del mismo comprador
        User::query()->whereKey($customer->id)->lockForUpdate()->first();

        $enrolled = Enrollment::query()
            ->whereIn('course_id', $courseIds)
            ->where('student_id', $customer->id)
            ->where('status', Enrollment::STATUS_ACTIVE)
            ->count();

        // Un paquete se puede comprar aunque ya se tenga alguno de sus cursos; no si se tienen todos
        if ($enrolled >= count($courseIds)) {
            throw new ApiException(
                ErrorCode::AlreadyEnrolled,
                $productType === Promotion::PRODUCT_COURSE ? "{$who} una matrícula en este curso." : "{$who} todos los cursos de este paquete.",
                409
            );
        }

        $pending = $productType === Promotion::PRODUCT_COURSE
            ? $this->pendingFor($customer, $productId)
            : $this->pendingForPackage($customer, $productId);

        if ($pending) {
            throw new ApiException(
                ErrorCode::OrderAlreadyPending,
                $assisted ? 'El alumno ya tiene una orden pendiente de pago por esta compra: confírmala o cancélala desde Ventas y pagos.' : 'Ya tienes una orden pendiente de pago por esta compra.',
                409,
                ['order_id' => $pending->id]
            );
        }
    }

    private function assertPending(Order $order): void
    {
        $message = match ($order->state()) {
            Order::STATUS_PENDING => null,
            Order::STATUS_PAID => 'La orden ya está pagada.',
            Order::STATUS_PARTIAL => 'La orden ya recibió un pago y el comprador tiene acceso: no se puede cancelar.',
            Order::STATUS_CANCELLED => 'La orden fue cancelada.',
            default => 'La orden venció sin pago. El comprador debe generar una nueva.',
        };

        if ($message !== null) {
            throw new ApiException(ErrorCode::OrderNotPending, $message, 409, ['status' => $order->state()]);
        }
    }

    /**
     * Orden pagada → matrícula (US-SAL-001). Si el comprador ya fue
     * matriculado a mano entretanto, la orden queda ligada a esa matrícula.
     * En una venta corporativa no hay a quién matricular: sus licencias las
     * entrega quien escucha OrderPaid.
     */
    private function settle(Order $order, User $actor): void
    {
        $this->enroll($order, $actor);
        $this->markPaid($order, $actor, Order::STATUS_PENDING);
    }

    /**
     * Da al comprador el acceso que compró: una matrícula por cada curso de la orden.
     */
    private function enroll(Order $order, User $actor): void
    {
        $order->items()->with('course')->get()->each(function (OrderItem $item) use ($order, $actor) {
            if ($order->customer_id === null) {
                return;
            }

            $enrollment = $this->enrollments
                ->enroll($item->course, [$order->customer_id], $actor, source: Enrollment::SOURCE_PURCHASE, requirePublished: false)
                ->first()
                ?? Enrollment::query()
                    ->where('course_id', $item->course_id)
                    ->where('student_id', $order->customer_id)
                    ->where('status', Enrollment::STATUS_ACTIVE)
                    ->first();

            $item->update(['enrollment_id' => $enrollment?->id]);
        });
    }

    /**
     * La orden quedó cobrada por completo.
     */
    private function markPaid(Order $order, User $actor, string $from): void
    {
        $order->update(['status' => Order::STATUS_PAID, 'paid_at' => now()]);

        $this->audit->record('orders.paid', $order, ['status' => $from], [
            'status' => Order::STATUS_PAID,
            'number' => $order->number,
            'total' => $order->total_amount,
        ]);

        OrderPaid::dispatch($order, $actor);
    }

    /**
     * Número de la orden como lo ve la persona.
     */
    private static function code(Order $order): string
    {
        return 'N.º '.str_pad((string) $order->number, 5, '0', STR_PAD_LEFT);
    }

    /**
     * Importe normalizado a dos decimales, para comparar sin errores de coma flotante.
     */
    private static function money(string $amount): string
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * El correlativo es por organización: se toma un candado para que dos
     * órdenes simultáneas no reciban el mismo número.
     */
    private function lockOrderNumbers(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ['orders:'.$this->context->tenantId()]);
        }
    }
}
