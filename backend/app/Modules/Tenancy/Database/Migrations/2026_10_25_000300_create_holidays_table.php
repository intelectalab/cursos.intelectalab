<?php

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Feriados de la organización: no cuentan como días hábiles en los plazos
        Schema::create('holidays', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->date('date');
            $table->string('name', 120);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'date']);
        });

        Rls::enable('holidays');
    }

    public function down(): void
    {
        Schema::dropIfExists('holidays');
    }
};
