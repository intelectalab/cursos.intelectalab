<?php

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'question_banks', 'questions', 'course_activities', 'quizzes', 'quiz_questions', 'tasks', 'surveys',
    ];

    public function up(): void
    {
        // Bancos de preguntas de la organización, reutilizables entre cursos
        Schema::create('question_banks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('name', 150);
            $table->string('description', 300)->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('question_bank_id')->constrained('question_banks')->cascadeOnDelete();
            $table->string('type', 20);
            $table->text('statement');
            // Opciones y respuesta correcta; la forma depende del tipo (QuestionConfig)
            $table->jsonb('config');
            // Comentario que se muestra al alumno después de responder
            $table->text('explanation')->nullable();
            $table->timestampsTz();

            $table->index(['question_bank_id', 'type']);
        });

        // Actividad evaluable dentro de una sección: QUIZ, TASK o SURVEY
        Schema::create('course_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignUuid('section_id')->constrained('course_sections')->cascadeOnDelete();
            $table->string('type', 20);
            $table->string('title', 200);
            $table->text('description')->nullable();
            $table->unsignedInteger('position');
            $table->timestampsTz();

            $table->index(['section_id', 'position']);
        });

        Schema::create('quizzes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('activity_id')->unique()->constrained('course_activities')->cascadeOnDelete();
            // Porcentaje mínimo para aprobar; se muestra en la escala de la organización
            $table->decimal('passing_percent', 5, 2)->default(55);
            // null = intentos ilimitados
            $table->unsignedSmallInteger('max_attempts')->nullable()->default(3);
            $table->unsignedSmallInteger('time_limit_minutes')->nullable();
            $table->boolean('shuffle')->default(false);
            $table->timestampsTz();
        });

        Schema::create('quiz_questions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('quiz_id')->constrained('quizzes')->cascadeOnDelete();
            // Una pregunta en uso no se borra del banco
            $table->foreignUuid('question_id')->constrained('questions')->restrictOnDelete();
            $table->unsignedInteger('position');
            $table->decimal('points', 6, 2)->default(1);

            $table->unique(['quiz_id', 'question_id']);
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('activity_id')->unique()->constrained('course_activities')->cascadeOnDelete();
            // Qué puede entregar el alumno
            $table->boolean('allow_text')->default(true);
            $table->boolean('allow_file')->default(true);
            $table->timestampsTz();
        });

        Schema::create('surveys', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('activity_id')->unique()->constrained('course_activities')->cascadeOnDelete();
            // Preguntas de escala, opción o texto libre (SurveyConfig)
            $table->jsonb('questions');
            $table->timestampsTz();
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
