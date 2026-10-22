<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            // Asistencia mínima a las clases en vivo (en %) para recibir el certificado; vacío = no se exige
            $table->unsignedSmallInteger('min_attendance_percent')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('min_attendance_percent');
        });
    }
};
