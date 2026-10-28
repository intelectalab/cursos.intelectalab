<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dónde aparece el enlace de cada página, además del pie (show_in_footer), y en qué orden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            // Menú superior del sitio público (portada, catálogo)
            $table->boolean('show_in_header')->default(false);
            // Pantallas de inicio de sesión y de registro
            $table->boolean('show_in_auth')->default(false);
            // Menú de los alumnos dentro del sistema
            $table->boolean('show_in_student_menu')->default(false);
            // Posición del enlace: de menor a mayor; a igual número, por título
            $table->unsignedSmallInteger('menu_order')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn(['show_in_header', 'show_in_auth', 'show_in_student_menu', 'menu_order']);
        });
    }
};
