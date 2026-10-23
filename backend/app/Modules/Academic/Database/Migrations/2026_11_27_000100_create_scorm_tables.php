<?php

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SCORM (RF-SCORM-001): paquetes subidos a una actividad del curso y lo que
 * cada alumno lleva en ellos.
 */
return new class extends Migration
{
    private const TABLES = ['scorm_packages', 'scorm_attempts'];

    public function up(): void
    {
        Schema::create('scorm_packages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            // Sin clave foránea: la versión publicada sigue usando su paquete aunque se borre la actividad del borrador
            $table->uuid('activity_id');
            // 1.2 o 2004
            $table->string('version', 10);
            // Página que se abre, relativa a la carpeta del paquete
            $table->string('entry', 500);
            $table->string('title', 200)->nullable();
            $table->string('file_name', 200);
            $table->unsignedBigInteger('size_bytes');
            $table->unsignedInteger('files_count');
            // Nota mínima que define el paquete (adlcp:masteryscore), en porcentaje
            $table->decimal('mastery_score', 5, 2)->nullable();
            $table->string('disk', 30);
            $table->string('directory', 300);
            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['activity_id', 'created_at']);
        });

        Schema::create('scorm_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('enrollment_id')->constrained('enrollments')->cascadeOnDelete();
            $table->foreignUuid('student_id')->constrained('users');
            $table->foreignUuid('course_id')->constrained('courses');
            $table->uuid('activity_id');
            $table->uuid('package_id');
            // Estado que informa el paquete: not attempted, incomplete, completed, passed, failed, browsed
            $table->string('lesson_status', 20)->default('not attempted');
            $table->decimal('score_percent', 5, 2)->nullable();
            // Todos los datos que el paquete guardó (cmi.*): con ellos se retoma donde quedó
            $table->jsonb('cmi')->default('{}');
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampsTz();

            // Un solo intento, que se retoma
            $table->unique(['enrollment_id', 'activity_id']);
            $table->index(['course_id', 'activity_id']);
        });

        foreach (self::TABLES as $table) {
            Rls::enable($table);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLES) as $table) {
            Schema::dropIfExists($table);
        }
    }
};
