<?php

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cobro en línea (S6): la cuenta que cada organización conecta en cada
 * pasarela y los intentos de pago que sus compradores inician en ellas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_gateway_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // MERCADOPAGO, PAYPAL
            $table->string('gateway', 30);
            $table->boolean('enabled')->default(true);
            // Claves de la cuenta, cifradas con la clave de la aplicación
            $table->text('credentials');
            // Lo que se puede mostrar de la cuenta: nombre, país, si es de prueba
            $table->jsonb('display')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'gateway']);
        });

        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('gateway', 30);
            // Identificador del cobro en la pasarela (preferencia, orden)
            $table->string('external_id', 120)->nullable();
            // Pago que la pasarela terminó informando
            $table->string('external_payment_id', 120)->nullable();
            // CREATED, APPROVED, REJECTED, NEEDS_REVIEW
            $table->string('status', 20)->default('CREATED');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3);
            $table->text('checkout_url')->nullable();
            // Por qué quedó para revisar
            $table->string('review_reason', 300)->nullable();
            $table->timestampsTz();

            $table->index(['order_id']);
            $table->index(['gateway', 'external_payment_id']);
        });

        foreach (['payment_gateway_accounts', 'payment_attempts'] as $table) {
            Rls::enable($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('payment_gateway_accounts');
    }
};
