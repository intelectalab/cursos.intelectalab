<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificate_templates', function (Blueprint $table) {
            // Diseño propio de la organización: su imagen de fondo y dónde va cada dato
            $table->foreignUuid('background_file_id')->nullable()->constrained('files')->nullOnDelete();
            $table->jsonb('design')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('certificate_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('background_file_id');
            $table->dropColumn('design');
        });
    }
};
