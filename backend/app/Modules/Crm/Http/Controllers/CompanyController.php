<?php

namespace App\Modules\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Crm\Application\CrmService;
use App\Modules\Crm\Domain\Models\Company;
use App\Modules\Crm\Domain\Models\Contact;
use App\Modules\Crm\Domain\Models\Opportunity;
use App\Modules\Crm\Http\CrmPresenter;
use App\Shared\Http\ApiResponse;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Empresas y sus contactos. La misma ficha sirve mientras la empresa es
 * prospecto y cuando ya es cliente.
 */
class CompanyController extends Controller
{
    private const EDITABLE = ['name', 'tax_id', 'industry', 'website', 'email', 'phone', 'address', 'city', 'notes', 'owner_id'];

    public function __construct(
        private readonly CrmService $crm,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(Company::STATUSES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $companies = $this->listing()
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->where('status', $status))
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $like = '%'.mb_strtolower($search).'%';

                $q->where(fn (Builder $match) => $match
                    ->whereRaw('lower(name) like ?', [$like])
                    ->orWhereRaw('lower(tax_id) like ?', [$like])
                    ->orWhereHas('contacts', fn (Builder $contact) => $contact
                        ->whereRaw("lower(first_name || ' ' || coalesce(last_name, '')) like ?", [$like])
                        ->orWhereRaw('lower(email) like ?', [$like])));
            })
            ->orderBy('name')
            ->orderBy('id')
            ->paginate($filters['per_page'] ?? 50);

        return ApiResponse::paginated($companies, fn (Company $company) => CrmPresenter::company($company));
    }

    public function store(Request $request, TenantContext $context): JsonResponse
    {
        $data = $this->validated($request, $context);
        $this->crm->assertOwner($data['owner_id'] ?? null);

        $company = $this->crm->createCompany($request->user(), $data + ['owner_id' => $request->user()->id]);

        return $this->respond($company, 201);
    }

    public function show(Company $company): JsonResponse
    {
        return $this->respond($company);
    }

    public function update(Request $request, Company $company, TenantContext $context): JsonResponse
    {
        $data = $this->validated($request, $context, $company);
        $this->crm->assertOwner($data['owner_id'] ?? null);

        $before = $company->only(self::EDITABLE);
        $company->update($data);

        if ($company->wasChanged()) {
            $this->audit->record('companies.updated', $company, $before, $company->only(self::EDITABLE));
        }

        return $this->respond($company);
    }

    public function storeContact(Request $request, Company $company): JsonResponse
    {
        $data = $this->validatedContact($request);

        DB::transaction(function () use ($company, $data) {
            // El primero queda como principal aunque no se marque
            $primary = ($data['is_primary'] ?? false) || ! $company->contacts()->exists();
            $contact = $company->contacts()->create(['is_primary' => $primary] + $data);
            $this->keepOnePrimary($contact);
        });

        return $this->respond($company, 201);
    }

    public function updateContact(Request $request, Contact $contact): JsonResponse
    {
        $data = $this->validatedContact($request);

        DB::transaction(function () use ($contact, $data) {
            $contact->update($data);
            $this->keepOnePrimary($contact);
        });

        return $this->respond($contact->company);
    }

    public function destroyContact(Contact $contact): JsonResponse
    {
        $company = $contact->company;
        $contact->delete();

        $this->audit->record('contacts.deleted', $contact, ['name' => $contact->fullName(), 'company' => $company->name]);

        return $this->respond($company);
    }

    /**
     * @return Builder<Company>
     */
    private function listing(): Builder
    {
        return Company::query()
            ->with('owner')
            ->withCount([
                'contacts',
                'opportunities as open_opportunities_count' => fn (Builder $q) => $q->whereNotIn('stage', Opportunity::CLOSED_STAGES),
            ]);
    }

    /**
     * Ficha completa: datos, contactos y oportunidades.
     */
    private function respond(Company $company, int $status = 200): JsonResponse
    {
        $company = $this->listing()->findOrFail($company->id);
        $opportunities = $company->opportunities()->with(['company', 'contact', 'owner'])->orderByDesc('created_at')->get();

        return ApiResponse::success(CrmPresenter::company($company) + [
            'contacts' => $company->contacts->map(fn (Contact $contact) => CrmPresenter::contact($contact))->values(),
            'opportunities' => $opportunities->map(fn (Opportunity $opportunity) => CrmPresenter::opportunity($opportunity))->values(),
        ], null, $status);
    }

    private function keepOnePrimary(Contact $contact): void
    {
        if ($contact->is_primary) {
            Contact::query()->where('company_id', $contact->company_id)->whereKeyNot($contact->id)->update(['is_primary' => false]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, TenantContext $context, ?Company $company = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'tax_id' => [
                'nullable', 'string', 'max:20',
                Rule::unique('companies', 'tax_id')->where('tenant_id', $context->tenantId())->ignore($company?->id),
            ],
            'industry' => ['nullable', 'string', 'max:100'],
            'website' => ['nullable', 'string', 'max:200'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address' => ['nullable', 'string', 'max:250'],
            'city' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'owner_id' => ['sometimes', 'nullable', 'uuid'],
        ], [
            'tax_id.unique' => 'Ya existe una empresa con ese RUC.',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedContact(Request $request): array
    {
        return $request->validate([
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'position' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'is_primary' => ['sometimes', 'boolean'],
        ]);
    }
}
