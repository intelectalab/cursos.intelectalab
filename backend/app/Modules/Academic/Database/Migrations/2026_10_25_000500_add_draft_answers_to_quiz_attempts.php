<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            // Lo que el alumno lleva marcado en un intento en curso, para retomarlo.
            // Las respuestas definitivas siguen en quiz_answers, al enviar.
            $table->jsonb('draft_answers')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quiz_attempts', function (Blueprint $table) {
            $table->dropColumn('draft_answers');
        });
    }
};
