<?php

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plazo de las tareas, devolución de entregas e intentos adicionales de
 * cuestionario concedidos a un alumno.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // Días desde el inicio de la matrícula; vacío = sin plazo
            $table->unsignedSmallInteger('due_days')->nullable();
            // Pasado el plazo: se recibe marcada como tardía, o ya no se recibe
            $table->boolean('accept_late')->default(true);
        });

        Schema::table('task_submissions', function (Blueprint $table) {
            $table->boolean('is_late')->default(false);
            // RETURNED: quien revisa la devolvió para que el alumno la rehaga
            $table->timestampTz('returned_at')->nullable();
            $table->foreignUuid('returned_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('quiz_attempt_grants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('enrollment_id')->constrained('enrollments')->cascadeOnDelete();
            $table->foreignUuid('course_id')->constrained('courses');
            // Actividad de la versión publicada (sin FK, como los intentos)
            $table->uuid('activity_id');
            $table->unsignedSmallInteger('attempts');
            $table->string('reason', 300);
            $table->foreignUuid('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['enrollment_id', 'activity_id']);
        });

        Rls::enable('quiz_attempt_grants');
    }

    public function down(): void
    {
        Schema::dropIfExists('quiz_attempt_grants');

        Schema::table('task_submissions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('returned_by');
            $table->dropColumn(['is_late', 'returned_at']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropColumn(['due_days', 'accept_late']);
        });
    }
};
