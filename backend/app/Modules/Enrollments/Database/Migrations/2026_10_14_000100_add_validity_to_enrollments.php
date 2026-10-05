<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            // Vigencia (Maestro §6): fuera de estas fechas no hay acceso. Vacías = sin límite.
            $table->timestampTz('starts_at')->nullable();
            $table->timestampTz('ends_at')->nullable();
            // Momento en que el alumno llegó al 100 % del curso
            $table->timestampTz('completed_at')->nullable();
        });

        Schema::table('courses', function (Blueprint $table) {
            // Días de acceso que recibe cada matrícula nueva. Vacío = no vence.
            // La regla de vigencia no se fija en el código (PD-005): la define cada organización por curso.
            $table->unsignedSmallInteger('access_days')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('access_days');
        });

        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropColumn(['starts_at', 'ends_at', 'completed_at']);
        });
    }
};
