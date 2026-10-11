<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Application\Exchange\ExchangeRateService;
use App\Modules\Sales\Domain\Models\ExchangeRate;
use App\Shared\Http\ApiException;
use App\Shared\Http\ApiResponse;
use App\Shared\Http\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tipo de cambio de la organización: cómo se obtiene, cuál rige y su historial.
 */
class ExchangeRateController extends Controller
{
    private const HISTORY = 30;

    public function __construct(private readonly ExchangeRateService $rates) {}

    public function show(): JsonResponse
    {
        return $this->respond();
    }

    /**
     * Cambia entre manual y automático, la fuente y el recargo.
     */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => ['required', Rule::in(ExchangeRateService::MODES)],
            'source' => ['nullable', Rule::in(array_column($this->rates->sources(), 'code'))],
            'margin_percent' => ['nullable', 'numeric', 'min:0', 'max:'.ExchangeRateService::MAX_MARGIN],
        ]);

        $this->rates->configure(
            $data['mode'],
            $data['source'] ?? $this->rates->settings()['source'],
            (float) ($data['margin_percent'] ?? 0),
            $request->user()
        );

        return $this->respond();
    }

    /**
     * Registra a mano el tipo de cambio de hoy.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['rate' => ['required', 'numeric', 'min:0.1', 'max:100']]);

        $this->rates->recordManual((float) $data['rate'], $request->user());

        return $this->respond(201);
    }

    /**
     * Trae ahora el valor de la fuente, sin esperar a la actualización diaria.
     */
    public function refresh(Request $request): JsonResponse
    {
        if ($this->rates->settings()['mode'] !== ExchangeRateService::MODE_AUTO) {
            throw new ApiException(ErrorCode::ValidationError, 'El tipo de cambio está en manual. Cámbialo a automático para traerlo de la fuente.', 422);
        }

        if ($this->rates->refresh($request->user()) === null) {
            throw new ApiException(ErrorCode::GatewayError, 'La fuente del tipo de cambio no respondió. Se mantiene el último valor; inténtalo de nuevo en unos minutos.', 502);
        }

        return $this->respond();
    }

    private function respond(int $status = 200): JsonResponse
    {
        $current = $this->rates->current();
        $history = ExchangeRate::query()
            ->with('author')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::HISTORY)
            ->get();

        return ApiResponse::success([
            ...$this->rates->settings(),
            'current' => $current ? $this->present($current) : null,
            // Lleva días sin cambiar: en automático, la fuente puede estar fallando
            'stale' => $current !== null && $current->effective_date->diffInDays(now()->startOfDay()) > ExchangeRateService::STALE_DAYS,
            'history' => $history->map(fn (ExchangeRate $rate) => $this->present($rate))->values(),
        ], [
            'sources' => $this->rates->sources(),
            'max_margin' => ExchangeRateService::MAX_MARGIN,
            'stale_days' => ExchangeRateService::STALE_DAYS,
        ], $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(ExchangeRate $rate): array
    {
        return [
            'id' => $rate->id,
            'rate' => $rate->rate,
            'source' => $rate->source,
            'official_rate' => $rate->official_rate,
            'margin_percent' => $rate->margin_percent,
            'effective_date' => $rate->effective_date->toDateString(),
            'created_at' => $rate->created_at->toIso8601String(),
            // null = la actualización automática
            'created_by' => $rate->author ? trim("{$rate->author->first_name} {$rate->author->last_name}") : null,
        ];
    }
}
