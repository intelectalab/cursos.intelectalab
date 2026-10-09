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
use App\Modules\Sales\Domain\Models\OrderItem;
use App\Modules\Sales\Domain\Models\Package;
use App\Modules\Sales\Domain\Models\Payment;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Venta a personas (RF-B2C-001, FP-02): checkout → validar precio → orden →
 * pago → matrícula. Una orden lleva un curso; los ítems admiten más para
 * cuando lleguen el carrito y los paquetes (S11).
 */
class OrderService
{
    public function __construct(
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
        if (! $course->isForSale()) {
            throw new ApiException(ErrorCode::CourseNotForSale, 'Este curso no está en venta.', 409);
        }

        if (! $customer->hasPermission(EnrollmentService::STUDENT_PERMISSION)) {
            throw new ApiException(ErrorCode::Forbidden, 'Solo una cuenta de alumno puede comprar cursos.', 403);
        }

        $title = $course->currentVersion->snapshot['title'] ?? $course->title;

        return $this->checkout($customer, Promotion::PRODUCT_COURSE, $course->id, $course->title, $course->price, $expectedTotal, $couponCode, [
            ['course_id' => $course->id, 'title' => $title, 'unit_price' => $course->price, 'quantity' => 1, 'total_amount' => $course->price],
        ]);
    }

    /**
     * Crea la orden de un paquete (FP-03): una compra, varias matrículas. El
     * precio del paquete se reparte entre sus cursos en proporción a su
     * precio de lista, para que la orden sume exacto. Quien ya tiene alguno
     * de los cursos solo queda matriculado en los que le faltan.
     */
    public function placePackage(User $customer, Package $package, ?string $expectedTotal = null, ?string $couponCode = null): Order
    {
        $package->loadMissing('courses.currentVersion');

        if (! $package->isForSale()) {
            throw new ApiException(ErrorCode::CourseNotForSale, 'Este paquete no está en venta.', 409);
        }

        if (! $customer->hasPermission(EnrollmentService::STUDENT_PERMISSION)) {
            throw new ApiException(ErrorCode::Forbidden, 'Solo una cuenta de alumno puede comprar cursos.', 403);
        }

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
        ]);
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
    ): Order {
        $currency = Currency::byCode($this->settings->currency());

        return DB::transaction(function () use ($customer, $productType, $productId, $productName, $subtotal, $expectedTotal, $couponCode, $items, $attributes, $currency) {
            $this->lockOrderNumbers();

            $this->assertCanBuy($customer, $productType, $productId, array_column($items, 'course_id'));

            // Sin cupo de alumnos no se vende: la orden pagada sí se respeta, por eso se revisa aquí
            if (! $this->quota->hasRoomFor([$customer->id])) {
                throw new ApiException(
                    ErrorCode::StudentLimitReached,
                    'Por ahora la academia no está recibiendo nuevos alumnos. Comunícate con ella para inscribirte.',
                    409
                );
            }

            // Un solo cupón por orden; el servidor decide si aplica y cuánto descuenta
            $applied = $couponCode !== null && trim($couponCode) !== ''
                ? $this->coupons->evaluate($couponCode, $customer, $productType, $productId, $subtotal, lock: true)
                : null;

            $discount = $applied['discount'] ?? '0.00';
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
                'expires_at' => now()->addHours($this->settings->orderExpirationHours()),
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
            ] + ($applied ? ['coupon' => $applied['coupon']->code, 'discount' => $discount] : []));

            if ($total === self::money('0')) {
                $this->settle($order, $customer);
            }

            return $order;
        });
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

            if (! ($online && $order->status === Order::STATUS_PENDING)) {
                $this->assertPending($order);
            }

            $payment = Payment::create([
                'order_id' => $order->id,
                'currency_id' => $order->currency_id,
                'amount' => $order->total_amount,
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
            ]);

            $this->settle($order, $actor);

            return $order;
        });
    }

    /**
     * Deja reembolsada una orden pagada: el dinero ya se devolvió (por la
     * pasarela o por fuera). Las matrículas que dio la compra se cancelan.
     * Solo compras personales con importe: una venta a empresa o una
     * inscripción gratuita no pasan por aquí.
     *
     * @param  string|null  $reference  devolución en la pasarela, o cómo se devolvió el dinero
     */
    public function markRefunded(Order $order, User $actor, string $reason, ?string $reference): Order
    {
        return DB::transaction(function () use ($order, $actor, $reason, $reference) {
            $order = Order::query()->lockForUpdate()->with('payment')->findOrFail($order->id);

            $this->assertRefundable($order);

            $order->payment->update([
                'status' => Payment::STATUS_REFUNDED,
                'refunded_at' => now(),
                'refunded_by' => $actor->id,
                'refund_reason' => $reason,
                'refund_reference' => $reference,
            ]);
            $order->update(['status' => Order::STATUS_REFUNDED, 'refunded_at' => now()]);

            // Devuelto el dinero, se retira el acceso que dio esta compra
            $order->items()->with('enrollment.student')->get()->each(function (OrderItem $item) {
                if ($item->enrollment?->isActive()) {
                    $this->enrollments->cancel($item->enrollment);
                }
            });

            $this->audit->record('orders.refunded', $order, ['status' => Order::STATUS_PAID], [
                'status' => Order::STATUS_REFUNDED,
                'number' => $order->number,
                'total' => $order->total_amount,
                'gateway' => $order->payment->gateway,
                'reason' => $reason,
                'reference' => $reference,
            ]);

            return $order;
        });
    }

    /**
     * Solo se reembolsa una compra personal pagada y con importe.
     */
    public function assertRefundable(Order $order): void
    {
        $message = match (true) {
            $order->status === Order::STATUS_REFUNDED => 'Esta orden ya fue reembolsada.',
            $order->status !== Order::STATUS_PAID || $order->payment === null => 'Solo se reembolsa una orden pagada.',
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
     */
    private function assertCanBuy(User $customer, string $productType, string $productId, array $courseIds): void
    {
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
                $productType === Promotion::PRODUCT_COURSE ? 'Ya tienes una matrícula en este curso.' : 'Ya tienes todos los cursos de este paquete.',
                409
            );
        }

        $pending = $productType === Promotion::PRODUCT_COURSE
            ? $this->pendingFor($customer, $productId)
            : $this->pendingForPackage($customer, $productId);

        if ($pending) {
            throw new ApiException(
                ErrorCode::OrderAlreadyPending,
                'Ya tienes una orden pendiente de pago por esta compra.',
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
        $order->update(['status' => Order::STATUS_PAID, 'paid_at' => now()]);

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

        $this->audit->record('orders.paid', $order, ['status' => Order::STATUS_PENDING], [
            'status' => Order::STATUS_PAID,
            'number' => $order->number,
            'total' => $order->total_amount,
        ]);

        OrderPaid::dispatch($order, $actor);
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
