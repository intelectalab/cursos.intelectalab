<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Verificación en dos pasos con una aplicación de códigos (cifrado con la clave de la aplicación)
            $table->text('two_factor_secret')->nullable();
            // Vacío mientras la persona no confirme con un primer código
            $table->timestampTz('two_factor_confirmed_at')->nullable();
            // Huellas de los códigos de respaldo que aún no se usan
            $table->text('two_factor_recovery_codes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_confirmed_at', 'two_factor_recovery_codes']);
        });
    }
};
