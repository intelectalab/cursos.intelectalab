<?php

namespace App\Modules\Sales\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Enrollments\Application\EnrollmentService;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Sales\Application\Payments\PaymentGateway;
use App\Modules\Sales\Domain\Events\OrderCancelled;
use App\Modules\Sales\Domain\Events\OrderPaid;
use App\Modules\Sales\Domain\Models\Currency;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\OrderItem;
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
        private readonly TenantSettingsService $settings,
        private readonly PaymentGateway $gateway,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Crea la orden de compra de un curso. El precio se toma del servidor
     * (RN-008): si no coincide con el que vio el comprador, se rechaza para
     * que lo confirme. Un curso gratuito queda pagado y matriculado al instante.
     *
     * @param  string|null  $expectedTotal  total que el comprador vio en pantalla
     */
    public function place(User $customer, Course $course, ?string $expectedTotal = null): Order
    {
        if (! $course->isForSale()) {
            throw new ApiException(ErrorCode::CourseNotForSale, 'Este curso no está en venta.', 409);
        }

        if (! $customer->hasPermission(EnrollmentService::STUDENT_PERMISSION)) {
            throw new ApiException(ErrorCode::Forbidden, 'Solo una cuenta de alumno puede comprar cursos.', 403);
        }

        $currency = Currency::byCode($this->settings->currency());

        if ($expectedTotal !== null && self::money($expectedTotal) !== self::money($course->price)) {
            throw new ApiException(
                ErrorCode::PriceChanged,
                'El precio del curso cambió. Revisa el nuevo total antes de continuar.',
                409,
                ['total' => $course->price, 'currency' => $currency->code]
            );
        }

        return DB::transaction(function () use ($customer, $course, $currency) {
            $this->lockOrderNumbers();

            $this->assertCanBuy($customer, $course);

            $order = Order::create([
                'number' => (int) Order::query()->max('number') + 1,
                'customer_id' => $customer->id,
                'currency_id' => $currency->id,
                'subtotal_amount' => $course->price,
                'discount_amount' => 0,
                'total_amount' => $course->price,
                'status' => Order::STATUS_PENDING,
                'expires_at' => now()->addHours($this->settings->orderExpirationHours()),
            ]);

            $order->items()->create([
                'course_id' => $course->id,
                'title' => $course->currentVersion->snapshot['title'] ?? $course->title,
                'unit_price' => $course->price,
                'quantity' => 1,
                'total_amount' => $course->price,
            ]);

            $this->audit->record('orders.created', $order, null, [
                'number' => $order->number,
                'customer' => $customer->email,
                'course' => $course->title,
                'total' => $order->total_amount,
                'currency' => $currency->code,
            ]);

            if (self::money($order->total_amount) === self::money('0')) {
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
     * @param  array{method: string, reference?: ?string, notes?: ?string, paid_at?: ?CarbonInterface}  $data
     */
    public function applyPayment(Order $order, User $actor, array $data): Order
    {
        return DB::transaction(function () use ($order, $actor, $data) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            $this->assertPending($order);

            $payment = Payment::create([
                'order_id' => $order->id,
                'currency_id' => $order->currency_id,
                'amount' => $order->total_amount,
                'method' => $data['method'],
                'gateway' => $this->gateway->code(),
                'status' => Payment::STATUS_CONFIRMED,
                'reference' => $data['reference'] ?? null,
                'notes' => $data['notes'] ?? null,
                'confirmed_by' => $actor->id,
                'paid_at' => $data['paid_at'] ?? now(),
            ]);

            $this->audit->record('payments.confirmed', $payment, null, [
                'order' => $order->number,
                'amount' => $payment->amount,
                'method' => $payment->method,
                'reference' => $payment->reference,
            ]);

            $this->settle($order, $actor);

            return $order;
        });
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
            ->whereHas('items', fn ($items) => $items->where('course_id', $courseId))
            ->first();
    }

    /**
     * Sin compra duplicada: ni con matrícula en el curso ni con otra orden esperando pago.
     */
    private function assertCanBuy(User $customer, Course $course): void
    {
        // Serializa las compras simultáneas del mismo comprador
        User::query()->whereKey($customer->id)->lockForUpdate()->first();

        $enrolled = Enrollment::query()
            ->where('course_id', $course->id)
            ->where('student_id', $customer->id)
            ->where('status', Enrollment::STATUS_ACTIVE)
            ->exists();

        if ($enrolled) {
            throw new ApiException(ErrorCode::AlreadyEnrolled, 'Ya tienes una matrícula en este curso.', 409);
        }

        $pending = $this->pendingFor($customer, $course->id);

        if ($pending) {
            throw new ApiException(
                ErrorCode::OrderAlreadyPending,
                'Ya tienes una orden pendiente de pago por este curso.',
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
