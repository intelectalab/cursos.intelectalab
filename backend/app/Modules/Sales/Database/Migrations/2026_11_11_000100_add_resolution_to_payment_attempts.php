<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cobros en revisión: quién resolvió cada uno, cuándo y cómo. El intento
 * pasa de NEEDS_REVIEW a RESOLVED y deja de avisar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table) {
            // Cuándo una persona dio por resuelto el cobro en revisión
            $table->timestampTz('resolved_at')->nullable();
            // Quién lo resolvió
            $table->foreignUuid('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            // Qué se hizo con el cobro: se devolvió en la pasarela, se aplicó a mano…
            $table->string('resolution_note', 300)->nullable();

            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('payment_attempts', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'status']);
            $table->dropConstrainedForeignId('resolved_by');
            $table->dropColumn(['resolved_at', 'resolution_note']);
        });
    }
};
