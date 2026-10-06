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
        // Una orden la compra una persona (B2C) o una empresa (B2B)
        Schema::table('orders', function (Blueprint $table) {
            $table->uuid('customer_id')->nullable()->change();
            $table->foreignUuid('company_id')->nullable()->constrained('companies');
            $table->index(['company_id']);
        });

        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_has_buyer CHECK (customer_id IS NOT NULL OR company_id IS NOT NULL)');

        // Cuenta corporativa: la empresa como cliente. Nace con su primera venta o al darle acceso al portal.
        Schema::create('corporate_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('company_id')->unique()->constrained('companies');
            $table->timestampsTz();
        });

        Schema::create('quotations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            // Correlativo por organización
            $table->unsignedInteger('number');
            $table->foreignUuid('company_id')->constrained('companies');
            $table->foreignUuid('contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->foreignUuid('opportunity_id')->nullable()->constrained('opportunities')->nullOnDelete();
            $table->foreignUuid('currency_id')->constrained('currencies');
            // DRAFT, SENT, ACCEPTED, REJECTED
            $table->string('status', 20)->default('DRAFT');
            // Sube cada vez que se vuelve a editar una cotización ya enviada
            $table->unsignedSmallInteger('version')->default(1);
            // Último día en que el cliente puede aceptarla
            $table->date('valid_until');
            $table->text('notes')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('accepted_at')->nullable();
            $table->foreignUuid('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            // PORTAL = la aceptó el responsable de la empresa; STAFF = la registró el personal
            $table->string('accepted_via', 10)->nullable();
            $table->string('acceptance_note', 500)->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->string('reject_reason', 500)->nullable();
            // Venta que generó la aceptación
            $table->foreignUuid('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['company_id']);
        });

        // Ítems de la versión en curso: un curso y cuántas licencias
        Schema::create('quotation_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('quotation_id')->constrained('quotations')->cascadeOnDelete();
            $table->foreignUuid('course_id')->constrained('courses');
            // Título del curso al cotizar
            $table->string('title', 200);
            $table->unsignedSmallInteger('quantity');
            // Precio de lista del curso al cotizar (referencia) y precio pactado por licencia
            $table->decimal('list_price', 12, 2)->nullable();
            $table->decimal('unit_price', 12, 2);
            $table->decimal('total_amount', 12, 2);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestampsTz();

            $table->unique(['quotation_id', 'course_id']);
        });

        // RN-011: cada versión enviada se conserva tal como la vio el cliente
        Schema::create('quotation_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('quotation_id')->constrained('quotations')->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->jsonb('snapshot');
            $table->decimal('total_amount', 12, 2);
            $table->foreignUuid('sent_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('sent_at');
            $table->timestampsTz();

            $table->unique(['quotation_id', 'version']);
        });

        foreach (['corporate_accounts', 'quotations', 'quotation_items', 'quotation_versions'] as $table) {
            Rls::enable($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_versions');
        Schema::dropIfExists('quotation_items');
        Schema::dropIfExists('quotations');
        Schema::dropIfExists('corporate_accounts');

        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_has_buyer');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('company_id');
        });
    }
};
