<?php

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        // Tipos de cambio de la organización (RF-CUR-001): cada valor que tuvo, con su fuente. Vale el más reciente.
        Schema::create('exchange_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            // Moneda que se convierte: con USD, el valor dice cuánto cuesta un dólar
            $table->char('base_currency', 3)->default('USD');
            // Moneda en la que se expresa el valor: con PEN, soles por dólar
            $table->char('quote_currency', 3)->default('PEN');
            // Tipo de cambio con el que se cobra, ya con el ajuste aplicado
            $table->decimal('rate', 12, 4);
            // MANUAL = lo escribió una persona; SUNAT = tomado del tipo de cambio oficial
            $table->string('source', 20);
            // Valor que publicó la fuente, antes del ajuste; vacío en los manuales
            $table->decimal('official_rate', 12, 4)->nullable();
            // Recargo sobre lo que se cobra en dólares que se aplicó a ese valor
            $table->decimal('margin_percent', 5, 2)->nullable();
            // Día al que corresponde el tipo de cambio
            $table->date('effective_date');
            // Quién lo registró; vacío = la actualización automática
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['tenant_id', 'base_currency', 'quote_currency', 'effective_date']);
        });

        Rls::enable('exchange_rates');

        // El tipo de cambio que cada organización ya tenía escrito en su cuenta de PayPal pasa a ser su primer registro
        DB::statement("SELECT set_config('app.bypass_rls', 'on', true)");

        foreach (DB::table('payment_gateway_accounts')->whereNotNull('settings')->get(['tenant_id', 'settings', 'updated_at']) as $account) {
            $rate = json_decode((string) $account->settings, true)['exchange_rate'] ?? null;

            if ($rate !== null && ! DB::table('exchange_rates')->where('tenant_id', $account->tenant_id)->exists()) {
                DB::table('exchange_rates')->insert([
                    'id' => (string) Str::uuid7(),
                    'tenant_id' => $account->tenant_id,
                    'rate' => $rate,
                    'source' => 'MANUAL',
                    'effective_date' => substr((string) $account->updated_at, 0, 10),
                    'created_at' => $account->updated_at,
                    'updated_at' => $account->updated_at,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('exchange_rates');
    }
};
