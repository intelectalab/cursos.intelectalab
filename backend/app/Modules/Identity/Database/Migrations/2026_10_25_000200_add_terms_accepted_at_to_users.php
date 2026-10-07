<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Cuándo aceptó los textos legales quien se registró por su cuenta. La versión
            // aceptada es la que estaba publicada en ese momento (page_versions.published_at).
            $table->timestampTz('terms_accepted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('terms_accepted_at');
        });
    }
};
