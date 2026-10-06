<?php

namespace App\Modules\Crm\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Crm\Domain\Models\Company;
use App\Modules\Crm\Domain\Models\Contact;
use App\Modules\Crm\Domain\Models\Lead;
use App\Modules\Crm\Domain\Models\Opportunity;
use App\Modules\Crm\Domain\Models\OpportunityActivity;
use App\Modules\Identity\Domain\Models\User;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reglas del CRM (FP-21): prospecto → empresa y contacto → oportunidad → seguimiento.
 */
class CrmService
{
    /** Permisos con los que alguien puede llevar prospectos y oportunidades */
    private const OWNER_PERMISSIONS = ['*', 'crm.*', 'crm.manage'];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Personal activo que puede quedar a cargo de un prospecto u oportunidad.
     *
     * @return Builder<User>
     */
    public function owners(): Builder
    {
        return User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->whereHas('roles.permissions', fn (Builder $permissions) => $permissions->whereIn('code', self::OWNER_PERMISSIONS))
            ->orderBy('first_name')
            ->orderBy('last_name');
    }

    public function assertOwner(?string $userId): void
    {
        if ($userId !== null && ! $this->owners()->whereKey($userId)->exists()) {
            throw new ApiException(ErrorCode::ValidationError, 'La persona elegida no puede estar a cargo.', 422, [
                'owner_id' => ['Elige a alguien del equipo comercial.'],
            ]);
        }
    }

    /**
     * Convierte el prospecto en empresa (nueva o existente), contacto y,
     * si se pide, oportunidad. El prospecto conserva el rastro.
     *
     * @param  array{company_id?: ?string, company?: ?array<string, mixed>, opportunity?: ?array<string, mixed>}  $data
     */
    public function convertLead(Lead $lead, User $actor, array $data): Lead
    {
        return DB::transaction(function () use ($lead, $actor, $data) {
            $lead = Lead::query()->lockForUpdate()->findOrFail($lead->id);

            if (! $lead->isOpen()) {
                throw new ApiException(ErrorCode::LeadNotOpen, 'Este prospecto ya fue convertido o descartado.', 409);
            }

            $company = isset($data['company_id'])
                ? Company::query()->findOrFail($data['company_id'])
                : $this->createCompany($actor, ($data['company'] ?? []) + ['owner_id' => $lead->owner_id]);

            $contact = $company->contacts()->create([
                'first_name' => $lead->first_name,
                'last_name' => $lead->last_name,
                'position' => $lead->position,
                'email' => $lead->email,
                'phone' => $lead->phone,
                'is_primary' => ! $company->contacts()->exists(),
            ]);

            $opportunity = isset($data['opportunity'])
                ? $this->createOpportunity($actor, $data['opportunity'] + [
                    'company_id' => $company->id,
                    'contact_id' => $contact->id,
                    'owner_id' => $lead->owner_id,
                    'notes' => $lead->interest,
                ])
                : null;

            $lead->update([
                'status' => Lead::STATUS_CONVERTED,
                'converted_at' => now(),
                'converted_by' => $actor->id,
                'company_id' => $company->id,
                'contact_id' => $contact->id,
                'opportunity_id' => $opportunity?->id,
            ]);

            $this->audit->record('leads.converted', $lead, null, [
                'lead' => $lead->fullName(),
                'company' => $company->name,
                'opportunity' => $opportunity?->title,
            ]);

            return $lead;
        });
    }

    public function discardLead(Lead $lead, string $reason): Lead
    {
        if (! $lead->isOpen()) {
            throw new ApiException(ErrorCode::LeadNotOpen, 'Este prospecto ya fue convertido o descartado.', 409);
        }

        $lead->update(['status' => Lead::STATUS_DISCARDED, 'discard_reason' => $reason]);

        $this->audit->record('leads.discarded', $lead, null, ['lead' => $lead->fullName(), 'reason' => $reason]);

        return $lead;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCompany(User $actor, array $data): Company
    {
        $company = Company::create($data + ['created_by' => $actor->id, 'status' => Company::STATUS_PROSPECT]);

        $this->audit->record('companies.created', $company, null, ['name' => $company->name, 'tax_id' => $company->tax_id]);

        return $company;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createOpportunity(User $actor, array $data): Opportunity
    {
        $this->assertContactOfCompany($data['contact_id'] ?? null, $data['company_id']);

        $opportunity = Opportunity::create($data + [
            'stage' => Opportunity::STAGE_NEW,
            'owner_id' => $actor->id,
            'created_by' => $actor->id,
        ]);

        $this->audit->record('opportunities.created', $opportunity, null, [
            'title' => $opportunity->title,
            'estimated_amount' => $opportunity->estimated_amount,
        ]);

        return $opportunity;
    }

    public function assertContactOfCompany(?string $contactId, string $companyId): void
    {
        if ($contactId !== null && ! Contact::query()->whereKey($contactId)->where('company_id', $companyId)->exists()) {
            throw new ApiException(ErrorCode::ValidationError, 'El contacto no pertenece a la empresa.', 422, [
                'contact_id' => ['Elige un contacto de esta empresa.'],
            ]);
        }
    }

    /**
     * Mueve la oportunidad de etapa y deja el cambio en su seguimiento.
     * Perderla exige el motivo; ganarla o perderla la cierra.
     */
    public function moveOpportunity(Opportunity $opportunity, User $actor, string $stage, ?string $lostReason = null): Opportunity
    {
        if ($opportunity->stage === $stage) {
            return $opportunity;
        }

        return DB::transaction(function () use ($opportunity, $actor, $stage, $lostReason) {
            $from = $opportunity->stage;
            $closes = in_array($stage, Opportunity::CLOSED_STAGES, true);

            $opportunity->update([
                'stage' => $stage,
                'lost_reason' => $stage === Opportunity::STAGE_LOST ? $lostReason : null,
                'closed_at' => $closes ? now() : null,
            ]);

            $note = 'De "'.Opportunity::STAGES[$from].'" a "'.Opportunity::STAGES[$stage].'"';

            $opportunity->activities()->create([
                'type' => OpportunityActivity::TYPE_STAGE,
                'notes' => $lostReason ? "{$note}. Motivo: {$lostReason}" : $note,
                'occurred_at' => now(),
                'created_by' => $actor->id,
            ]);

            $this->audit->record('opportunities.stage_changed', $opportunity, ['stage' => $from], [
                'stage' => $stage,
                'lost_reason' => $opportunity->lost_reason,
            ]);

            return $opportunity;
        });
    }
}
