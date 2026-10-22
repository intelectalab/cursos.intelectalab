<?php

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Avisos del docente o de la academia a los alumnos de un curso
        Schema::create('course_announcements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignUuid('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 150);
            $table->text('body');
            // Cuándo se avisó por correo a los matriculados; vacío = solo se ve en el aula
            $table->timestampTz('emailed_at')->nullable();
            $table->timestampsTz();

            $table->index(['course_id', 'created_at']);
        });

        // Opinión de un alumno sobre un curso: una por alumno y curso
        Schema::create('course_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('course_id')->constrained('courses')->cascadeOnDelete();
            $table->foreignUuid('student_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('rating');
            $table->text('comment')->nullable();
            // VISIBLE u HIDDEN (la ocultó la academia)
            $table->string('status', 10)->default('VISIBLE');
            $table->string('hidden_reason', 300)->nullable();
            $table->foreignUuid('hidden_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('hidden_at')->nullable();
            $table->timestampsTz();

            $table->unique(['course_id', 'student_id']);
            $table->index(['course_id', 'status']);
        });

        foreach (['course_announcements', 'course_reviews'] as $table) {
            Rls::enable($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('course_reviews');
        Schema::dropIfExists('course_announcements');
    }
};
