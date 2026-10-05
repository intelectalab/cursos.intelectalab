<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('doc_type', 20)->nullable();
            $table->string('doc_number', 30)->nullable();

            // Un mismo documento no se repite dentro de la organización
            $table->unique(['tenant_id', 'doc_type', 'doc_number']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'doc_type', 'doc_number']);
            $table->dropColumn(['doc_type', 'doc_number']);
        });
    }
};
