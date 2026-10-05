<?php

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('actor_user_id')->nullable()->constrained('users');
            $table->string('action', 100);
            $table->string('entity_type', 100);
            $table->uuid('entity_id')->nullable();
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('request_id', 64)->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['tenant_id', 'entity_type', 'entity_id']);
            $table->index(['tenant_id', 'created_at']);
        });

        Rls::enable('audit_events');

        if (DB::connection()->getDriverName() === 'pgsql') {
            // Auditoría inmutable: solo inserción
            DB::unprepared(<<<'SQL'
                CREATE OR REPLACE FUNCTION audit_events_immutable() RETURNS trigger AS $$
                BEGIN
                    RAISE EXCEPTION 'audit_events es de solo inserción';
                END;
                $$ LANGUAGE plpgsql;

                CREATE TRIGGER audit_events_no_update_delete
                    BEFORE UPDATE OR DELETE ON audit_events
                    FOR EACH ROW EXECUTE FUNCTION audit_events_immutable();
            SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_events');

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS audit_events_immutable()');
        }
    }
};
