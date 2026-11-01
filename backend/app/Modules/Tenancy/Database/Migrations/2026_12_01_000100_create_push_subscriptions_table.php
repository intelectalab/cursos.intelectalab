<?php

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Avisos al teléfono: un dispositivo (navegador o app instalada) que aceptó recibirlos
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            // Persona que activó los avisos en ese dispositivo
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            // Dirección del servicio de avisos del navegador (Google, Apple, Mozilla…) para ese dispositivo
            $table->text('endpoint');
            // SHA-256 de la dirección: identifica el dispositivo sin indexar un texto largo
            $table->char('endpoint_hash', 64);
            // Llave pública del dispositivo con la que se cifra cada aviso (p256dh)
            $table->string('public_key', 200);
            // Secreto de autenticación del dispositivo que acompaña al cifrado
            $table->string('auth_token', 100);
            // Navegador y sistema del dispositivo, para que la persona reconozca cuál es
            $table->string('user_agent', 255)->nullable();
            // Último aviso entregado a ese dispositivo
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'endpoint_hash']);
            $table->index(['tenant_id', 'user_id']);
        });

        Rls::enable('push_subscriptions');
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
