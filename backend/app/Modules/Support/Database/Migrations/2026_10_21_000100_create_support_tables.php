<?php

use App\Shared\Tenancy\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            // Correlativo por organización
            $table->unsignedInteger('number');
            $table->foreignUuid('requester_id')->constrained('users');
            $table->string('subject', 200);
            // ACCESS, COURSE, PAYMENT, CERTIFICATE, OTHER
            $table->string('category', 20);
            // LOW, NORMAL, HIGH: la fija el personal
            $table->string('priority', 10)->default('NORMAL');
            // OPEN, IN_PROGRESS, RESOLVED, CLOSED
            $table->string('status', 20)->default('OPEN');
            $table->foreignUuid('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            // A qué se refiere, si quien consulta lo indicó
            $table->foreignUuid('course_id')->nullable()->constrained('courses')->nullOnDelete();
            $table->foreignUuid('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestampTz('resolved_at')->nullable();
            $table->timestampTz('closed_at')->nullable();
            // Último mensaje visible para quien consulta, y de qué lado vino (REQUESTER o STAFF)
            $table->timestampTz('last_message_at');
            $table->string('last_message_by', 10)->default('REQUESTER');
            $table->timestampsTz();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['requester_id']);
            $table->index(['assignee_id']);
        });

        Schema::create('ticket_messages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->foreignUuid('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            // Lo escribió el personal de la organización
            $table->boolean('is_staff')->default(false);
            // Nota interna: quien consulta no la ve
            $table->boolean('is_internal')->default(false);
            $table->timestampsTz();

            $table->index(['ticket_id', 'created_at']);
        });

        Schema::create('ticket_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants');
            $table->foreignUuid('ticket_message_id')->constrained('ticket_messages')->cascadeOnDelete();
            $table->foreignUuid('file_id')->constrained('files');
            $table->timestampsTz();

            $table->index(['ticket_message_id']);
        });

        foreach (['tickets', 'ticket_messages', 'ticket_attachments'] as $table) {
            Rls::enable($table);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_attachments');
        Schema::dropIfExists('ticket_messages');
        Schema::dropIfExists('tickets');
    }
};
