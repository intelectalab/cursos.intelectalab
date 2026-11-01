<?php

namespace App\Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Invoicing\Application\InvoiceIssuer;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Http\InvoicePresenter;
use App\Modules\Sales\Domain\Billing;
use App\Modules\Sales\Domain\Models\Order;
use App\Shared\Http\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Los comprobantes de una orden: el personal los ve, corrige los datos del
 * comprador y reintenta; el comprador ve y descarga los suyos.
 */
class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceIssuer $issuer,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Order $order): JsonResponse
    {
        return ApiResponse::success($this->rows($order, staff: true), ['billing' => $order->billing]);
    }

    public function mine(Request $request, Order $order): JsonResponse
    {
        $this->assertOwner($request, $order);

        return ApiResponse::success($this->rows($order, staff: false));
    }

    public function pdf(Invoice $invoice): Response
    {
        return $this->download($invoice);
    }

    public function minePdf(Request $request, Invoice $invoice): Response
    {
        $this->assertOwner($request, $invoice->order);

        return $this->download($invoice);
    }

    public function retry(Invoice $invoice): JsonResponse
    {
        return ApiResponse::success(InvoicePresenter::row($this->issuer->retry($invoice), staff: true));
    }

    /**
     * Corrige los datos del comprobante (por ejemplo, el DNI que faltaba) antes de reintentar.
     */
    public function updateBilling(Request $request, Order $order): JsonResponse
    {
        $type = is_string($request->input('billing.doc_type')) ? $request->input('billing.doc_type') : null;
        $data = $request->validate(['billing' => ['required', 'array']] + Billing::rules($type), Billing::messages());

        $before = $order->billing;
        $order->forceFill(['billing' => Billing::from($data['billing'], $order->customer)])->save();
        $this->audit->record('orders.billing_updated', $order, ['billing' => $before], ['billing' => $order->billing]);

        return ApiResponse::success(['billing' => $order->billing]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(Order $order, bool $staff): array
    {
        return Invoice::query()->where('order_id', $order->id)->orderBy('created_at')->get()
            ->map(fn (Invoice $invoice) => InvoicePresenter::row($invoice, $staff))
            ->values()
            ->all();
    }

    private function download(Invoice $invoice): Response
    {
        $pdf = $this->issuer->pdf($invoice);

        if ($pdf === null) {
            throw new ApiException(ErrorCode::NotFound, 'El PDF del comprobante todavía no está disponible.', 404);
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$invoice->code().'.pdf"',
        ]);
    }

    private function assertOwner(Request $request, ?Order $order): void
    {
        // Otra persona no sabe ni que existe
        if ($order === null || $order->customer_id !== $request->user()->id) {
            throw new ApiException(ErrorCode::NotFound, 'El recurso solicitado no existe.', 404);
        }
    }
}
