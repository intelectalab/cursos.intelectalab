<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Varias plantillas por organización: una es la principal y cada curso puede elegir otra
        Schema::table('certificate_templates', function (Blueprint $table) {
            $table->dropUnique(['tenant_id']);
            // Nombre con el que la organización la reconoce
            $table->string('name', 100)->default('Plantilla principal');
            // La que usan los cursos que no eligieron una
            $table->boolean('is_default')->default(false);
            $table->index(['tenant_id']);
        });

        Schema::table('courses', function (Blueprint $table) {
            // Plantilla de certificado del curso; NULL = la principal de la organización
            $table->foreignUuid('certificate_template_id')->nullable()->constrained('certificate_templates')->nullOnDelete();
        });

        Schema::table('certificates', function (Blueprint $table) {
            // Diseño con el que se emitió, congelado: un cambio posterior de la plantilla no lo altera.
            // NULL = emitido sin plantilla guardada; se dibuja con la plantilla vigente de su curso
            $table->jsonb('design')->nullable();
        });

        DB::statement("SELECT set_config('app.bypass_rls', 'on', true)");

        // La plantilla que cada organización ya tenía pasa a ser la principal
        DB::table('certificate_templates')->update(['is_default' => true]);
        DB::statement('CREATE UNIQUE INDEX certificate_templates_one_default ON certificate_templates (tenant_id) WHERE is_default');

        // Los certificados ya emitidos conservan el diseño que tienen hoy
        DB::statement(<<<'SQL'
            UPDATE certificates c
            SET design = jsonb_build_object(
                'template_id', t.id,
                'template_name', t.name,
                'layout', t.layout,
                'design', t.design,
                'background_file_id', t.background_file_id,
                'signature_file_id', t.signature_file_id
            )
            FROM certificate_templates t
            WHERE t.tenant_id = c.tenant_id
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS certificate_templates_one_default');

        Schema::table('certificates', fn (Blueprint $table) => $table->dropColumn('design'));
        Schema::table('courses', fn (Blueprint $table) => $table->dropConstrainedForeignId('certificate_template_id'));
        Schema::table('certificate_templates', function (Blueprint $table) {
            $table->dropIndex(['tenant_id']);
            $table->dropColumn(['name', 'is_default']);
        });
    }
};
