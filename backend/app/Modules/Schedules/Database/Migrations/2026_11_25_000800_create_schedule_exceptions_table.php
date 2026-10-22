<?php

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Días en que un docente no tiene su horario habitual (permiso, viaje…): ese día no hay clase en vivo
        Schema::create('schedule_exceptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('teacher_id')->constrained('users')->cascadeOnDelete();
            $table->date('date');
            $table->string('reason', 150)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'teacher_id', 'date']);
        });

        Rls::enable('schedule_exceptions');
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_exceptions');
    }
};
