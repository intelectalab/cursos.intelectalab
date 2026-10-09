<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Órdenes de plan: plazo de pago, constancia adjunta, pago en línea, lo
 * necesario para deshacer una confirmación y el aviso a la organización.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_orders', function (Blueprint $table) {
            // Hasta cuándo espera su pago; pasado el plazo vence sola si no se informó ningún pago
            $table->timestampTz('expires_at')->nullable();
            // Constancia de pago que adjunta la organización
            $table->foreignUuid('proof_file_id')->nullable()->constrained('files')->nullOnDelete();
            // Cómo estaba la suscripción antes de confirmar, y hasta cuándo quedó: permite deshacer
            $table->jsonb('previous_subscription')->nullable();
            $table->timestampTz('applied_period_ends_at')->nullable();
            // La organización ya vio cómo se resolvió su orden (confirmada, cancelada o vencida)
            $table->timestampTz('outcome_seen_at')->nullable();
            // Pago en línea: pasarela, cobro preparado en ella y pago con el que quedó pagada
            $table->string('gateway', 30)->nullable();
            $table->string('gateway_checkout_id', 120)->nullable();
            $table->string('gateway_payment_id', 120)->nullable();
            // La pasarela cobró y no se pudo aplicar: lo resuelve una persona
            $table->string('review_reason', 300)->nullable();
        });

        // Las pendientes de antes reciben el plazo desde hoy; las ya resueltas no generan avisos
        DB::table('plan_orders')->where('status', 'PENDING')->update(['expires_at' => now()->addDays(config('billing.order_expiration_days', 7))]);
        DB::table('plan_orders')->where('status', '!=', 'PENDING')->update(['outcome_seen_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('plan_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('proof_file_id');
            $table->dropColumn(['expires_at', 'previous_subscription', 'applied_period_ends_at', 'outcome_seen_at', 'gateway', 'gateway_checkout_id', 'gateway_payment_id', 'review_reason']);
        });
    }
};
