<?php

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Un registro por cada recurso o actividad que el alumno completó
        Schema::create('enrollment_progress', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('enrollment_id')->constrained('enrollments')->cascadeOnDelete();
            // RESOURCE o ACTIVITY
            $table->string('item_type', 20);
            // Identificador dentro de la versión publicada; sin FK porque el borrador puede haberlo eliminado
            $table->uuid('item_id');
            $table->timestampTz('completed_at');

            $table->unique(['enrollment_id', 'item_type', 'item_id']);
        });

        Rls::enable('enrollment_progress');
    }

    public function down(): void
    {
        Schema::dropIfExists('enrollment_progress');
    }
};
