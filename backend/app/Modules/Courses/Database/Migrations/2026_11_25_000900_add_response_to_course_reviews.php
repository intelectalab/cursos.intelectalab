<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_reviews', function (Blueprint $table) {
            // Respuesta pública de la academia a la reseña
            $table->text('response')->nullable();
            $table->foreignUuid('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('responded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('course_reviews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responded_by');
            $table->dropColumn(['response', 'responded_at']);
        });
    }
};
