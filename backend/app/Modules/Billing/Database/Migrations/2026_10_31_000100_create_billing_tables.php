<?php

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Lo que cada organización le paga a la plataforma: el catálogo de planes
 * (de la plataforma, sin tenant) y la suscripción vigente de cada organización.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 80);
            $table->string('description', 300)->nullable();
            $table->decimal('price', 10, 2);
            $table->char('currency', 3)->default('PEN');
            // Cada cuántos meses se paga: 1 = mensual, 12 = anual
            $table->unsignedSmallInteger('billing_months')->default(1);
            // Tope de alumnos con matrícula activa; null = sin tope
            $table->unsignedInteger('max_students')->nullable();
            // ACTIVE (se ofrece) o ARCHIVED (ya no se ofrece; quien lo tiene lo conserva)
            $table->string('status', 20)->default('ACTIVE');
            $table->timestampsTz();
        });

        Schema::create('tenant_subscriptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->unique()->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('plan_id')->nullable()->constrained('plans');
            // TRIAL (prueba gratuita) o ACTIVE (con plan, o sin límites si no tiene plan)
            $table->string('status', 20);
            $table->timestampTz('trial_ends_at')->nullable();
            // Hasta cuándo está pagado el plan; null = sin vencimiento
            $table->timestampTz('current_period_ends_at')->nullable();
            // Tope vigente de alumnos: el de la prueba o el del plan al asignarlo; null = sin tope
            $table->unsignedInteger('max_students')->nullable();
            $table->timestampsTz();
        });

        Rls::enable('tenant_subscriptions');

        // Las organizaciones que ya existían no pasan por la prueba: quedan sin
        // límites hasta que la plataforma les asigne un plan desde su ficha.
        $now = now();
        DB::statement("SELECT set_config('app.bypass_rls', 'on', true)");
        DB::table('tenants')->pluck('id')->each(fn (string $tenantId) => DB::table('tenant_subscriptions')->insert([
            'id' => (string) Str::uuid7(),
            'tenant_id' => $tenantId,
            'status' => 'ACTIVE',
            'created_at' => $now,
            'updated_at' => $now,
        ]));
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_subscriptions');
        Schema::dropIfExists('plans');
    }
};
