<?php

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('title', 200);
            $table->string('summary', 300)->nullable();
            $table->text('description')->nullable();
            $table->string('category', 80)->nullable();
            $table->string('level', 20)->nullable();
            $table->unsignedSmallInteger('duration_hours')->nullable();
            $table->string('language', 5)->default('es');
            $table->foreignUuid('cover_file_id')->nullable()->constrained('files')->nullOnDelete();
            // DRAFT, PUBLISHED, ARCHIVED (Maestro §6)
            $table->string('status', 20)->default('DRAFT');
            // Versión que ven los alumnos; null mientras no se publique
            $table->uuid('current_version_id')->nullable();
            // El borrador difiere de la última versión publicada
            $table->boolean('has_pending_changes')->default(true);
            $table->timestampTz('published_at')->nullable();
            $table->timestampTz('archived_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['tenant_id', 'status']);
        });

        // Copia inmutable del curso en cada publicación
        Schema::create('course_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('course_id')->constrained('courses')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('note', 300)->nullable();
            $table->jsonb('snapshot');
            $table->foreignUuid('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('published_at');

            $table->unique(['course_id', 'number']);
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->foreign('current_version_id')->references('id')->on('course_versions')->nullOnDelete();
        });

        Schema::create('course_sections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('course_id')->constrained('courses')->cascadeOnDelete();
            $table->string('title', 200);
            $table->unsignedInteger('position');
            $table->timestampsTz();

            $table->index(['course_id', 'position']);
        });

        Schema::create('course_resources', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignUuid('section_id')->constrained('course_sections')->cascadeOnDelete();
            // VIDEO (enlace a YouTube), DOCUMENT, LINK, TEXT
            $table->string('type', 20);
            $table->string('title', 200);
            $table->unsignedInteger('position');
            $table->string('url', 2000)->nullable();
            $table->text('body')->nullable();
            $table->foreignUuid('file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['section_id', 'position']);
        });

        // Profesores asignados: acota lo que ve y edita el rol Profesor
        Schema::create('course_teachers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('created_at')->nullable();

            $table->unique(['course_id', 'user_id']);
        });

        foreach (['courses', 'course_versions', 'course_sections', 'course_resources', 'course_teachers'] as $table) {
            Rls::enable($table);
        }
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
        });

        Schema::dropIfExists('course_teachers');
        Schema::dropIfExists('course_resources');
        Schema::dropIfExists('course_sections');
        Schema::dropIfExists('course_versions');
        Schema::dropIfExists('courses');
    }
};
