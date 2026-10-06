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
        // Personas de una empresa cliente: sus trabajadores y quienes la administran en el portal
        Schema::create('corporate_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('corporate_account_id')->constrained('corporate_accounts')->cascadeOnDelete();
            // Una persona pertenece a una sola empresa
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            // Responsable de empresa: administra las licencias en el portal
            $table->boolean('is_manager')->default(false);
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['corporate_account_id']);
        });

        // Una licencia es un cupo de un curso para un trabajador de la empresa (RN-010)
        Schema::create('licenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('corporate_account_id')->constrained('corporate_accounts');
            // Producto licenciado (Maestro §6): hoy solo cursos; los paquetes llegan con S11
            $table->foreignUuid('course_id')->constrained('courses');
            // Venta que la originó
            $table->foreignUuid('order_id')->constrained('orders');
            // AVAILABLE, ASSIGNED, CANCELLED
            $table->string('status', 20)->default('AVAILABLE');
            // Hasta cuándo se puede asignar
            $table->timestampTz('expires_at');
            $table->timestampsTz();

            $table->index(['corporate_account_id', 'course_id', 'status']);
            $table->index(['order_id']);
        });

        Schema::create('license_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('license_id')->constrained('licenses');
            $table->foreignUuid('student_id')->constrained('users');
            // Matrícula que creó la asignación
            $table->foreignUuid('enrollment_id')->nullable()->constrained('enrollments')->nullOnDelete();
            $table->foreignUuid('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('assigned_at');
            // Retirada: la licencia volvió a quedar disponible
            $table->timestampTz('revoked_at')->nullable();
            $table->foreignUuid('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['student_id']);
        });

        // Una licencia tiene un solo titular a la vez; las asignaciones retiradas quedan como historial
        DB::statement('CREATE UNIQUE INDEX license_assignments_one_active ON license_assignments (license_id) WHERE revoked_at IS NULL');

        Schema::table('orders', function (Blueprint $table) {
            // Venta corporativa cuyas licencias se entregaron antes del pago, y por qué
            $table->timestampTz('licenses_released_at')->nullable();
            $table->foreignUuid('licenses_released_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('licenses_release_reason', 500)->nullable();
        });

        foreach (['corporate_members', 'licenses', 'license_assignments'] as $table) {
            Rls::enable($table);
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('licenses_released_by');
            $table->dropColumn(['licenses_released_at', 'licenses_release_reason']);
        });

        Schema::dropIfExists('license_assignments');
        Schema::dropIfExists('licenses');
        Schema::dropIfExists('corporate_members');
    }
};
