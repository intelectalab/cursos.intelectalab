<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cobro en una moneda distinta a la de la orden (PayPal cobra en dólares):
 * el tipo de cambio que fija quien cobra y lo que de verdad se le pidió
 * cobrar a la pasarela.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_gateway_accounts', function (Blueprint $table) {
            // Ajustes de la cuenta que no son claves: p. ej. el tipo de cambio (soles por dólar)
            $table->jsonb('settings')->nullable();
        });

        Schema::table('payment_attempts', function (Blueprint $table) {
            // amount y currency guardan lo que se pidió cobrar; aquí, el tipo de cambio usado si lo hubo
            $table->decimal('exchange_rate', 10, 4)->nullable();
        });

        Schema::table('plan_orders', function (Blueprint $table) {
            // Lo que se le pidió cobrar a la pasarela, si no fue el importe de la orden tal cual
            $table->decimal('gateway_amount', 10, 2)->nullable();
            $table->char('gateway_currency', 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('plan_orders', fn (Blueprint $table) => $table->dropColumn(['gateway_amount', 'gateway_currency']));
        Schema::table('payment_attempts', fn (Blueprint $table) => $table->dropColumn('exchange_rate'));
        Schema::table('payment_gateway_accounts', fn (Blueprint $table) => $table->dropColumn('settings'));
    }
};
