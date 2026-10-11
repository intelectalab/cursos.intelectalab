<?php

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cuotas de una orden que se paga por partes (RN-012, FP-15). Una orden sin cuotas se paga de una vez.
        Schema::create('order_installments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            // Orden de pago: 1 es la primera cuota, la que da acceso al curso
            $table->unsignedSmallInteger('number');
            // Importe de la cuota; las cuotas de una orden suman su total
            $table->decimal('amount', 12, 2);
            // Último día para pagarla; pasado ese día sin pago, es una deuda vencida
            $table->date('due_date');
            // PENDING = por pagar; PAID = pagada
            $table->string('status', 20)->default('PENDING');
            // Cuándo se pagó
            $table->timestampTz('paid_at')->nullable();
            // Quién armó el plan de pagos
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['order_id', 'number']);
            $table->index(['tenant_id', 'status', 'due_date']);
        });

        Rls::enable('order_installments');

        Schema::table('payments', function (Blueprint $table) {
            // Cuota que cubre el pago; vacío = pago de la orden completa
            $table->foreignUuid('installment_id')->nullable()->constrained('order_installments')->nullOnDelete();
        });

        // Un solo pago vigente por orden completa, y uno por cuota
        DB::statement('DROP INDEX IF EXISTS payments_one_confirmed');
        DB::statement("CREATE UNIQUE INDEX payments_one_confirmed ON payments (order_id) WHERE status = 'CONFIRMED' AND installment_id IS NULL");
        DB::statement("CREATE UNIQUE INDEX payments_one_per_installment ON payments (installment_id) WHERE status = 'CONFIRMED'");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS payments_one_per_installment');
        DB::statement('DROP INDEX IF EXISTS payments_one_confirmed');

        Schema::table('payments', fn (Blueprint $table) => $table->dropConstrainedForeignId('installment_id'));
        Schema::dropIfExists('order_installments');

        DB::statement("CREATE UNIQUE INDEX payments_one_confirmed ON payments (order_id) WHERE status = 'CONFIRMED'");
    }
};
