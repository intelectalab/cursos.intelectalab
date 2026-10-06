<?php

namespace App\Modules\B2b\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\B2b\Domain\Models\CorporateAccount;
use App\Modules\B2b\Domain\Models\Quotation;
use App\Modules\B2b\Domain\Models\QuotationItem;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Crm\Application\CrmService;
use App\Modules\Crm\Domain\Models\Company;
use App\Modules\Crm\Domain\Models\Opportunity;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Sales\Application\OrderService;
use App\Modules\Sales\Domain\Models\Currency;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Cotización corporativa (RF-B2B-001, FP-04): borrador → enviada → aceptada
 * → venta. Cada envío guarda su versión; la venta sale de la versión que el
 * cliente aceptó (RN-011).
 */
class QuotationService
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly CrmService $crm,
        private readonly TenantSettingsService $settings,
        private readonly TenantContext $context,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(User $actor, array $data): Quotation
    {
        $this->assertBelongsToCompany($data);

        return DB::transaction(function () use ($actor, $data) {
            if (DB::connection()->getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ['quotations:'.$this->context->tenantId()]);
            }

            $quotation = Quotation::create([
                'number' => (int) Quotation::query()->max('number') + 1,
                'company_id' => $data['company_id'],
                'contact_id' => $data['contact_id'] ?? null,
                'opportunity_id' => $data['opportunity_id'] ?? null,
                'currency_id' => Currency::byCode($this->settings->currency())->id,
                'status' => Quotation::STATUS_DRAFT,
                'version' => 1,
                'valid_until' => $data['valid_until'] ?? now()->addDays(Quotation::DEFAULT_VALIDITY_DAYS)->toDateString(),
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
            ]);

            $this->replaceItems($quotation, $data['items'] ?? []);

            $this->audit->record('quotations.created', $quotation, null, [
                'number' => $quotation->number,
                'company' => $quotation->company->name,
                'total' => $quotation->total_amount,
            ]);

            return $quotation;
        });
    }

    /**
     * Solo un borrador se edita; una enviada primero se abre como versión nueva.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Quotation $quotation, array $data): Quotation
    {
        $this->assertState($quotation, [Quotation::STATUS_DRAFT], 'Solo se puede editar una cotización en borrador. Crea una versión nueva para cambiarla.');
        $this->assertBelongsToCompany($data + ['company_id' => $quotation->company_id]);

        return DB::transaction(function () use ($quotation, $data) {
            $quotation->update([
                'contact_id' => $data['contact_id'] ?? null,
                'valid_until' => $data['valid_until'],
                'notes' => $data['notes'] ?? null,
            ]);

            $this->replaceItems($quotation, $data['items'] ?? []);

            return $quotation;
        });
    }

    /**
     * Envía la cotización: guarda la versión tal como queda y la deja lista para que el cliente responda.
     */
    public function send(Quotation $quotation, User $actor): Quotation
    {
        $this->assertState($quotation, [Quotation::STATUS_DRAFT], 'Esta cotización ya fue enviada.');

        if ($quotation->items()->doesntExist() || (float) $quotation->total_amount <= 0) {
            throw new ApiException(ErrorCode::QuotationIncomplete, 'Agrega al menos un curso con precio antes de enviar la cotización.', 409);
        }

        if ($quotation->valid_until->copy()->endOfDay()->isPast()) {
            throw new ApiException(ErrorCode::QuotationIncomplete, 'La fecha de vigencia ya pasó. Cámbiala antes de enviar.', 409);
        }

        return DB::transaction(function () use ($quotation, $actor) {
            $quotation->update(['status' => Quotation::STATUS_SENT, 'sent_at' => now()]);

            $quotation->versions()->updateOrCreate(['version' => $quotation->version], [
                'snapshot' => $this->snapshot($quotation),
                'total_amount' => $quotation->total_amount,
                'sent_by' => $actor->id,
                'sent_at' => now(),
            ]);

            // La oportunidad avanza sola a "Propuesta enviada" si aún no llegó ahí
            $opportunity = $quotation->opportunity;
            if ($opportunity && in_array($opportunity->stage, [Opportunity::STAGE_NEW, Opportunity::STAGE_CONTACTED], true)) {
                $this->crm->moveOpportunity($opportunity, $actor, Opportunity::STAGE_PROPOSAL);
            }

            $this->audit->record('quotations.sent', $quotation, null, [
                'number' => $quotation->number,
                'version' => $quotation->version,
                'total' => $quotation->total_amount,
            ]);

            return $quotation;
        });
    }

    /**
     * Abre una versión nueva de una cotización enviada, vencida o rechazada.
     * La versión anterior queda guardada y ya no se puede aceptar.
     */
    public function revise(Quotation $quotation): Quotation
    {
        $this->assertState($quotation, [Quotation::STATUS_SENT, Quotation::STATE_EXPIRED, Quotation::STATUS_REJECTED], 'Esta cotización no admite una versión nueva.');

        $quotation->update([
            'status' => Quotation::STATUS_DRAFT,
            'version' => $quotation->version + 1,
            'sent_at' => null,
            'rejected_at' => null,
            'reject_reason' => null,
        ]);

        // Una vigencia vencida no serviría para enviar la versión nueva
        if ($quotation->valid_until->copy()->endOfDay()->isPast()) {
            $quotation->update(['valid_until' => now()->addDays(Quotation::DEFAULT_VALIDITY_DAYS)->toDateString()]);
        }

        $this->audit->record('quotations.revised', $quotation, null, ['number' => $quotation->number, 'version' => $quotation->version]);

        return $quotation;
    }

    /**
     * El cliente acepta: nace la venta con los cursos, cantidades y precios
     * de la versión enviada (RN-011) y la empresa pasa a ser cliente.
     *
     * @param  string  $via  Quotation::VIA_PORTAL o VIA_STAFF
     */
    public function accept(Quotation $quotation, User $actor, string $via, ?string $note = null): Quotation
    {
        return DB::transaction(function () use ($quotation, $actor, $via, $note) {
            $quotation = Quotation::query()->lockForUpdate()->findOrFail($quotation->id);

            if ($quotation->state() === Quotation::STATE_EXPIRED) {
                throw new ApiException(ErrorCode::QuotationExpired, 'La cotización venció. Hay que enviar una versión nueva.', 409);
            }

            $this->assertState($quotation, [Quotation::STATUS_SENT], 'Solo se puede aceptar una cotización enviada.');

            $version = $quotation->versions()->where('version', $quotation->version)->firstOrFail();

            $order = $this->orders->placeForCompany(
                $quotation->company_id,
                $quotation->currency_id,
                array_map(fn (array $item) => [
                    'course_id' => $item['course_id'],
                    'title' => $item['title'],
                    'unit_price' => $item['unit_price'],
                    'quantity' => $item['quantity'],
                    'total_amount' => $item['total_amount'],
                ], $version->snapshot['items']),
            );

            $quotation->update([
                'status' => Quotation::STATUS_ACCEPTED,
                'accepted_at' => now(),
                'accepted_by' => $actor->id,
                'accepted_via' => $via,
                'acceptance_note' => $note,
                'order_id' => $order->id,
            ]);

            Company::query()->whereKey($quotation->company_id)->update(['status' => Company::STATUS_CUSTOMER]);
            CorporateAccount::forCompany($quotation->company_id);

            $opportunity = $quotation->opportunity;
            if ($opportunity && $opportunity->stage !== Opportunity::STAGE_WON) {
                $this->crm->moveOpportunity($opportunity, $actor, Opportunity::STAGE_WON);
            }

            $this->audit->record('quotations.accepted', $quotation, null, [
                'number' => $quotation->number,
                'version' => $quotation->version,
                'via' => $via,
                'order' => $order->number,
                'total' => $order->total_amount,
            ]);

            return $quotation;
        });
    }

    public function reject(Quotation $quotation, string $reason): Quotation
    {
        $this->assertState($quotation, [Quotation::STATUS_SENT, Quotation::STATE_EXPIRED], 'Solo se puede rechazar una cotización enviada.');

        $quotation->update(['status' => Quotation::STATUS_REJECTED, 'rejected_at' => now(), 'reject_reason' => $reason]);

        $this->audit->record('quotations.rejected', $quotation, null, ['number' => $quotation->number, 'reason' => $reason]);

        return $quotation;
    }

    /**
     * @param  list<string>  $allowed  situaciones (state()) admitidas
     */
    private function assertState(Quotation $quotation, array $allowed, string $message): void
    {
        if (! in_array($quotation->state(), $allowed, true)) {
            throw new ApiException(ErrorCode::QuotationInvalidState, $message, 409, ['status' => $quotation->state()]);
        }
    }

    /**
     * El contacto y la oportunidad deben ser de la misma empresa.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertBelongsToCompany(array $data): void
    {
        $this->crm->assertContactOfCompany($data['contact_id'] ?? null, $data['company_id']);

        $opportunity = $data['opportunity_id'] ?? null;

        if ($opportunity !== null && ! Opportunity::query()->whereKey($opportunity)->where('company_id', $data['company_id'])->exists()) {
            throw new ApiException(ErrorCode::ValidationError, 'La oportunidad no pertenece a la empresa.', 422, [
                'opportunity_id' => ['Elige una oportunidad de esta empresa.'],
            ]);
        }
    }

    /**
     * Reemplaza los ítems del borrador. El precio lo fija el vendedor; el de
     * lista del curso se guarda solo como referencia. Solo se cotizan cursos publicados.
     *
     * @param  list<array{course_id: string, quantity: int, unit_price: float|string}>  $items
     */
    private function replaceItems(Quotation $quotation, array $items): void
    {
        $courses = Course::query()
            ->with('currentVersion')
            ->whereIn('id', array_column($items, 'course_id'))
            ->where('status', Course::STATUS_PUBLISHED)
            ->get()
            ->keyBy('id');

        $quotation->items()->delete();
        $total = 0;

        foreach (array_values($items) as $position => $item) {
            $course = $courses->get($item['course_id']);

            if (! $course) {
                throw new ApiException(ErrorCode::ValidationError, 'Solo se pueden cotizar cursos publicados.', 422, [
                    "items.{$position}.course_id" => ['Este curso no está publicado.'],
                ]);
            }

            $lineTotal = round((float) $item['unit_price'] * (int) $item['quantity'], 2);
            $total += $lineTotal;

            QuotationItem::create([
                'quotation_id' => $quotation->id,
                'course_id' => $course->id,
                'title' => $course->currentVersion->snapshot['title'] ?? $course->title,
                'quantity' => $item['quantity'],
                'list_price' => $course->price,
                'unit_price' => $item['unit_price'],
                'total_amount' => $lineTotal,
                'position' => $position,
            ]);
        }

        $quotation->update(['total_amount' => round($total, 2)]);
        $quotation->unsetRelation('items');
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(Quotation $quotation): array
    {
        $quotation->load(['company', 'contact', 'currency', 'items']);

        return [
            'company' => ['name' => $quotation->company->name, 'tax_id' => $quotation->company->tax_id],
            'contact' => $quotation->contact?->fullName(),
            'currency' => $quotation->currency->code,
            'valid_until' => $quotation->valid_until->toDateString(),
            'notes' => $quotation->notes,
            'total_amount' => $quotation->total_amount,
            'items' => $quotation->items->map(fn (QuotationItem $item) => [
                'course_id' => $item->course_id,
                'title' => $item->title,
                'quantity' => $item->quantity,
                'list_price' => $item->list_price,
                'unit_price' => $item->unit_price,
                'total_amount' => $item->total_amount,
            ])->values()->all(),
        ];
    }
}
