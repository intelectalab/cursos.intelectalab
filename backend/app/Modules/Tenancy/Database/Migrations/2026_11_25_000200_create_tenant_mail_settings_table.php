<?php

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Correo de envío de cada academia: el suyo propio o el de la plataforma con su nombre
        Schema::create('tenant_mail_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained('tenants');
            // PLATFORM (sale por el correo de IntelectaLab) u OWN (su propio servidor de correo)
            $table->string('mode', 10)->default('PLATFORM');
            $table->string('from_name', 100)->nullable();
            $table->string('reply_to', 190)->nullable();
            $table->string('from_address', 190)->nullable();
            // Servidor, puerto, seguridad, usuario y contraseña, cifrados con la clave de la aplicación
            $table->text('smtp')->nullable();
            $table->timestampTz('last_sent_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('last_error_at')->nullable();
            $table->timestampsTz();
        });

        Rls::enable('tenant_mail_settings');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_mail_settings');
    }
};
