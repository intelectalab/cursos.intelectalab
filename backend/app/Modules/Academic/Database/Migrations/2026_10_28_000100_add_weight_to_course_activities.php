<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_activities', function (Blueprint $table) {
            // Peso del cuestionario o la tarea en la nota final del curso; 0 = no cuenta
            $table->decimal('weight', 5, 2)->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('course_activities', function (Blueprint $table) {
            $table->dropColumn('weight');
        });
    }
};
