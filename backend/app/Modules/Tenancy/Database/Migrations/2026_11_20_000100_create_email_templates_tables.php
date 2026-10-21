<?php

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Textos propios de los correos automáticos. Lo que no se personaliza sale
 * con el texto base: el de la plataforma o, si tampoco lo cambió, el del
 * sistema (EmailCatalog).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            // Qué correo es (clave de EmailCatalog, por ejemplo «orden_recibida»)
            $table->string('key', 60);
            // Asunto propio; vacío = el texto base
            $table->string('subject', 200)->nullable();
            // Mensaje propio, con párrafos separados por una línea en blanco; vacío = el texto base
            $table->text('body')->nullable();
            // Texto del botón propio; vacío = el texto base
            $table->string('button', 60)->nullable();
            // Si se envía; los correos indispensables no se pueden apagar
            $table->boolean('enabled')->default(true);
            // Quién lo cambió por última vez
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'key']);
        });

        Rls::enable('email_templates');

        Schema::create('platform_email_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Qué correo es: uno propio de la plataforma o el texto base de un correo de las academias
            $table->string('key', 60)->unique();
            // Asunto; vacío = el del sistema
            $table->string('subject', 200)->nullable();
            // Mensaje, con párrafos separados por una línea en blanco; vacío = el del sistema
            $table->text('body')->nullable();
            // Texto del botón; vacío = el del sistema
            $table->string('button', 60)->nullable();
            // Si se envía (solo cuenta en los correos propios de la plataforma)
            $table->boolean('enabled')->default(true);
            // Correo del administrador de plataforma que lo cambió
            $table->string('updated_by', 190)->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_email_templates');
        Schema::dropIfExists('email_templates');
    }
};
