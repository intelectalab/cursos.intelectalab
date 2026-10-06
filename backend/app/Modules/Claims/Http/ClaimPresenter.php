<?php

namespace App\Modules\Claims\Http;

use App\Modules\Claims\Domain\Models\Claim;
use App\Modules\Claims\Domain\Models\ClaimMessage;
use App\Modules\Claims\Domain\Models\ClaimStatusChange;
use App\Modules\Identity\Domain\Models\User;

class ClaimPresenter
{
    /**
     * Fila de la lista del personal.
     *
     * @return array<string, mixed>
     */
    public static function summary(Claim $claim): array
    {
        return [
            'id' => $claim->id,
            'code' => $claim->code,
            'type' => $claim->type,
            'status' => $claim->status,
            'consumer' => ['name' => $claim->consumerName(), 'doc_type' => $claim->doc_type, 'doc_number' => $claim->doc_number],
            'good_description' => $claim->good_description,
            'created_at' => $claim->created_at->toIso8601String(),
            'due_at' => $claim->due_at->toDateString(),
            'answered_at' => $claim->answered_at?->toIso8601String(),
            'overdue' => $claim->isOverdue(),
        ];
    }

    /**
     * La hoja completa, tal como la llenó el consumidor, con su seguimiento.
     * Espera history y messages cargados (y sus autores si es para el personal).
     *
     * @param  bool  $forStaff  el personal ve además las notas internas y quién hizo cada cambio
     * @return array<string, mixed>
     */
    public static function make(Claim $claim, bool $forStaff): array
    {
        // La ficha reemplaza el resumen del consumidor por sus datos completos
        return array_replace(self::summary($claim), [
            'consumer' => [
                'name' => $claim->consumerName(),
                'doc_type' => $claim->doc_type,
                'doc_number' => $claim->doc_number,
                'address' => $claim->address,
                'email' => $claim->email,
                'phone' => $claim->phone,
                'is_minor' => $claim->is_minor,
                'guardian_name' => $claim->guardian_name,
                'guardian_doc_number' => $claim->guardian_doc_number,
            ],
            'good' => [
                'type' => $claim->good_type,
                'description' => $claim->good_description,
                'amount' => $claim->amount,
                'currency' => $claim->currency,
                'order_reference' => $claim->order_reference,
            ],
            'detail' => $claim->detail,
            'request' => $claim->request,
            'provider' => $claim->provider,
            'history' => $claim->history->map(fn (ClaimStatusChange $change) => [
                'status' => $change->to_status,
                'at' => $change->created_at->toIso8601String(),
            ] + ($forStaff ? ['by' => self::name($change->author)] : []))->values(),
            // Respuesta formal al consumidor
            'response' => ($response = $claim->messages->firstWhere('is_public', true))
                ? ['body' => $response->body, 'at' => $response->created_at->toIso8601String()] + ($forStaff ? ['by' => self::name($response->author)] : [])
                : null,
        ]) + ($forStaff ? [
            'notes' => $claim->messages->where('is_public', false)->values()->map(fn (ClaimMessage $note) => [
                'id' => $note->id,
                'body' => $note->body,
                'at' => $note->created_at->toIso8601String(),
                'by' => self::name($note->author),
            ]),
        ] : []);
    }

    private static function name(?User $user): ?string
    {
        return $user ? trim("{$user->first_name} {$user->last_name}") : null;
    }
}
