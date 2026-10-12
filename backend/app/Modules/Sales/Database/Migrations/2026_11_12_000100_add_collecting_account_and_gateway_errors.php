<?php

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Con qué cuenta de la pasarela se preparó cada cobro (para avisar si la
 * conectada hoy es otra) y el registro de lo que las pasarelas rechazan o
 * no responden, que el personal consulta sin entrar al servidor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table) {
            // Cuenta de la pasarela con la que se preparó el cobro: su identificador allá
            $table->string('account_ref', 120)->nullable();
            // Nombre de esa cuenta, tal como se mostraba al conectarla
            $table->string('account_name', 150)->nullable();
        });

        Schema::create('payment_gateway_errors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Organización donde ocurrió; vacío si fue en la consola de la plataforma o en un aviso sin orden
            $table->foreignUuid('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            // TENANT = con la cuenta de la organización; PLATFORM = con la cuenta de la plataforma (pago de planes)
            $table->string('scope', 10);
            // MERCADOPAGO, PAYPAL
            $table->string('gateway', 30);
            // CONNECT, CHECKOUT, SYNC, REFUND, WEBHOOK
            $table->string('operation', 20);
            // Sobre qué se operaba, en palabras: «Orden 00021», «Plan P-0003»
            $table->string('subject', 120)->nullable();
            // Lo que se le explicó a la persona: causa y qué hacer
            $table->text('message');
            // Código HTTP con el que respondió la pasarela; vacío si no respondió
            $table->unsignedSmallInteger('gateway_status')->nullable();
            // Lo que la pasarela dijo, o por qué no se pudo llegar a ella
            $table->string('gateway_reason', 300)->nullable();
            // Identificador de la petición: ubica el detalle en el registro del servidor
            $table->string('request_id', 64)->nullable();
            // Veces que se repitió el mismo error seguido
            $table->unsignedInteger('occurrences')->default(1);
            // Última vez que ocurrió
            $table->timestampTz('last_at');
            $table->timestampsTz();

            $table->index(['tenant_id', 'scope', 'last_at']);
            $table->index(['scope', 'last_at']);
        });

        Rls::enable('payment_gateway_errors');
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_gateway_errors');

        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->dropColumn(['account_ref', 'account_name']);
        });
    }
};
