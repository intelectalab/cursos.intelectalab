<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Venta asistida: el personal registra la compra a nombre de un alumno.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Quién del personal registró la venta; vacío = la compró el propio alumno (o es una venta a empresa)
            $table->foreignUuid('sold_by')->nullable()->constrained('users')->nullOnDelete();
            // Motivo del descuento manual que dio el personal (sin cupón)
            $table->string('discount_reason', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sold_by');
            $table->dropColumn('discount_reason');
        });
    }
};
