<?php

namespace App\Modules\Sales\Console;

use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Sales\Domain\Models\OrderInstallment;
use App\Modules\Tenancy\Application\TenantNotifier;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Recordatorios por correo, una vez al día: la cuota que vence en unos días,
 * la que venció ayer y el acceso a un curso que está por terminar. Cada
 * aviso sale un día exacto, así que correrlo a diario no lo repite.
 */
class SendRemindersCommand extends Command
{
    /** Días de anticipación con que se avisa de una cuota y de un acceso por vencer */
    public const INSTALLMENT_DAYS = 3;

    public const ACCESS_DAYS = 7;

    protected $signature = 'reminders:send';

    protected $description = 'Envía los recordatorios del día: cuotas por vencer y vencidas, y accesos a cursos por terminar';

    public function handle(TenantContext $context, TenantNotifier $notifier, TenantSettingsService $settings): int
    {
        $sent = 0;

        foreach (Tenant::query()->where('status', Tenant::STATUS_ACTIVE)->pluck('id') as $tenantId) {
            $sent += $context->runForTenant($tenantId, function () use ($notifier, $settings) {
                // El día es el de la organización, no el del servidor
                $today = CarbonImmutable::now($settings->timezone())->startOfDay();

                return $this->installments($notifier, $today) + $this->accesses($notifier, $today);
            });
        }

        $this->info("Recordatorios enviados: {$sent}");

        return self::SUCCESS;
    }

    private function installments(TenantNotifier $notifier, CarbonImmutable $today): int
    {
        $soon = $today->addDays(self::INSTALLMENT_DAYS)->toDateString();
        $yesterday = $today->subDay()->toDateString();

        $installments = OrderInstallment::query()
            ->where('status', OrderInstallment::STATUS_PENDING)
            ->whereIn('due_date', [$soon, $yesterday])
            // Solo debe la orden que ya recibió su primer pago
            ->whereIn('order_id', Order::query()->where('status', Order::STATUS_PARTIAL)->select('id'))
            ->with(['order.customer', 'order.currency'])
            ->get();

        foreach ($installments as $installment) {
            $order = $installment->order;
            $money = TenantNotifier::money($order->currency->code, $installment->amount);
            $late = $installment->due_date->toDateString() === $yesterday;

            $notifier->notify($order->customer, $late ? 'cuota_vencida' : 'recordatorio_cuota', [
                'cuota' => $installment->number,
                'importe' => $money,
                'vencimiento' => $installment->due_date->format('d/m/Y'),
                'dias' => self::INSTALLMENT_DAYS,
            ], "/my-orders/{$order->id}");
        }

        return $installments->count();
    }

    private function accesses(TenantNotifier $notifier, CarbonImmutable $today): int
    {
        $day = $today->addDays(self::ACCESS_DAYS);

        $enrollments = Enrollment::query()
            ->where('status', Enrollment::STATUS_ACTIVE)
            ->whereNull('completed_at')
            ->whereBetween('ends_at', [$day->utc(), $day->endOfDay()->utc()])
            ->with(['student', 'course'])
            ->get();

        foreach ($enrollments as $enrollment) {
            $notifier->notify($enrollment->student, 'acceso_por_vencer', [
                'curso' => $enrollment->course->title,
                'fecha_fin' => $day->format('d/m/Y'),
                'dias' => self::ACCESS_DAYS,
            ], "/my-courses/{$enrollment->course_id}");
        }

        return $enrollments->count();
    }
}
