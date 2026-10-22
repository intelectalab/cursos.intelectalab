<?php

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Asistencia de cada alumno a una clase en vivo: un curso, un día y la hora de inicio de la clase
        Schema::create('live_class_attendances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('course_id')->constrained('courses')->cascadeOnDelete();
            $table->date('session_date');
            $table->string('session_start', 5);
            $table->foreignUuid('student_id')->constrained('users')->cascadeOnDelete();
            // PRESENT, LATE, ABSENT, EXCUSED
            $table->string('status', 10);
            $table->foreignUuid('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['course_id', 'session_date', 'session_start', 'student_id']);
            $table->index(['student_id', 'course_id']);
        });

        Rls::enable('live_class_attendances');
    }

    public function down(): void
    {
        Schema::dropIfExists('live_class_attendances');
    }
};
