<?php

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Grupos: listas de personas con nombre que la organización arma una vez y reutiliza
        Schema::create('groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('name', 120);
            $table->string('description', 300)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('group_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('group_id')->constrained('groups')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestampTz('created_at')->nullable();

            $table->unique(['group_id', 'user_id']);
            $table->index('user_id');
        });

        Rls::enable('groups');
        Rls::enable('group_members');

        Schema::table('users', function (Blueprint $table) {
            // Cuenta creada con una contraseña temporal (importación): debe cambiarla al entrar
            $table->boolean('must_change_password')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });

        Schema::dropIfExists('group_members');
        Schema::dropIfExists('groups');
    }
};
