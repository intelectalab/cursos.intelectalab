<?php

namespace App\Modules\Invoicing\Http;

use App\Modules\Invoicing\Domain\Models\Invoice;

final class InvoicePresenter
{
    /**
     * @param  bool  $staff  el personal ve el motivo del proveedor y si se puede reintentar
     * @return array<string, mixed>
     */
    public static function row(Invoice $invoice, bool $staff): array
    {
        return [
            'id' => $invoice->id,
            'code' => $invoice->code(),
            'type' => $invoice->document_type === Invoice::TYPE_FACTURA ? 'FACTURA' : 'BOLETA',
            'status' => $invoice->status,
            'issue_date' => $invoice->issue_date->toDateString(),
            'currency' => $invoice->currency,
            'total' => $invoice->total_amount,
            'igv' => $invoice->igv_amount,
            'customer_name' => $invoice->customer_name,
            'customer_doc_number' => $invoice->customer_doc_number,
            'description' => $invoice->description,
            'has_pdf' => $invoice->isValid(),
        ] + ($staff ? [
            'message' => $invoice->provider_message,
            'attempts' => $invoice->attempts,
            'can_retry' => in_array($invoice->status, [Invoice::STATUS_REJECTED, Invoice::STATUS_ERROR, Invoice::STATUS_PENDING], true),
        ] : []);
    }
}
