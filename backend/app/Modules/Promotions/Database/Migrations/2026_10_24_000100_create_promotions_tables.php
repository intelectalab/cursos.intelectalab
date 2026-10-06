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
        // Promoción: la regla del descuento. Sus cupones son los códigos que la activan (FP-13).
        Schema::create('promotions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('name', 150);
            // PERCENT = porcentaje del precio; FIXED = monto en la moneda de la organización
            $table->string('discount_type', 10);
            $table->decimal('discount_value', 12, 2);
            // Vigencia; null = sin límite por ese lado
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            // Usos en total entre todos sus cupones; null = sin tope
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->boolean('once_per_customer')->default(true);
            // Compra mínima, antes del descuento
            $table->decimal('min_purchase', 12, 2)->nullable();
            // ALL = todo lo que está en venta; SELECTED = solo lo listado en promotion_items
            $table->string('applies_to', 10)->default('ALL');
            // ACTIVE, INACTIVE
            $table->string('status', 10)->default('ACTIVE');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['tenant_id', 'status']);
        });

        // A qué aplica una promoción acotada: cursos o paquetes
        Schema::create('promotion_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('promotion_id')->constrained('promotions')->cascadeOnDelete();
            // COURSE o PACKAGE
            $table->string('product_type', 10);
            $table->uuid('product_id');
            $table->timestampsTz();

            $table->unique(['promotion_id', 'product_type', 'product_id']);
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('promotion_id')->constrained('promotions')->cascadeOnDelete();
            // Siempre en mayúsculas
            $table->string('code', 40);
            // Para saber por dónde llegó la venta: Instagram, correo, un aliado…
            $table->string('label', 100)->nullable();
            // ACTIVE, INACTIVE
            $table->string('status', 10)->default('ACTIVE');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'code']);
        });

        // Un uso de un cupón: en qué orden, por quién y cuánto descontó
        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('coupon_id')->constrained('coupons');
            $table->foreignUuid('promotion_id')->constrained('promotions');
            // Una orden lleva un solo cupón (los descuentos no se acumulan)
            $table->foreignUuid('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained('users');
            $table->decimal('discount_amount', 12, 2);
            $table->timestampsTz();

            $table->index(['promotion_id']);
            $table->index(['customer_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            // Código del cupón aplicado, tal como quedó en la orden
            $table->string('coupon_code', 40)->nullable();
        });

        foreach (['promotions', 'promotion_items', 'coupons', 'coupon_redemptions'] as $table) {
            Rls::enable($table);
        }

        DB::statement("ALTER TABLE promotions ADD CONSTRAINT promotions_discount_positive CHECK (discount_value > 0 AND (discount_type <> 'PERCENT' OR discount_value <= 100))");
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('coupon_code');
        });

        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('promotion_items');
        Schema::dropIfExists('promotions');
    }
};
