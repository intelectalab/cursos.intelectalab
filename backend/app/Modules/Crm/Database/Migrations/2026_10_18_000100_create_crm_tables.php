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
        // Empresa: una sola ficha desde que es prospecto hasta que compra (FP-21)
        Schema::create('companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('name', 200);
            // RUC u otro identificador tributario
            $table->string('tax_id', 20)->nullable();
            $table->string('industry', 100)->nullable();
            $table->string('website', 200)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('address', 250)->nullable();
            $table->string('city', 100)->nullable();
            $table->text('notes')->nullable();
            // PROSPECT, CUSTOMER (pasa a cliente con su primera venta)
            $table->string('status', 20)->default('PROSPECT');
            $table->foreignUuid('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['tenant_id', 'status']);
        });

        // Una empresa por identificador tributario dentro de la organización
        DB::statement('CREATE UNIQUE INDEX companies_tenant_tax_id ON companies (tenant_id, tax_id) WHERE tax_id IS NOT NULL');

        Schema::create('contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('company_id')->constrained('companies')->cascadeOnDelete();
            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->string('position', 120)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('phone', 30)->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestampsTz();

            $table->index(['company_id']);
        });

        Schema::create('opportunities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('company_id')->constrained('companies');
            $table->foreignUuid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('title', 200);
            // NEW, CONTACTED, PROPOSAL, NEGOTIATION, WON, LOST
            $table->string('stage', 20)->default('NEW');
            // Estimación del vendedor en la moneda de la organización; el importe real lo fija la cotización
            $table->decimal('estimated_amount', 12, 2)->nullable();
            $table->date('expected_close_date')->nullable();
            $table->text('notes')->nullable();
            $table->string('lost_reason', 500)->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->foreignUuid('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['tenant_id', 'stage']);
            $table->index(['company_id']);
        });

        // Seguimiento de la oportunidad: llamadas, reuniones, correos, notas y cambios de etapa
        Schema::create('opportunity_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('opportunity_id')->constrained('opportunities')->cascadeOnDelete();
            // CALL, MEETING, EMAIL, NOTE; STAGE lo registra el sistema
            $table->string('type', 20);
            $table->text('notes');
            $table->timestampTz('occurred_at');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['opportunity_id', 'occurred_at']);
        });

        // Prospecto: una persona interesada, antes de saber si habrá negocio
        Schema::create('leads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('first_name', 100);
            $table->string('last_name', 100)->nullable();
            $table->string('company_name', 200)->nullable();
            $table->string('position', 120)->nullable();
            $table->string('email', 150)->nullable();
            $table->string('phone', 30)->nullable();
            // WEB, REFERRAL, EVENT, CALL, SOCIAL, OTHER
            $table->string('source', 20)->default('OTHER');
            $table->text('interest')->nullable();
            // NEW, CONTACTED, CONVERTED, DISCARDED
            $table->string('status', 20)->default('NEW');
            $table->string('discard_reason', 500)->nullable();
            $table->foreignUuid('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Rastro de la conversión: en qué empresa, contacto y oportunidad terminó
            $table->timestampTz('converted_at')->nullable();
            $table->foreignUuid('converted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('company_id')->nullable()->constrained('companies')->nullOnDelete();
            $table->foreignUuid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignUuid('opportunity_id')->nullable()->constrained('opportunities')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['tenant_id', 'status']);
        });

        foreach (['companies', 'contacts', 'opportunities', 'opportunity_activities', 'leads'] as $table) {
            Rls::enable($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
        Schema::dropIfExists('opportunity_activities');
        Schema::dropIfExists('opportunities');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('companies');
    }
};
