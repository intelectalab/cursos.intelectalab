<?php

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Bajas de correos: una fila por persona y tipo de correo que dejó de recibir
        Schema::create('email_opt_outs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            // Correo de la persona, en minúsculas (también sirve si aún no tiene cuenta)
            $table->string('email', 190);
            // Tipo de correo (EmailCatalog::OPT_OUTS): courses, reminders, announcements o staff
            $table->string('category', 20);
            // Desde el enlace del correo (LINK), con un clic desde el programa de correo (ONE_CLICK) o en «Mi cuenta» (ACCOUNT)
            $table->string('source', 10);
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['tenant_id', 'email', 'category']);
        });

        Rls::enable('email_opt_outs');
    }

    public function down(): void
    {
        Schema::dropIfExists('email_opt_outs');
    }
};
