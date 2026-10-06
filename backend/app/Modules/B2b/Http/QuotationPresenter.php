<?php

namespace App\Modules\B2b\Http;

use App\Modules\B2b\Domain\Models\Quotation;
use App\Modules\B2b\Domain\Models\QuotationItem;
use App\Modules\B2b\Domain\Models\QuotationVersion;
use App\Modules\Identity\Domain\Models\User;

class QuotationPresenter
{
    /**
     * Fila de una lista. Espera company y currency cargados.
     *
     * @return array<string, mixed>
     */
    public static function summary(Quotation $quotation): array
    {
        return [
            'id' => $quotation->id,
            'number' => $quotation->number,
            'version' => $quotation->version,
            // Situación de hoy: una enviada fuera de plazo se muestra vencida
            'status' => $quotation->state(),
            'company' => ['id' => $quotation->company->id, 'name' => $quotation->company->name],
            'currency' => $quotation->currency->code,
            'total_amount' => $quotation->total_amount,
            'valid_until' => $quotation->valid_until->toDateString(),
            'created_at' => $quotation->created_at->toIso8601String(),
            'sent_at' => $quotation->sent_at?->toIso8601String(),
            'accepted_at' => $quotation->accepted_at?->toIso8601String(),
        ];
    }

    /**
     * Detalle. Espera además contact, opportunity, items, versions, order, author y accepter.
     *
     * @return array<string, mixed>
     */
    public static function make(Quotation $quotation): array
    {
        return self::summary($quotation) + [
            'contact' => $quotation->contact ? ['id' => $quotation->contact->id, 'name' => $quotation->contact->fullName()] : null,
            'opportunity' => $quotation->opportunity ? ['id' => $quotation->opportunity->id, 'title' => $quotation->opportunity->title] : null,
            'notes' => $quotation->notes,
            'items' => $quotation->items->map(fn (QuotationItem $item) => [
                'course_id' => $item->course_id,
                'title' => $item->title,
                'quantity' => $item->quantity,
                'list_price' => $item->list_price,
                'unit_price' => $item->unit_price,
                'total_amount' => $item->total_amount,
            ])->values(),
            'created_by' => self::name($quotation->author),
            'accepted_by' => self::name($quotation->accepter),
            'accepted_via' => $quotation->accepted_via,
            'acceptance_note' => $quotation->acceptance_note,
            'rejected_at' => $quotation->rejected_at?->toIso8601String(),
            'reject_reason' => $quotation->reject_reason,
            // Venta que generó la aceptación
            'order' => $quotation->order ? [
                'id' => $quotation->order->id,
                'number' => $quotation->order->number,
                'status' => $quotation->order->state(),
            ] : null,
            // Versiones ya enviadas, de la más reciente a la más antigua
            'versions' => $quotation->versions->map(fn (QuotationVersion $version) => [
                'version' => $version->version,
                'total_amount' => $version->total_amount,
                'sent_at' => $version->sent_at->toIso8601String(),
                'valid_until' => $version->snapshot['valid_until'],
                'items' => $version->snapshot['items'],
            ])->values(),
        ];
    }

    private static function name(?User $user): ?string
    {
        return $user ? trim("{$user->first_name} {$user->last_name}") : null;
    }
}
