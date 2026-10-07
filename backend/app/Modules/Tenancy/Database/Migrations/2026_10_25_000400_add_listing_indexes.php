<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Índices para consultas que ya existen y crecerán con el uso (S12):
     * ítems de una orden, listados por fecha y contenido de un curso.
     */
    public function up(): void
    {
        Schema::table('order_items', fn (Blueprint $table) => $table->index('order_id'));
        Schema::table('orders', fn (Blueprint $table) => $table->index(['tenant_id', 'paid_at']));
        Schema::table('enrollments', fn (Blueprint $table) => $table->index(['tenant_id', 'enrolled_at']));
        Schema::table('course_resources', fn (Blueprint $table) => $table->index('course_id'));
        Schema::table('course_activities', fn (Blueprint $table) => $table->index('course_id'));
    }

    public function down(): void
    {
        Schema::table('order_items', fn (Blueprint $table) => $table->dropIndex(['order_id']));
        Schema::table('orders', fn (Blueprint $table) => $table->dropIndex(['tenant_id', 'paid_at']));
        Schema::table('enrollments', fn (Blueprint $table) => $table->dropIndex(['tenant_id', 'enrolled_at']));
        Schema::table('course_resources', fn (Blueprint $table) => $table->dropIndex(['course_id']));
        Schema::table('course_activities', fn (Blueprint $table) => $table->dropIndex(['course_id']));
    }
};
