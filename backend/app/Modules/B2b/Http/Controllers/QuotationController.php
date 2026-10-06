<?php

namespace App\Modules\B2b\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\B2b\Application\QuotationService;
use App\Modules\B2b\Domain\Models\Quotation;
use App\Modules\B2b\Http\QuotationPresenter;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Tenancy\Application\TenantSettingsService;
use App\Shared\Http\ApiResponse;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cotizaciones corporativas que arma el equipo comercial.
 */
class QuotationController extends Controller
{
    public const RELATIONS = ['company', 'currency', 'contact', 'opportunity', 'items', 'versions', 'order', 'author', 'accepter'];

    public function __construct(private readonly QuotationService $quotations) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(Quotation::STATES)],
            'company_id' => ['nullable', 'uuid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $quotations = Quotation::query()
            ->with(['company', 'currency'])
            ->when($filters['status'] ?? null, fn (Builder $q, string $status) => $q->inState($status))
            ->when($filters['company_id'] ?? null, fn (Builder $q, string $company) => $q->where('company_id', $company))
            ->when($filters['search'] ?? null, function (Builder $q, string $search) {
                $like = '%'.mb_strtolower($search).'%';
                $number = ctype_digit($search) ? (int) $search : null;

                $q->where(fn (Builder $match) => $match
                    ->when($number, fn (Builder $byNumber) => $byNumber->where('number', $number))
                    ->orWhereHas('company', fn (Builder $company) => $company->whereRaw('lower(name) like ?', [$like])));
            })
            ->orderByDesc('number')
            ->paginate($filters['per_page'] ?? 50);

        return ApiResponse::paginated($quotations, fn (Quotation $quotation) => QuotationPresenter::summary($quotation));
    }

    /**
     * Lo que hace falta para armar una cotización: cursos publicados con su
     * precio de lista, la moneda y la vigencia que se propone.
     */
    public function options(TenantSettingsService $settings): JsonResponse
    {
        $courses = Course::query()
            ->where('status', Course::STATUS_PUBLISHED)
            ->orderBy('title')
            ->get(['id', 'title', 'price']);

        return ApiResponse::success([
            'currency' => $settings->currency(),
            'validity_days' => Quotation::DEFAULT_VALIDITY_DAYS,
            'courses' => $courses->map(fn (Course $course) => [
                'id' => $course->id,
                'title' => $course->title,
                'list_price' => $course->price,
            ])->values(),
        ]);
    }

    /**
     * Maestro §7.2 (POST /quotations).
     */
    public function store(Request $request, TenantContext $context): JsonResponse
    {
        $tenant = $context->tenantId();

        $data = $request->validate([
            'company_id' => ['required', 'uuid', Rule::exists('companies', 'id')->where('tenant_id', $tenant)],
            'opportunity_id' => ['nullable', 'uuid', Rule::exists('opportunities', 'id')->where('tenant_id', $tenant)],
        ] + $this->rules($tenant));

        return $this->respond($this->quotations->create($request->user(), $data), 201);
    }

    public function show(Quotation $quotation): JsonResponse
    {
        return $this->respond($quotation);
    }

    public function update(Request $request, Quotation $quotation, TenantContext $context): JsonResponse
    {
        $data = $request->validate(['valid_until' => ['required', 'date', 'after_or_equal:today']] + $this->rules($context->tenantId()));

        return $this->respond($this->quotations->update($quotation, $data));
    }

    public function send(Request $request, Quotation $quotation): JsonResponse
    {
        return $this->respond($this->quotations->send($quotation, $request->user()));
    }

    public function revise(Quotation $quotation): JsonResponse
    {
        return $this->respond($this->quotations->revise($quotation));
    }

    /**
     * El personal registra que el cliente aceptó por otro medio (correo, teléfono).
     */
    public function accept(Request $request, Quotation $quotation): JsonResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:500']]);

        return $this->respond($this->quotations->accept($quotation, $request->user(), Quotation::VIA_STAFF, $data['note'] ?? null));
    }

    public function reject(Request $request, Quotation $quotation): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->respond($this->quotations->reject($quotation, $data['reason']));
    }

    private function respond(Quotation $quotation, int $status = 200): JsonResponse
    {
        return ApiResponse::success(QuotationPresenter::make($quotation->load(self::RELATIONS)), null, $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(string $tenant): array
    {
        return [
            'contact_id' => ['nullable', 'uuid', Rule::exists('contacts', 'id')->where('tenant_id', $tenant)],
            'valid_until' => ['nullable', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'items' => ['present', 'array', 'max:50'],
            'items.*.course_id' => ['required', 'uuid', 'distinct', Rule::exists('courses', 'id')->where('tenant_id', $tenant)],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0', 'max:9999999'],
        ];
    }
}
