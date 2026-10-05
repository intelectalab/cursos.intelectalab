<?php

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Dispositivo de origen de cada sesión (sesiones activas)
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            // Solo se guarda el hash: el token viaja únicamente en el correo
            $table->string('token_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'user_id']);
        });

        Rls::enable('password_reset_tokens');
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');

        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['ip_address', 'user_agent']);
        });
    }
};
