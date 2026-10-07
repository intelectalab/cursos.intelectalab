<?php

namespace App\Modules\Tenancy\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Tenancy\Application\BusinessCalendar;
use App\Modules\Tenancy\Domain\Models\Holiday;
use App\Shared\Http\ApiResponse;
use App\Shared\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Feriados de la organización. Cada respuesta devuelve la lista del año pedido,
 * que es lo que muestra Configuración.
 */
class HolidayController extends Controller
{
    private const YEAR_RULES = ['required', 'integer', 'min:2020', 'max:2100'];

    public function __construct(
        private readonly BusinessCalendar $calendar,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $year = (int) $request->validate(['year' => self::YEAR_RULES])['year'];

        return $this->list($year);
    }

    public function store(Request $request, TenantContext $context): JsonResponse
    {
        $data = $request->validate([
            'date' => [
                'required', 'date_format:Y-m-d', 'after_or_equal:2020-01-01', 'before_or_equal:2100-12-31',
                Rule::unique('holidays', 'date')->where('tenant_id', $context->tenantId()),
            ],
            'name' => ['required', 'string', 'max:120'],
        ], ['date.unique' => 'Ese día ya está registrado como feriado.']);

        $holiday = Holiday::create($data);
        $this->audit->record('holidays.created', $holiday, null, ['date' => $data['date'], 'name' => $data['name']]);

        return $this->list((int) substr($data['date'], 0, 4), 201);
    }

    public function loadNational(Request $request): JsonResponse
    {
        $year = (int) $request->validate(['year' => self::YEAR_RULES])['year'];

        return $this->list($year, 200, ['added' => $this->calendar->loadNational($year)]);
    }

    public function destroy(Holiday $holiday): JsonResponse
    {
        $this->audit->record('holidays.deleted', $holiday, ['date' => $holiday->date->toDateString(), 'name' => $holiday->name]);
        $holiday->delete();

        return $this->list($holiday->date->year);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function list(int $year, int $status = 200, array $meta = []): JsonResponse
    {
        $holidays = Holiday::query()->whereYear('date', $year)->orderBy('date')->get()
            ->map(fn (Holiday $holiday) => [
                'id' => $holiday->id,
                'date' => $holiday->date->toDateString(),
                'name' => $holiday->name,
            ]);

        return ApiResponse::success($holidays, ['year' => $year] + $meta, $status);
    }
}
