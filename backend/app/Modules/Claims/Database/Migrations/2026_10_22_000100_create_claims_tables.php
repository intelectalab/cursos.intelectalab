<?php

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Hoja del Libro de Reclamaciones. Una vez registrada no se edita: solo cambia de estado y recibe respuesta.
        Schema::create('claims', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            // Correlativo por organización y año (RN-018)
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('number');
            // Código que recibe el consumidor: LR-2026-000001
            $table->string('code', 20);
            // RECLAMO = disconformidad con el producto o servicio; QUEJA = malestar con la atención
            $table->string('type', 10);

            // Consumidor
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('doc_type', 12);
            $table->string('doc_number', 20);
            $table->string('address', 250);
            $table->string('email', 150);
            $table->string('phone', 30)->nullable();
            // Menor de edad: datos del padre, madre o apoderado
            $table->boolean('is_minor')->default(false);
            $table->string('guardian_name', 200)->nullable();
            $table->string('guardian_doc_number', 20)->nullable();

            // Bien contratado
            $table->string('good_type', 10);
            $table->string('good_description', 500);
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
            // Número de orden o comprobante, si el consumidor lo indica
            $table->string('order_reference', 60)->nullable();

            $table->text('detail');
            $table->text('request');

            // RECEIVED, IN_REVIEW, ANSWERED
            $table->string('status', 20)->default('RECEIVED');
            // Datos del proveedor tal como figuraban al registrarse
            $table->jsonb('provider');
            // Fecha límite para responder
            $table->date('due_at');
            $table->timestampTz('answered_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'year', 'number']);
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });

        // Estado trazable (RN-018): cada cambio, cuándo y quién
        Schema::create('claim_status_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('claim_id')->constrained('claims')->cascadeOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            // null = lo registró el consumidor desde el formulario público
            $table->foreignUuid('changed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('created_at');

            $table->index(['claim_id', 'created_at']);
        });

        Schema::create('claim_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('claim_id')->constrained('claims')->cascadeOnDelete();
            $table->foreignUuid('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            // true = respuesta al consumidor; false = nota interna del personal
            $table->boolean('is_public')->default(false);
            $table->timestampsTz();

            $table->index(['claim_id', 'created_at']);
        });

        foreach (['claims', 'claim_status_history', 'claim_messages'] as $table) {
            Rls::enable($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_messages');
        Schema::dropIfExists('claim_status_history');
        Schema::dropIfExists('claims');
    }
};
