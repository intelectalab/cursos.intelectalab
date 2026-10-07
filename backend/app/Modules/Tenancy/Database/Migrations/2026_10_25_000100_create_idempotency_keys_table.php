<?php

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Operaciones con efecto ya atendidas: repetir la petición devuelve la misma respuesta
        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('user_id')->constrained('users');
            $table->string('key', 100);
            $table->string('method', 10);
            $table->string('path', 255);
            // Huella del cuerpo: la misma clave con otro contenido es un error del cliente
            $table->char('request_hash', 64);
            // Vacíos mientras la primera petición sigue en curso
            $table->unsignedSmallInteger('response_status')->nullable();
            $table->jsonb('response_body')->nullable();
            $table->timestampTz('created_at');

            $table->unique(['tenant_id', 'user_id', 'key']);
            $table->index('created_at');
        });

        Rls::enable('idempotency_keys');
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
