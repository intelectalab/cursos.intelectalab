<?php

namespace App\Modules\Invoicing\Http;

use App\Modules\Invoicing\Domain\Models\Invoice;

final class InvoicePresenter
{
    /** Cómo se llama cada tipo en pantalla */
    public const TYPES = [
        Invoice::TYPE_BOLETA => 'BOLETA',
        Invoice::TYPE_FACTURA => 'FACTURA',
        Invoice::TYPE_CREDIT_NOTE => 'NOTA_CREDITO',
    ];

    /**
     * @param  bool  $staff  el personal ve el motivo del proveedor y qué puede hacer con el comprobante
     * @return array<string, mixed>
     */
    public static function row(Invoice $invoice, bool $staff): array
    {
        // El listado lo trae con withExists; en una orden se pregunta aquí
        $credited = $invoice->isCreditNote() ? false : (bool) ($invoice->getAttribute('credited') ?? $invoice->isCredited());
        $sale = ! $invoice->isCreditNote() && $invoice->isValid() && ! $credited;
        // Una nota de crédito que espera a que SUNAT acepte su comprobante sale sola: no hay nada que reintentar
        $waiting = $invoice->isCreditNote() && $invoice->status === Invoice::STATUS_PENDING && ! $invoice->reference?->isValid();

        return [
            'id' => $invoice->id,
            'code' => $invoice->code(),
            'type' => self::TYPES[$invoice->document_type] ?? $invoice->document_type,
            'status' => $invoice->status,
            'issue_date' => $invoice->issue_date->toDateString(),
            'currency' => $invoice->currency,
            'total' => $invoice->total_amount,
            'igv' => $invoice->igv_amount,
            'customer_name' => $invoice->customer_name,
            'customer_doc_number' => $invoice->customer_doc_number,
            'description' => $invoice->description,
            // En una nota de crédito: el comprobante que corrige y por qué
            'reference_code' => $invoice->reference_invoice_id ? $invoice->reference?->code() : null,
            'reason' => $invoice->reason,
            'credited' => $credited,
            'has_pdf' => $invoice->isValid(),
        ] + ($staff ? [
            'order_id' => $invoice->order_id,
            'order_number' => $invoice->relationLoaded('order') ? $invoice->order?->number : null,
            'message' => $invoice->provider_message,
            // Cuándo se le envió al comprador por correo, con el PDF
            'emailed_at' => $invoice->emailed_at?->toIso8601String(),
            'attempts' => $invoice->attempts,
            'can_retry' => ! $waiting && in_array($invoice->status, [Invoice::STATUS_REJECTED, Invoice::STATUS_ERROR, Invoice::STATUS_PENDING, Invoice::STATUS_VOIDED], true),
            // Dentro del plazo de SUNAT se anula; después, se corrige con una nota de crédito
            'can_void' => $sale && ! $invoice->isPastVoidDeadline(),
            'can_credit' => $sale,
            'void_deadline' => $invoice->isCreditNote() ? null : $invoice->voidDeadline()->toDateString(),
        ] : []);
    }
}
