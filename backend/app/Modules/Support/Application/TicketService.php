<?php

namespace App\Modules\Support\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Enrollments\Domain\Models\Enrollment;
use App\Modules\Files\Application\FileStorage;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Sales\Domain\Models\Order;
use App\Modules\Support\Domain\Models\Ticket;
use App\Modules\Support\Domain\Models\TicketMessage;
use App\Modules\Tenancy\Application\StaffNotifier;
use App\Modules\Tenancy\Application\TenantNotifier;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Tickets de soporte (RF-SUP-001, FP-17): crear → asignar → responder →
 * resolver → cerrar o reabrir.
 */
class TicketService
{
    /** Permisos con los que alguien puede atender tickets */
    private const AGENT_PERMISSIONS = ['*', 'tickets.*', 'tickets.manage'];

    public function __construct(
        private readonly TenantNotifier $notifier,
        private readonly StaffNotifier $staff,
        private readonly FileStorage $storage,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Personal activo que puede atender tickets.
     *
     * @return Builder<User>
     */
    public function agents(): Builder
    {
        return User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->whereHas('roles.permissions', fn (Builder $permissions) => $permissions->whereIn('code', self::AGENT_PERMISSIONS))
            ->orderBy('first_name')
            ->orderBy('last_name');
    }

    /**
     * @param  array{subject: string, category: string, body: string, course_id?: ?string, order_id?: ?string}  $data
     * @param  list<UploadedFile>  $files
     */
    public function open(User $requester, array $data, array $files = []): Ticket
    {
        $this->assertOwnReferences($requester, $data);

        return DB::transaction(function () use ($requester, $data, $files) {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ['tickets:'.$this->context->tenantId()]);
            }

            $ticket = Ticket::create([
                'number' => (int) Ticket::query()->max('number') + 1,
                'requester_id' => $requester->id,
                'subject' => $data['subject'],
                'category' => $data['category'],
                'priority' => 'NORMAL',
                'status' => Ticket::STATUS_OPEN,
                'course_id' => $data['course_id'] ?? null,
                'order_id' => $data['order_id'] ?? null,
                'last_message_at' => now(),
                'last_message_by' => Ticket::BY_REQUESTER,
            ]);

            $this->addMessage($ticket, $requester, $data['body'], $files, staff: false, internal: false);

            $this->audit->record('tickets.created', $ticket, null, [
                'number' => $ticket->number,
                'subject' => $ticket->subject,
                'category' => $ticket->category,
            ]);

            // Aviso a quienes atienden soporte
            $this->staff->notify(self::AGENT_PERMISSIONS, 'personal_consulta_nueva', $this->noticeValues($ticket, $requester), '/support', $requester->id);

            return $ticket;
        });
    }

    /**
     * Respuesta de quien abrió el ticket. Si ya estaba resuelto, se reabre.
     *
     * @param  list<UploadedFile>  $files
     */
    public function replyAsRequester(Ticket $ticket, User $author, string $body, array $files = []): Ticket
    {
        $this->assertNotClosed($ticket);

        return DB::transaction(function () use ($ticket, $author, $body, $files) {
            $this->addMessage($ticket, $author, $body, $files, staff: false, internal: false);

            $reopened = $ticket->status === Ticket::STATUS_RESOLVED;

            $ticket->update([
                'last_message_at' => now(),
                'last_message_by' => Ticket::BY_REQUESTER,
            ] + ($reopened ? [
                'status' => $ticket->assignee_id ? Ticket::STATUS_IN_PROGRESS : Ticket::STATUS_OPEN,
                'resolved_at' => null,
            ] : []));

            if ($reopened) {
                $this->audit->record('tickets.reopened', $ticket, ['status' => Ticket::STATUS_RESOLVED], ['status' => $ticket->status, 'number' => $ticket->number]);
            }

            // A quien la tiene asignada; si nadie, a todo el personal de soporte
            $ticket->loadMissing('assignee');
            $ticket->assignee?->isActive()
                ? $this->notifier->notify($ticket->assignee, 'personal_consulta_mensaje', $this->noticeValues($ticket, $author), '/support')
                : $this->staff->notify(self::AGENT_PERMISSIONS, 'personal_consulta_mensaje', $this->noticeValues($ticket, $author), '/support', $author->id);

            return $ticket;
        });
    }

    /**
     * Datos de una consulta para los avisos al personal.
     *
     * @return array<string, string>
     */
    private function noticeValues(Ticket $ticket, User $person): array
    {
        return [
            'numero' => '#'.str_pad((string) $ticket->number, 4, '0', STR_PAD_LEFT),
            'asunto' => $ticket->subject,
            'persona' => trim("{$person->first_name} {$person->last_name}"),
        ];
    }

    /**
     * Respuesta del personal. La primera respuesta pone el ticket en atención
     * y, si nadie lo tenía, se lo asigna a quien responde. Una nota interna
     * no cambia nada de eso ni la ve quien consulta.
     *
     * @param  list<UploadedFile>  $files
     */
    public function replyAsStaff(Ticket $ticket, User $author, string $body, array $files = [], bool $internal = false): Ticket
    {
        $this->assertNotClosed($ticket);

        return DB::transaction(function () use ($ticket, $author, $body, $files, $internal) {
            $this->addMessage($ticket, $author, $body, $files, staff: true, internal: $internal);

            if (! $internal) {
                $ticket->update([
                    'last_message_at' => now(),
                    'last_message_by' => Ticket::BY_STAFF,
                    'status' => $ticket->status === Ticket::STATUS_OPEN ? Ticket::STATUS_IN_PROGRESS : $ticket->status,
                    'assignee_id' => $ticket->assignee_id ?? $author->id,
                ]);

                $ticket->loadMissing('requester');
                $this->notifier->notify($ticket->requester, 'respuesta_soporte', [
                    'numero' => '#'.str_pad((string) $ticket->number, 4, '0', STR_PAD_LEFT),
                    'asunto' => $ticket->subject,
                ], '/help');
            }

            return $ticket;
        });
    }

    /**
     * El personal cambia el estado. Un ticket cerrado ya no se mueve: quien
     * consulta abre otro.
     */
    public function changeStatus(Ticket $ticket, string $status): Ticket
    {
        $this->assertNotClosed($ticket);

        if ($ticket->status === $status) {
            return $ticket;
        }

        $before = $ticket->status;

        $ticket->update([
            'status' => $status,
            'resolved_at' => match ($status) {
                Ticket::STATUS_RESOLVED => now(),
                Ticket::STATUS_CLOSED => $ticket->resolved_at,
                default => null,
            },
            'closed_at' => $status === Ticket::STATUS_CLOSED ? now() : null,
        ]);

        $this->audit->record('tickets.status_changed', $ticket, ['status' => $before], ['status' => $status, 'number' => $ticket->number]);

        return $ticket;
    }

    /**
     * Quien abrió el ticket lo da por terminado.
     */
    public function closeByRequester(Ticket $ticket): Ticket
    {
        return $this->changeStatus($ticket, Ticket::STATUS_CLOSED);
    }

    /**
     * @param  array{assignee_id?: ?string, priority?: string, category?: string}  $data
     */
    public function update(Ticket $ticket, array $data): Ticket
    {
        $this->assertNotClosed($ticket);

        if (isset($data['assignee_id']) && ! $this->agents()->whereKey($data['assignee_id'])->exists()) {
            throw new ApiException(ErrorCode::ValidationError, 'La persona elegida no atiende tickets.', 422, [
                'assignee_id' => ['Elige a alguien del equipo de soporte.'],
            ]);
        }

        $before = $ticket->only(['assignee_id', 'priority', 'category']);
        $ticket->update($data);

        if ($ticket->wasChanged()) {
            $this->audit->record('tickets.updated', $ticket, $before, $ticket->only(['assignee_id', 'priority', 'category']) + ['number' => $ticket->number]);
        }

        return $ticket;
    }

    /**
     * Cierra los tickets de la organización actual que llevan resueltos más
     * de AUTO_CLOSE_DAYS días sin respuesta.
     *
     * @return int cantidad de tickets cerrados
     */
    public function closeStale(): int
    {
        $stale = Ticket::query()
            ->where('status', Ticket::STATUS_RESOLVED)
            ->where('resolved_at', '<=', now()->subDays(Ticket::AUTO_CLOSE_DAYS))
            ->get();

        foreach ($stale as $ticket) {
            $ticket->update(['status' => Ticket::STATUS_CLOSED, 'closed_at' => now()]);

            $this->audit->record('tickets.auto_closed', $ticket, ['status' => Ticket::STATUS_RESOLVED], ['status' => Ticket::STATUS_CLOSED, 'number' => $ticket->number]);
        }

        return $stale->count();
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function addMessage(Ticket $ticket, User $author, string $body, array $files, bool $staff, bool $internal): TicketMessage
    {
        $message = $ticket->messages()->create([
            'author_id' => $author->id,
            'body' => $body,
            'is_staff' => $staff,
            'is_internal' => $internal,
        ]);

        foreach ($files as $file) {
            $message->attachments()->create(['file_id' => $this->storage->store($file, Ticket::FILE_PURPOSE)->id]);
        }

        return $message;
    }

    private function assertNotClosed(Ticket $ticket): void
    {
        if ($ticket->isClosed()) {
            throw new ApiException(ErrorCode::TicketClosed, 'Este ticket está cerrado. Si el problema continúa, abre uno nuevo.', 409);
        }
    }

    /**
     * El curso o la orden a los que se refiere el ticket deben ser de quien lo abre.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertOwnReferences(User $requester, array $data): void
    {
        $errors = [];

        if (! empty($data['course_id']) && ! Enrollment::query()->where('student_id', $requester->id)->where('course_id', $data['course_id'])->exists()) {
            $errors['course_id'] = ['Elige uno de tus cursos.'];
        }

        if (! empty($data['order_id']) && ! Order::query()->where('customer_id', $requester->id)->whereKey($data['order_id'])->exists()) {
            $errors['order_id'] = ['Elige una de tus compras.'];
        }

        if ($errors !== []) {
            throw new ApiException(ErrorCode::ValidationError, 'La referencia del ticket no es válida.', 422, $errors);
        }
    }
}
