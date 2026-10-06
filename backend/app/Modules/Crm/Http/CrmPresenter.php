<?php

namespace App\Modules\Crm\Http;

use App\Modules\Crm\Domain\Models\Company;
use App\Modules\Crm\Domain\Models\Contact;
use App\Modules\Crm\Domain\Models\Lead;
use App\Modules\Crm\Domain\Models\Opportunity;
use App\Modules\Crm\Domain\Models\OpportunityActivity;
use App\Modules\Identity\Domain\Models\User;

class CrmPresenter
{
    /**
     * @return array{id: string, name: string}|null
     */
    public static function person(?User $user): ?array
    {
        return $user ? ['id' => $user->id, 'name' => trim("{$user->first_name} {$user->last_name}")] : null;
    }

    /**
     * Espera owner y company cargados.
     *
     * @return array<string, mixed>
     */
    public static function lead(Lead $lead): array
    {
        return [
            'id' => $lead->id,
            'first_name' => $lead->first_name,
            'last_name' => $lead->last_name,
            'name' => $lead->fullName(),
            'company_name' => $lead->company_name,
            'position' => $lead->position,
            'email' => $lead->email,
            'phone' => $lead->phone,
            'source' => $lead->source,
            'interest' => $lead->interest,
            'status' => $lead->status,
            'discard_reason' => $lead->discard_reason,
            'owner' => self::person($lead->owner),
            'created_at' => $lead->created_at->toIso8601String(),
            'converted_at' => $lead->converted_at?->toIso8601String(),
            // Dónde terminó el prospecto al convertirse
            'company' => $lead->company ? ['id' => $lead->company->id, 'name' => $lead->company->name] : null,
            'opportunity_id' => $lead->opportunity_id,
        ];
    }

    /**
     * Fila de la lista de empresas. Espera owner cargado y los conteos contacts_count y open_opportunities_count.
     *
     * @return array<string, mixed>
     */
    public static function company(Company $company): array
    {
        return [
            'id' => $company->id,
            'name' => $company->name,
            'tax_id' => $company->tax_id,
            'industry' => $company->industry,
            'website' => $company->website,
            'email' => $company->email,
            'phone' => $company->phone,
            'address' => $company->address,
            'city' => $company->city,
            'notes' => $company->notes,
            'status' => $company->status,
            'owner' => self::person($company->owner),
            'contacts_count' => (int) ($company->contacts_count ?? 0),
            'open_opportunities_count' => (int) ($company->open_opportunities_count ?? 0),
            'created_at' => $company->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function contact(Contact $contact): array
    {
        return [
            'id' => $contact->id,
            'company_id' => $contact->company_id,
            'first_name' => $contact->first_name,
            'last_name' => $contact->last_name,
            'name' => $contact->fullName(),
            'position' => $contact->position,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'is_primary' => $contact->is_primary,
        ];
    }

    /**
     * Espera company, contact y owner cargados.
     *
     * @return array<string, mixed>
     */
    public static function opportunity(Opportunity $opportunity): array
    {
        return [
            'id' => $opportunity->id,
            'title' => $opportunity->title,
            'stage' => $opportunity->stage,
            'company' => ['id' => $opportunity->company->id, 'name' => $opportunity->company->name],
            'contact' => $opportunity->contact ? ['id' => $opportunity->contact->id, 'name' => $opportunity->contact->fullName()] : null,
            'estimated_amount' => $opportunity->estimated_amount,
            'expected_close_date' => $opportunity->expected_close_date?->toDateString(),
            'notes' => $opportunity->notes,
            'lost_reason' => $opportunity->lost_reason,
            'owner' => self::person($opportunity->owner),
            'created_at' => $opportunity->created_at->toIso8601String(),
            'closed_at' => $opportunity->closed_at?->toIso8601String(),
        ];
    }

    /**
     * Espera author cargado.
     *
     * @return array<string, mixed>
     */
    public static function activity(OpportunityActivity $activity): array
    {
        return [
            'id' => $activity->id,
            'type' => $activity->type,
            'notes' => $activity->notes,
            'occurred_at' => $activity->occurred_at->toIso8601String(),
            'author' => self::person($activity->author)['name'] ?? null,
        ];
    }
}
