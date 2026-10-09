<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reembolso total de una orden pagada (S6): la orden pasa a REFUNDED y su
 * pago guarda cuándo, por qué y con qué referencia se devolvió.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestampTz('refunded_at')->nullable();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->timestampTz('refunded_at')->nullable();
            $table->foreignUuid('refunded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('refund_reason', 500)->nullable();
            // Devolución en la pasarela, o cómo se devolvió el dinero por fuera
            $table->string('refund_reference', 120)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('refunded_by');
            $table->dropColumn(['refunded_at', 'refund_reason', 'refund_reference']);
        });

        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('refunded_at'));
    }
};
