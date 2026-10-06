<?php

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Paquete: varios cursos que se venden juntos a un precio propio (RF-PKG-001)
        Schema::create('packages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->string('title', 200);
            $table->string('summary', 500)->nullable();
            // Precio del conjunto, en la moneda de la organización
            $table->decimal('price', 10, 2);
            // ACTIVE = en venta; INACTIVE = retirado del catálogo
            $table->string('status', 10)->default('INACTIVE');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('package_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('package_id')->constrained('packages')->cascadeOnDelete();
            $table->foreignUuid('course_id')->constrained('courses');
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestampsTz();

            $table->unique(['package_id', 'course_id']);
        });

        Schema::table('orders', function (Blueprint $table) {
            // Paquete comprado; null cuando la orden es de un curso suelto o de una empresa
            $table->foreignUuid('package_id')->nullable()->constrained('packages');
            // Nombre del paquete al momento de la compra
            $table->string('package_title', 200)->nullable();
            $table->index(['package_id']);
        });

        foreach (['packages', 'package_items'] as $table) {
            Rls::enable($table);
        }
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('package_id');
            $table->dropColumn('package_title');
        });

        Schema::dropIfExists('package_items');
        Schema::dropIfExists('packages');
    }
};
