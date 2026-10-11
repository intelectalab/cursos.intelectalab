<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificate_templates', function (Blueprint $table) {
            // Diseño libre del editor visual: orientación de la hoja y cada elemento con su posición en milímetros.
            // NULL = se usa el diseño por opciones de la columna design
            $table->jsonb('layout')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('certificate_templates', function (Blueprint $table) {
            $table->dropColumn('layout');
        });
    }
};
