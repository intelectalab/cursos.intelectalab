<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Con qué cuenta de la plataforma se preparó el cobro en línea de cada
 * orden de plan, para avisar si la conectada hoy es otra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_orders', function (Blueprint $table) {
            // Cuenta de la pasarela con la que se preparó el cobro: su identificador allá
            $table->string('gateway_account_ref', 120)->nullable();
            // Nombre de esa cuenta, tal como se mostraba al conectarla
            $table->string('gateway_account_name', 150)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('plan_orders', function (Blueprint $table) {
            $table->dropColumn(['gateway_account_ref', 'gateway_account_name']);
        });
    }
};
