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
        Schema::table('courses', function (Blueprint $table) {
            // El curso entrega certificado a quien lo completa (RN-007). Regla de la organización, no contenido.
            $table->boolean('issues_certificate')->default(false);
        });

        // Plantilla del certificado: una por organización. El diseño es fijo; se personaliza la firma.
        Schema::create('certificate_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained('tenants');
            $table->string('signer_name', 150)->nullable();
            $table->string('signer_title', 150)->nullable();
            $table->foreignUuid('signature_file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->timestampsTz();
        });

        Schema::create('certificates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            // Matrícula de origen (Maestro §6)
            $table->foreignUuid('enrollment_id')->constrained('enrollments');
            $table->foreignUuid('course_id')->constrained('courses');
            $table->foreignUuid('student_id')->constrained('users');
            // Código público con el que cualquiera verifica el certificado
            $table->string('verification_code', 20)->unique();
            // ISSUED, REVOKED
            $table->string('status', 20)->default('ISSUED');
            // Lo que dice el certificado, tal como se emitió: no cambia si después cambian el curso o el alumno
            $table->jsonb('snapshot');
            $table->timestampTz('issued_at');
            // null = lo emitió el sistema al completarse el curso
            $table->foreignUuid('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignUuid('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('revoke_reason', 500)->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'status']);
            $table->index(['student_id']);
            $table->index(['course_id']);
        });

        // Un solo certificado vigente por matrícula; uno anulado no impide emitir otro
        DB::statement("CREATE UNIQUE INDEX certificates_one_issued ON certificates (enrollment_id) WHERE status = 'ISSUED'");

        // Consultas hechas en la página pública de verificación
        Schema::create('certificate_verifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('certificate_id')->nullable()->constrained('certificates')->cascadeOnDelete();
            $table->string('code', 40);
            // VALID, REVOKED, NOT_FOUND
            $table->string('result', 20);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestampTz('created_at');

            $table->index(['certificate_id']);
        });

        foreach (['certificate_templates', 'certificates', 'certificate_verifications'] as $table) {
            Rls::enable($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('certificate_verifications');
        Schema::dropIfExists('certificates');
        Schema::dropIfExists('certificate_templates');

        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('issues_certificate');
        });
    }
};
