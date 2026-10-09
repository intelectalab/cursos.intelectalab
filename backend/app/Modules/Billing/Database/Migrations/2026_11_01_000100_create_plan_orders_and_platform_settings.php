<?php

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Órdenes con las que una organización le paga su plan a la plataforma, y
 * los ajustes propios de la plataforma (p. ej. cómo pagarle).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->string('key', 60)->primary();
            $table->jsonb('value')->nullable();
            $table->timestampsTz();
        });

        Schema::create('plan_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unsignedInteger('number')->unique();
            $table->foreignUuid('plan_id')->constrained('plans');
            // El plan tal como se ofrecía al pedirlo: un cambio posterior no altera la orden
            $table->string('plan_name', 80);
            $table->unsignedSmallInteger('billing_months');
            $table->unsignedInteger('max_students')->nullable();
            $table->decimal('amount', 10, 2);
            $table->char('currency', 3);
            // PENDING, PAID o CANCELLED
            $table->string('status', 20);
            $table->foreignUuid('requested_by')->nullable()->constrained('users')->nullOnDelete();
            // Lo que la organización informa de su pago (número de operación, fecha…)
            $table->string('payer_reference', 300)->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->string('payment_reference', 120)->nullable();
            $table->string('notes', 500)->nullable();
            // Correo del administrador de plataforma que confirmó o canceló
            $table->string('resolved_by', 180)->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'created_at']);
        });

        // Numeración única para toda la plataforma: una organización no ve las órdenes
        // de otra, así que no puede calcular "la siguiente". La secuencia pertenece a la
        // columna: se elimina con la tabla.
        DB::statement('CREATE SEQUENCE IF NOT EXISTS plan_order_number_seq');
        DB::statement('ALTER SEQUENCE plan_order_number_seq OWNED BY plan_orders.number');

        // Una sola orden pendiente por organización
        DB::statement("CREATE UNIQUE INDEX plan_orders_one_pending ON plan_orders (tenant_id) WHERE status = 'PENDING'");

        Rls::enable('plan_orders');
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_orders');
        Schema::dropIfExists('platform_settings');
    }
};
