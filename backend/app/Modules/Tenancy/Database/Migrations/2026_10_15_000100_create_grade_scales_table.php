<?php

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Escalas de notas guardadas por la organización, además de las del sistema (20 y 100)
        Schema::create('grade_scales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('name', 80);
            // Nota máxima: las notas se muestran de 0 a este valor
            $table->unsignedSmallInteger('max_score');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'name']);
            $table->unique(['tenant_id', 'max_score']);
        });

        Rls::enable('grade_scales');
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_scales');
    }
};
