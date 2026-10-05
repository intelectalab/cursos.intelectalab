<?php

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Catálogo global de monedas (RF-CUR-001). Los tipos de cambio quedan pendientes (PD-011).
        Schema::create('currencies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 3)->unique();
            $table->string('name', 60);
            $table->string('symbol', 5);
            $table->timestampsTz();
        });

        foreach ([['PEN', 'Sol', 'S/'], ['USD', 'Dólar estadounidense', 'US$']] as [$code, $name, $symbol]) {
            DB::table('currencies')->insert([
                'id' => (string) Str::uuid7(),
                'code' => $code,
                'name' => $name,
                'symbol' => $symbol,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('courses', function (Blueprint $table) {
            // Precio de venta en la moneda de la organización. Vacío = no está en venta; 0 = gratuito.
            // Regla comercial, no contenido: no forma parte de las versiones publicadas.
            $table->decimal('price', 10, 2)->nullable();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            // Correlativo por organización
            $table->unsignedInteger('number');
            $table->foreignUuid('customer_id')->constrained('users');
            // RN-019: importe y moneda quedan fijados en la orden
            $table->foreignUuid('currency_id')->constrained('currencies');
            $table->decimal('subtotal_amount', 12, 2);
            // Lo usarán las promociones y cupones (S11)
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('total_amount', 12, 2);
            // PENDING, PAID, CANCELLED, EXPIRED
            $table->string('status', 20)->default('PENDING');
            // Hasta cuándo se puede pagar una orden pendiente
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('paid_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignUuid('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['customer_id', 'status']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignUuid('course_id')->constrained('courses');
            // Título del curso al momento de la compra
            $table->string('title', 200);
            $table->decimal('unit_price', 12, 2);
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->decimal('total_amount', 12, 2);
            // Matrícula que generó el pago de la orden
            $table->foreignUuid('enrollment_id')->nullable()->constrained('enrollments')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['course_id']);
        });

        // Pagos aplicados a una orden. Las transacciones con el proveedor llegan con S6 (PD-001).
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('order_id')->constrained('orders');
            $table->foreignUuid('currency_id')->constrained('currencies');
            $table->decimal('amount', 12, 2);
            // Medio con el que pagó el comprador: TRANSFER, YAPE, CASH, OTHER
            $table->string('method', 20);
            // Pasarela que registró el pago; MANUAL = lo confirmó el personal
            $table->string('gateway', 30)->default('MANUAL');
            $table->string('status', 20)->default('CONFIRMED');
            // Número de operación que entrega el comprador
            $table->string('reference', 120)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignUuid('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('paid_at');
            $table->timestampsTz();

            $table->index(['order_id']);
        });

        // RN-009: una orden recibe un solo pago confirmado
        DB::statement("CREATE UNIQUE INDEX payments_one_confirmed ON payments (order_id) WHERE status = 'CONFIRMED'");

        foreach (['orders', 'order_items', 'payments'] as $table) {
            Rls::enable($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');

        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('price');
        });

        Schema::dropIfExists('currencies');
    }
};
