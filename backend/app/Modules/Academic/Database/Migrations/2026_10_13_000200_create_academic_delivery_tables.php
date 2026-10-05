<?php

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lo que hacen los alumnos con las actividades. `activity_id` apunta a la
 * actividad de la versión publicada, no a la tabla del borrador (sin FK):
 * el resultado se conserva aunque la actividad se edite o se elimine después.
 */
return new class extends Migration
{
    private const TABLES = ['quiz_attempts', 'quiz_answers', 'task_submissions', 'survey_responses'];

    public function up(): void
    {
        Schema::create('quiz_attempts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('enrollment_id')->constrained('enrollments')->cascadeOnDelete();
            $table->foreignUuid('student_id')->constrained('users');
            $table->foreignUuid('course_id')->constrained('courses');
            $table->foreignUuid('course_version_id')->constrained('course_versions');
            $table->uuid('activity_id');
            $table->unsignedSmallInteger('number');
            // IN_PROGRESS, SUBMITTED, EXPIRED
            $table->string('status', 20);
            // Preguntas tal como se le presentaron, con sus respuestas: solo para calificar
            $table->jsonb('questions');
            $table->decimal('passing_percent', 5, 2);
            $table->timestampTz('started_at');
            $table->timestampTz('expires_at')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->decimal('score_percent', 5, 2)->nullable();
            $table->boolean('passed')->nullable();
            $table->timestampsTz();

            $table->unique(['enrollment_id', 'activity_id', 'number']);
            $table->index(['course_id', 'activity_id']);
        });

        Schema::create('quiz_answers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('attempt_id')->constrained('quiz_attempts')->cascadeOnDelete();
            $table->uuid('question_id');
            $table->jsonb('answer')->nullable();
            // Fracción obtenida (0 a 1) y puntos que representa
            $table->decimal('score', 5, 4);
            $table->decimal('points_possible', 6, 2);
            $table->decimal('points_earned', 8, 4);

            $table->unique(['attempt_id', 'question_id']);
        });

        Schema::create('task_submissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('enrollment_id')->constrained('enrollments')->cascadeOnDelete();
            $table->foreignUuid('student_id')->constrained('users');
            $table->foreignUuid('course_id')->constrained('courses');
            $table->uuid('activity_id');
            $table->text('body')->nullable();
            $table->foreignUuid('file_id')->nullable()->constrained('files')->nullOnDelete();
            // SUBMITTED (por revisar) o GRADED
            $table->string('status', 20);
            $table->timestampTz('submitted_at');
            $table->decimal('grade_percent', 5, 2)->nullable();
            $table->text('feedback')->nullable();
            $table->foreignUuid('graded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('graded_at')->nullable();
            $table->timestampsTz();

            // Una entrega por alumno y tarea; mientras no se califique, se reemplaza
            $table->unique(['enrollment_id', 'activity_id']);
            $table->index(['course_id', 'status']);
        });

        Schema::create('survey_responses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('enrollment_id')->constrained('enrollments')->cascadeOnDelete();
            $table->foreignUuid('student_id')->constrained('users');
            $table->foreignUuid('course_id')->constrained('courses');
            $table->uuid('activity_id');
            $table->jsonb('answers');
            $table->timestampTz('submitted_at');

            $table->unique(['enrollment_id', 'activity_id']);
            $table->index(['course_id', 'activity_id']);
        });

        // Un solo intento abierto a la vez por alumno y cuestionario
        DB::statement("CREATE UNIQUE INDEX quiz_attempts_one_open ON quiz_attempts (enrollment_id, activity_id) WHERE status = 'IN_PROGRESS'");

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
