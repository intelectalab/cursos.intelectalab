<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabla global: no pertenece a ningún tenant (sin tenant_id ni RLS)
        Schema::create('platform_admins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 150);
            $table->string('email', 180)->unique();
            $table->string('password');
            $table->string('status', 30)->default('ACTIVE');
            $table->timestampTz('last_login_at')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_admins');
    }
};
