<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoicing_settings', function (Blueprint $table) {
            // Serie de las notas de crédito de boletas (empieza con B, como pide SUNAT)
            $table->string('credit_boleta_series', 4)->default('BC01');
            // Serie de las notas de crédito de facturas (empieza con F)
            $table->string('credit_factura_series', 4)->default('FC01');
        });

        Schema::table('invoices', function (Blueprint $table) {
            // En una nota de crédito: la boleta o factura que corrige
            $table->foreignUuid('reference_invoice_id')->nullable()->constrained('invoices');
            // Tipo de nota de crédito (catálogo 09 de SUNAT: 01 anulación de la operación, 06 devolución total)
            $table->string('credit_type', 2)->nullable();
            // Motivo que figura en la nota de crédito, o por qué se pidió anular el comprobante
            $table->string('reason', 100)->nullable();
            // Cuándo SUNAT dio por anulado el comprobante (comunicación de baja aceptada)
            $table->timestampTz('voided_at')->nullable();

            $table->index(['tenant_id', 'reference_invoice_id']);
            $table->index(['tenant_id', 'issue_date']);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'issue_date']);
            $table->dropIndex(['tenant_id', 'reference_invoice_id']);
            $table->dropConstrainedForeignId('reference_invoice_id');
            $table->dropColumn(['credit_type', 'reason', 'voided_at']);
        });

        Schema::table('invoicing_settings', function (Blueprint $table) {
            $table->dropColumn(['credit_boleta_series', 'credit_factura_series']);
        });
    }
};
