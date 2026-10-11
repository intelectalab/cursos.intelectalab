<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Paso de revisión del borrador (RF-CMS-001): quien redacta lo envía y quien publica lo aprueba o lo devuelve
        Schema::table('pages', function (Blueprint $table) {
            // NULL = sin revisión pedida; IN_REVIEW = espera a quien publica; CHANGES_REQUESTED = devuelta con observaciones
            $table->string('review_status', 20)->nullable();
            // Quién envió el borrador a revisión y cuándo
            $table->foreignUuid('review_requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('review_requested_at')->nullable();
            // Observaciones de quien revisó, cuando devuelve la página
            $table->text('review_comment')->nullable();
            // Quién devolvió la página y cuándo
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('review_requested_by');
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn(['review_status', 'review_requested_at', 'review_comment', 'reviewed_at']);
        });
    }
};
