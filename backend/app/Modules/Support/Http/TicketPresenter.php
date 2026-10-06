<?php

namespace App\Modules\Support\Http;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Support\Domain\Models\Ticket;
use App\Modules\Support\Domain\Models\TicketAttachment;
use App\Modules\Support\Domain\Models\TicketMessage;

class TicketPresenter
{
    /**
     * Fila de una lista. Espera requester y assignee cargados.
     *
     * @return array<string, mixed>
     */
    public static function summary(Ticket $ticket): array
    {
        return [
            'id' => $ticket->id,
            'number' => $ticket->number,
            'subject' => $ticket->subject,
            'category' => $ticket->category,
            'priority' => $ticket->priority,
            'status' => $ticket->status,
            'requester' => [
                'id' => $ticket->requester_id,
                'name' => self::name($ticket->requester),
                'email' => $ticket->requester?->email,
            ],
            'assignee' => $ticket->assignee ? ['id' => $ticket->assignee->id, 'name' => self::name($ticket->assignee)] : null,
            // Le toca responder al personal
            'awaits_staff' => $ticket->awaitsStaff(),
            'created_at' => $ticket->created_at->toIso8601String(),
            'last_message_at' => $ticket->last_message_at->toIso8601String(),
            'resolved_at' => $ticket->resolved_at?->toIso8601String(),
            'closed_at' => $ticket->closed_at?->toIso8601String(),
        ];
    }

    /**
     * Detalle con la conversación. Espera además course, order y
     * messages.author / messages.attachments.file cargados.
     *
     * @param  bool  $forStaff  el personal ve también las notas internas
     * @return array<string, mixed>
     */
    public static function make(Ticket $ticket, bool $forStaff): array
    {
        return self::summary($ticket) + [
            'course' => $ticket->course ? ['id' => $ticket->course->id, 'title' => $ticket->course->title] : null,
            'order' => $ticket->order ? ['id' => $ticket->order->id, 'number' => $ticket->order->number] : null,
            'messages' => $ticket->messages
                ->filter(fn (TicketMessage $message) => $forStaff || ! $message->is_internal)
                ->values()
                ->map(fn (TicketMessage $message) => [
                    'id' => $message->id,
                    'author' => self::name($message->author),
                    'is_staff' => $message->is_staff,
                    'is_internal' => $message->is_internal,
                    'body' => $message->body,
                    'created_at' => $message->created_at->toIso8601String(),
                    'attachments' => $message->attachments->map(fn (TicketAttachment $attachment) => [
                        'id' => $attachment->id,
                        'name' => $attachment->file->original_name,
                        'size_bytes' => $attachment->file->size_bytes,
                    ])->values(),
                ]),
        ];
    }

    private static function name(?User $user): ?string
    {
        return $user ? trim("{$user->first_name} {$user->last_name}") : null;
    }
}
