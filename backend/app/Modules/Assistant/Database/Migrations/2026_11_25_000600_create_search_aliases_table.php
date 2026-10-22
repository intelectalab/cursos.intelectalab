<?php

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lo que aprende el buscador del asistente: «hojas de cálculo» lleva a «Excel para finanzas»
        Schema::create('search_aliases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            // Término normalizado: minúsculas, sin tildes ni signos
            $table->string('term', 100);
            // COURSE o PACKAGE; nunca personas
            $table->string('target_type', 10);
            $table->uuid('target_id');
            $table->unsignedInteger('hits')->default(1);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'term', 'target_type', 'target_id']);
            $table->index(['tenant_id', 'term']);
        });

        Rls::enable('search_aliases');
    }

    public function down(): void
    {
        Schema::dropIfExists('search_aliases');
    }
};
