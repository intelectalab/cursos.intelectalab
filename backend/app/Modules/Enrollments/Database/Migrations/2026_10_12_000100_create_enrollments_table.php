<?php

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('course_id')->constrained('courses');
            $table->foreignUuid('student_id')->constrained('users');
            // ACTIVE, CANCELLED. La vigencia queda fuera: el Maestro la deja pendiente (PD-005).
            $table->string('status', 20)->default('ACTIVE');
            // MANUAL por ahora; la compra y las licencias llegarán con S5 y S8
            $table->string('source', 20)->default('MANUAL');
            $table->foreignUuid('enrolled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('enrolled_at');
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampsTz();

            $table->index(['course_id', 'status']);
            $table->index(['student_id', 'status']);
        });

        // RN-004: una sola matrícula activa por alumno y curso; una cancelada no impide volver a matricular
        DB::statement("CREATE UNIQUE INDEX enrollments_one_active ON enrollments (course_id, student_id) WHERE status = 'ACTIVE'");

        Rls::enable('enrollments');
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollments');
    }
};
