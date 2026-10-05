<?php

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 150);
            // Subdominio de la organización: {slug}.{dominio central}
            $table->string('slug', 40)->unique();
            $table->string('legal_name', 200)->nullable();
            $table->string('tax_id', 30)->nullable();
            $table->string('status', 30)->default('ACTIVE');
            $table->timestampsTz();
        });

        Schema::create('tenant_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('key', 120);
            $table->jsonb('value')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'key']);
        });

        Rls::enable('tenant_settings');
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_settings');
        Schema::dropIfExists('tenants');
    }
};
