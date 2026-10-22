<?php

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Semana tipo de cada docente: la arma el personal y la confirma el docente
        Schema::create('teacher_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('teacher_id')->constrained('users')->cascadeOnDelete();
            // Versión vigente: fecha desde la que vale y sus bloques (día, inicio, fin, tipo, curso, enlace, nota)
            $table->date('valid_from');
            $table->jsonb('blocks');
            // PENDING (espera al docente), CONFIRMED, OBSERVED (el docente pidió cambios)
            $table->string('status', 12)->default('PENDING');
            $table->text('observation')->nullable();
            $table->timestampTz('sent_at');
            $table->foreignUuid('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('responded_at')->nullable();
            // Lo último que el docente confirmó: es lo que ven los alumnos mientras un cambio espera
            $table->date('confirmed_valid_from')->nullable();
            $table->jsonb('confirmed_blocks')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'teacher_id']);
            $table->index(['tenant_id', 'status']);
        });

        Rls::enable('teacher_schedules');
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_schedules');
    }
};
