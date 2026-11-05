<?php

namespace App\Modules\Invoicing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Invoicing\Application\InvoiceIssuer;
use App\Modules\Invoicing\Domain\Models\Invoice;
use App\Modules\Invoicing\Http\InvoicePresenter;
use App\Modules\Invoicing\Jobs\SendInvoiceMail;
use App\Modules\Sales\Domain\Billing;
use App\Modules\Sales\Domain\Models\Order;
use App\Shared\Export\ExcelExport;
use App\Shared\Http\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\DateRange;
use App\Shared\Http\ErrorCode;
use App\Shared\Http\StateCounts;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Los comprobantes: el listado de todos, los de una orden (el personal los
 * ve, corrige los datos del comprador, reintenta, anula o corrige con una
 * nota de crédito) y los del comprador, que ve y descarga los suyos.
 */
class InvoiceController extends Controller
{
    /** Situaciones de las tarjetas que filtran el listado */
    private const FILTERS = [
        'VALID' => [Invoice::STATUS_ACCEPTED, Invoice::STATUS_OBSERVED],
        'IN_PROGRESS' => [Invoice::STATUS_PENDING, Invoice::STATUS_PROCESSING, Invoice::STATUS_VOIDING],
        'PROBLEM' => [Invoice::STATUS_REJECTED, Invoice::STATUS_ERROR],
        'VOIDED' => [Invoice::STATUS_VOIDED, Invoice::STATUS_CANCELLED],
    ];

    private const STATUS_LABELS = [
        Invoice::STATUS_PENDING => 'Por enviar',
        Invoice::STATUS_PROCESSING => 'En SUNAT',
        Invoice::STATUS_ACCEPTED => 'Aceptado',
        Invoice::STATUS_OBSERVED => 'Aceptado con observaciones',
        Invoice::STATUS_REJECTED => 'Rechazado',
        Invoice::STATUS_ERROR => 'No emitido',
        Invoice::STATUS_VOIDING => 'Anulación pedida',
        Invoice::STATUS_VOIDED => 'Anulado',
        Invoice::STATUS_CANCELLED => 'Sin emitir (pago devuelto)',
    ];

    private const TYPE_LABELS = ['BOLETA' => 'Boleta', 'FACTURA' => 'Factura', 'NOTA_CREDITO' => 'Nota de crédito'];

    /** Cómo se nombra cada archivo en los mensajes */
    private const FILE_NAMES = ['pdf' => 'El PDF', 'xml' => 'El XML', 'cdr' => 'La constancia de SUNAT'];

    public function __construct(
        private readonly InvoiceIssuer $issuer,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Todos los comprobantes de la academia, con filtros y cuántos hay en cada situación.
     */
    public function all(Request $request): JsonResponse
    {
        $filters = $request->validate($this->listRules());
        $query = $this->search($filters);
        $counts = StateCounts::of($query, array_keys(self::FILTERS), fn (Builder $q, string $state) => $q->whereIn('status', self::FILTERS[$state]));

        $page = $this->ordered($query, $filters)->paginate($filters['per_page'] ?? 50);

        return ApiResponse::paginated($page, fn (Invoice $invoice) => InvoicePresenter::row($invoice, staff: true), ['counts' => $counts]);
    }

    /**
     * El listado con los mismos filtros, en el formato pedido.
     */
    public function export(Request $request): BinaryFileResponse
    {
        $filters = $request->validate($this->listRules());

        return ExcelExport::download('comprobantes', $this->ordered($this->search($filters), $filters), [
            'Comprobante', 'Tipo', 'Fecha', 'Cliente', 'Documento', 'Moneda', 'Base', 'IGV', 'Total', 'Estado', 'Corrige a', 'Motivo', 'Orden',
        ], fn (Invoice $invoice) => [
            $invoice->code(),
            self::TYPE_LABELS[InvoicePresenter::TYPES[$invoice->document_type]] ?? $invoice->document_type,
            $invoice->issue_date->toDateString(),
            $invoice->customer_name,
            $invoice->customer_doc_number === '-' ? null : $invoice->customer_doc_number,
            $invoice->currency,
            (float) $invoice->taxable_amount,
            (float) $invoice->igv_amount,
            // Una nota de crédito resta
            ($invoice->isCreditNote() ? -1 : 1) * (float) $invoice->total_amount,
            self::STATUS_LABELS[$invoice->status] ?? $invoice->status,
            $invoice->reference?->code(),
            $invoice->reason,
            $invoice->order ? '#'.str_pad((string) $invoice->order->number, 5, '0', STR_PAD_LEFT) : null,
        ], 'Comprobantes electrónicos');
    }

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
        return $this->download($invoice, 'pdf');
    }

    public function minePdf(Request $request, Invoice $invoice): Response
    {
        $this->assertOwner($request, $invoice->order);

        return $this->download($invoice, 'pdf');
    }

    /**
     * El XML firmado o la constancia de SUNAT (CDR), los dos en ZIP.
     */
    public function file(Invoice $invoice, string $kind): Response
    {
        return $this->download($invoice, $kind);
    }

    public function mineFile(Request $request, Invoice $invoice, string $kind): Response
    {
        $this->assertOwner($request, $invoice->order);

        return $this->download($invoice, $kind);
    }

    /**
     * Le vuelve a enviar al comprador su comprobante por correo, con el PDF.
     */
    public function resend(Invoice $invoice): JsonResponse
    {
        if (! $invoice->isValid()) {
            throw new ApiException(ErrorCode::Conflict, 'Solo se envía un comprobante aceptado por SUNAT.', 409);
        }
        if ($invoice->order?->customer === null) {
            throw new ApiException(ErrorCode::Conflict, 'Esta orden no tiene a quién enviarle el comprobante.', 409);
        }

        SendInvoiceMail::dispatch($invoice->tenant_id, $invoice->id, resend: true);
        $this->audit->record('invoices.resent', $invoice, null, ['code' => $invoice->code(), 'to' => $invoice->order->customer->email]);

        return ApiResponse::success(['sent_to' => $invoice->order->customer->email]);
    }

    public function retry(Invoice $invoice): JsonResponse
    {
        return ApiResponse::success(InvoicePresenter::row($this->issuer->retry($invoice), staff: true));
    }

    /**
     * Anula un comprobante emitido con error (dentro del plazo de SUNAT).
     */
    public function void(Request $request, Invoice $invoice): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:100']], [
            'reason.required' => 'Escribe por qué se anula (por ejemplo, «Error en el nombre del cliente»).',
        ]);

        return ApiResponse::success(InvoicePresenter::row($this->issuer->void($invoice, $data['reason'])->load('reference'), staff: true));
    }

    /**
     * Corrige un comprobante con una nota de crédito sin devolver el dinero.
     */
    public function creditNote(Request $request, Invoice $invoice): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:100']], [
            'reason.required' => 'Escribe el motivo que figurará en la nota de crédito.',
        ]);

        return ApiResponse::success(InvoicePresenter::row($this->issuer->creditNote($invoice, $data['reason'])->load('reference'), staff: true));
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
     * @return array<string, list<mixed>>
     */
    private function listRules(): array
    {
        return [
            'state' => ['nullable', Rule::in(array_keys(self::FILTERS))],
            'type' => ['nullable', Rule::in(array_values(InvoicePresenter::TYPES))],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            ...DateRange::rules(),
        ];
    }

    /**
     * Comprobantes que coinciden con el tipo, las fechas de emisión y lo buscado
     * (el número, como B001-12 o 12; el cliente o su documento).
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Invoice>
     */
    private function search(array $filters): Builder
    {
        $query = Invoice::query()
            ->when($filters['type'] ?? null, fn (Builder $q, string $type) => $q->where('document_type', array_search($type, InvoicePresenter::TYPES, true)))
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $search = trim($search);
                $like = '%'.mb_strtolower($search).'%';

                $q->where(function (Builder $match) use ($search, $like) {
                    $match->whereRaw('lower(customer_name) like ?', [$like])->orWhere('customer_doc_number', 'like', "%{$search}%");

                    if (preg_match('/^(?:([A-Za-z0-9]{4})-)?0*(\d{1,8})$/', $search, $code)) {
                        $match->orWhere(fn (Builder $number) => $number
                            ->where('number', (int) $code[2])
                            ->when($code[1] !== '', fn (Builder $series) => $series->where('series', mb_strtoupper($code[1]))));
                    }
                });
            });

        return DateRange::applyDays($query, 'issue_date', $filters);
    }

    /**
     * @param  Builder<Invoice>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<Invoice>
     */
    private function ordered(Builder $query, array $filters): Builder
    {
        return $query
            ->with(['reference', 'order'])
            ->withExists(['creditNotes as credited' => fn (Builder $q) => $q->whereNotIn('status', Invoice::DISCARDED)])
            ->when($filters['state'] ?? null, fn (Builder $q, string $state) => $q->whereIn('status', self::FILTERS[$state]))
            ->orderByDesc('issue_date')
            ->orderByDesc('created_at')
            ->orderBy('id');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(Order $order, bool $staff): array
    {
        return Invoice::query()->where('order_id', $order->id)->with('reference')->orderBy('created_at')->get()
            ->map(fn (Invoice $invoice) => InvoicePresenter::row($invoice, $staff))
            ->values()
            ->all();
    }

    private function download(Invoice $invoice, string $kind): Response
    {
        $bytes = $this->issuer->file($invoice, $kind);

        if ($bytes === null) {
            throw new ApiException(ErrorCode::NotFound, self::FILE_NAMES[$kind].' del comprobante todavía no está disponible.', 404);
        }

        // Los nombres de SUNAT: RUC-tipo-serie-número (la constancia empieza con R-)
        $sunatName = implode('-', [$this->issuer->setting()?->ruc, $invoice->document_type, $invoice->series, str_pad((string) $invoice->number, 8, '0', STR_PAD_LEFT)]);

        return response($bytes, 200, match ($kind) {
            'pdf' => ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.$invoice->code().'.pdf"'],
            'xml' => ['Content-Type' => 'application/zip', 'Content-Disposition' => 'attachment; filename="'.$sunatName.'.zip"'],
            'cdr' => ['Content-Type' => 'application/zip', 'Content-Disposition' => 'attachment; filename="R-'.$sunatName.'.zip"'],
        });
    }

    private function assertOwner(Request $request, ?Order $order): void
    {
        // Otra persona no sabe ni que existe
        if ($order === null || $order->customer_id !== $request->user()->id) {
            throw new ApiException(ErrorCode::NotFound, 'El recurso solicitado no existe.', 404);
        }
    }
}
