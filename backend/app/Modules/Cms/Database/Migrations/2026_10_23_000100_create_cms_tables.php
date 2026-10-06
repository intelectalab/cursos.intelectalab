<?php

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Página de la organización. Se edita como borrador; lo que ve el público es su versión publicada (FP-19).
        Schema::create('pages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            // Parte final de la dirección pública: /p/{slug}
            $table->string('slug', 80);
            $table->string('title', 150);
            // Documento estructurado del editor (no HTML): solo los nodos que admite PageContent
            $table->jsonb('content');
            // Se enlaza al pie de las páginas públicas
            $table->boolean('show_in_footer')->default(true);
            // DRAFT = nunca publicada o retirada; PUBLISHED = tiene una versión a la vista
            $table->string('status', 20)->default('DRAFT');
            $table->uuid('current_version_id')->nullable();
            $table->timestampTz('published_at')->nullable();
            // Última vez que cambió el borrador
            $table->timestampTz('draft_updated_at');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'slug']);
        });

        // Cada publicación guarda una copia numerada
        Schema::create('page_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('page_id')->constrained('pages')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('title', 150);
            $table->jsonb('content');
            $table->foreignUuid('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('published_at');
            $table->timestampsTz();

            $table->unique(['page_id', 'version']);
        });

        // Imágenes subidas para las páginas
        Schema::create('media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('file_id')->constrained('files')->cascadeOnDelete();
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
        });

        foreach (['pages', 'page_versions', 'media'] as $table) {
            Rls::enable($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
        Schema::dropIfExists('page_versions');
        Schema::dropIfExists('pages');
    }
};
