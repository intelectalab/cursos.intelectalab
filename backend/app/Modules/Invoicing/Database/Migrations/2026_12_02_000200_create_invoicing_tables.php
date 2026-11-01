<?php

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Datos con los que la academia emite sus comprobantes electrónicos y su cuenta del proveedor (Mifact)
        Schema::create('invoicing_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained('tenants');
            // Si se emiten comprobantes al confirmarse cada pago
            $table->boolean('enabled')->default(false);
            // Dirección del servicio del proveedor: la de pruebas o la de producción que entrega Mifact
            $table->string('service_url', 200);
            // RUC del emisor (11 dígitos)
            $table->string('ruc', 11);
            // Razón social del emisor, tal como figura en SUNAT
            $table->string('legal_name', 200);
            // Nombre comercial (opcional)
            $table->string('trade_name', 200)->nullable();
            // Domicilio fiscal del emisor
            $table->string('address', 250);
            // Ubigeo INEI del domicilio fiscal (6 dígitos)
            $table->string('ubigeo', 6);
            // Código del establecimiento anexo en SUNAT (0000 = domicilio fiscal)
            $table->string('establishment_code', 4)->default('0000');
            // Serie de las boletas (B001)
            $table->string('boleta_series', 4);
            // Serie de las facturas (F001)
            $table->string('factura_series', 4);
            // IGV de los cursos: INCLUDED (el precio incluye el 18 %) o EXONERATED
            $table->string('igv_mode', 12)->default('INCLUDED');
            // Token del emisor en el proveedor, cifrado con la clave de la aplicación
            $table->text('token');
            // Último error de conexión o de envío
            $table->string('last_error', 500)->nullable();
            // Cuándo fue la última emisión aceptada
            $table->timestampTz('last_ok_at')->nullable();
            $table->timestampsTz();
        });
        Rls::enable('invoicing_settings');

        // Correlativo de cada serie: se toma con bloqueo para que no se repita
        Schema::create('invoice_series', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            // Tipo de comprobante SUNAT: 01 factura, 03 boleta, 07 nota de crédito
            $table->string('document_type', 2);
            // Serie (B001, F001…)
            $table->string('series', 4);
            // Último número usado en la serie
            $table->unsignedInteger('last_number')->default(0);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'document_type', 'series']);
        });
        Rls::enable('invoice_series');

        // Comprobantes electrónicos emitidos por cada pago
        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('order_id')->constrained('orders');
            // Pago que respalda (uno por pago: también cada cuota)
            $table->foreignUuid('payment_id')->constrained('payments');
            // Tipo SUNAT (01 factura, 03 boleta)
            $table->string('document_type', 2);
            // Serie del comprobante (B001, F001…)
            $table->string('series', 4);
            // Número correlativo dentro de la serie
            $table->unsignedInteger('number');
            // Fecha de emisión
            $table->date('issue_date');
            // Moneda (PEN o USD)
            $table->string('currency', 3);
            // Tipo de cambio cuando no es en soles
            $table->decimal('exchange_rate', 10, 3)->nullable();
            // Base: valor gravado (sin IGV) o exonerado
            $table->decimal('taxable_amount', 12, 2);
            // IGV del comprobante (0 si la academia está exonerada)
            $table->decimal('igv_amount', 12, 2);
            // Total cobrado (el importe del pago)
            $table->decimal('total_amount', 12, 2);
            // INCLUDED o EXONERATED, como estaba la academia al emitir
            $table->string('igv_mode', 12);
            // Tipo de documento del receptor en SUNAT: 0 sin documento, 1 DNI, 4 CE, 6 RUC, 7 pasaporte
            $table->string('customer_doc_type', 1);
            // Número de documento del receptor (- si no tiene)
            $table->string('customer_doc_number', 15);
            // Nombre o razón social del receptor
            $table->string('customer_name', 200);
            // Dirección fiscal del receptor (en facturas)
            $table->string('customer_address', 250)->nullable();
            // Concepto que figura en el comprobante
            $table->string('description', 250);
            // PENDING, PROCESSING, ACCEPTED, OBSERVED, REJECTED o ERROR
            $table->string('status', 12);
            // Estado que devolvió el proveedor (101 en proceso, 102 aceptado, 103 observado, 104 rechazado)
            $table->string('provider_status', 3)->nullable();
            // Mensaje de SUNAT o error del proveedor
            $table->string('provider_message', 500)->nullable();
            // Código hash del comprobante (va en la representación impresa)
            $table->string('hash', 100)->nullable();
            // Intentos de envío automáticos
            $table->unsignedSmallInteger('attempts')->default(0);
            // Última vez que se envió o se consultó su estado
            $table->timestampTz('checked_at')->nullable();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'document_type', 'series', 'number']);
            $table->index(['tenant_id', 'order_id']);
            $table->index(['tenant_id', 'status']);
        });
        Rls::enable('invoices');
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoice_series');
        Schema::dropIfExists('invoicing_settings');
    }
};
