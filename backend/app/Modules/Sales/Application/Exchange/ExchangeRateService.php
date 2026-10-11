<?php

namespace App\Modules\Sales\Application\Exchange;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Sales\Domain\Models\ExchangeRate;
use App\Modules\Tenancy\Domain\Models\TenantSetting;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Illuminate\Support\Carbon;

/**
 * Tipo de cambio de la organización (RF-CUR-001, RN-019, PD-011): soles por
 * dólar con los que cobra en dólares lo que vende en soles. La organización
 * elige si lo escribe a mano o si se toma solo de una fuente cada día, con un
 * recargo opcional. Cada valor queda registrado con su fuente.
 */
class ExchangeRateService
{
    public const MODE_MANUAL = 'MANUAL';

    public const MODE_AUTO = 'AUTO';

    public const MODES = [self::MODE_MANUAL, self::MODE_AUTO];

    /** Recargo máximo sobre lo que se cobra en dólares */
    public const MAX_MARGIN = 20;

    /** Días tras los cuales un tipo de cambio se considera desactualizado */
    public const STALE_DAYS = 3;

    private const SETTINGS_KEY = 'exchange';

    /**
     * @param  iterable<ExchangeRateSource>  $sources
     */
    public function __construct(
        private readonly iterable $sources,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Cómo se obtiene el tipo de cambio en esta organización.
     *
     * @return array{mode: string, source: string, margin_percent: float}
     */
    public function settings(): array
    {
        $stored = TenantSetting::query()->where('key', self::SETTINGS_KEY)->value('value') ?? [];

        return [
            'mode' => in_array($stored['mode'] ?? null, self::MODES, true) ? $stored['mode'] : self::MODE_MANUAL,
            'source' => $this->source($stored['source'] ?? null)->code(),
            'margin_percent' => (float) ($stored['margin_percent'] ?? 0),
        ];
    }

    /**
     * @return list<array{code: string, label: string}>
     */
    public function sources(): array
    {
        return array_map(fn (ExchangeRateSource $source) => ['code' => $source->code(), 'label' => $source->label()], [...$this->sources]);
    }

    /**
     * El tipo de cambio vigente: el último que se registró, sea cual sea el día al que corresponde.
     */
    public function current(): ?ExchangeRate
    {
        return ExchangeRate::query()
            ->where('base_currency', 'USD')
            ->where('quote_currency', 'PEN')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Soles por dólar vigentes, o null si la organización todavía no tiene ninguno.
     */
    public function currentRate(): ?float
    {
        return $this->current()?->rate;
    }

    /**
     * Cambia la forma de obtenerlo. Al pasar a automático, o al cambiar el
     * recargo estando en automático, se trae el valor del día.
     */
    public function configure(string $mode, string $source, float $marginPercent, User $actor): void
    {
        $before = $this->settings();
        $after = ['mode' => $mode, 'source' => $this->source($source)->code(), 'margin_percent' => round($marginPercent, 2)];

        if ($before == $after) {
            return;
        }

        TenantSetting::query()->updateOrCreate(['key' => self::SETTINGS_KEY], ['value' => $after]);
        $this->audit->record('exchange_rates.configured', 'exchange_rate_settings', $before, $after);

        if ($mode === self::MODE_AUTO) {
            $this->refresh($actor);
        }
    }

    /**
     * Una persona escribe el tipo de cambio. Solo en modo manual: en
     * automático lo pisaría la siguiente actualización.
     */
    public function recordManual(float $rate, User $actor): ExchangeRate
    {
        if ($this->settings()['mode'] === self::MODE_AUTO) {
            throw new ApiException(ErrorCode::ValidationError, 'El tipo de cambio se actualiza solo. Para escribirlo a mano, cambia primero a manual.', 422);
        }

        $before = $this->currentRate();
        $rate = round($rate, 4);

        if ($before === $rate) {
            return $this->current();
        }

        $record = ExchangeRate::create([
            'rate' => $rate,
            'source' => ExchangeRate::SOURCE_MANUAL,
            'effective_date' => now()->toDateString(),
            'created_by' => $actor->id,
        ]);

        $this->audit->record('exchange_rates.recorded', $record, ['rate' => $before], ['rate' => $rate, 'source' => $record->source]);

        return $record;
    }

    /**
     * Trae el valor de hoy de la fuente y lo registra con el recargo. Si la
     * fuente no responde se conserva el último valor.
     *
     * @param  array{rate: float, date: string}|null  $fetched  valor ya consultado, para no pedirlo otra vez por cada organización
     */
    public function refresh(?User $actor = null, ?array $fetched = null): ?ExchangeRate
    {
        $settings = $this->settings();
        $fetched ??= $this->source($settings['source'])->today();

        if ($fetched === null) {
            return null;
        }

        $rate = self::withMargin($fetched['rate'], $settings['margin_percent']);
        $current = $this->current();

        // El mismo valor del mismo día no se registra dos veces
        if ($current && $current->source === $settings['source'] && $current->rate === $rate && $current->effective_date->toDateString() === Carbon::parse($fetched['date'])->toDateString()) {
            return $current;
        }

        $record = ExchangeRate::create([
            'rate' => $rate,
            'source' => $settings['source'],
            'official_rate' => round($fetched['rate'], 4),
            'margin_percent' => $settings['margin_percent'],
            'effective_date' => Carbon::parse($fetched['date'])->toDateString(),
            'created_by' => $actor?->id,
        ]);

        $this->audit->record('exchange_rates.recorded', $record, ['rate' => $current?->rate], [
            'rate' => $rate, 'source' => $record->source, 'official_rate' => $record->official_rate, 'margin_percent' => $record->margin_percent,
        ], $actor?->id);

        return $record;
    }

    /**
     * El recargo hace que se cobren más dólares por el mismo precio en soles:
     * con 2 %, lo que serían US$ 100 se cobra como US$ 102.
     */
    public static function withMargin(float $officialRate, float $marginPercent): float
    {
        return round($officialRate / (1 + $marginPercent / 100), 4);
    }

    /**
     * Valor de hoy según una fuente, sin registrarlo.
     *
     * @return array{rate: float, date: string}|null
     */
    public function fetch(string $source): ?array
    {
        return $this->source($source)->today();
    }

    private function source(?string $code): ExchangeRateSource
    {
        $sources = [...$this->sources];

        foreach ($sources as $source) {
            if ($source->code() === $code) {
                return $source;
            }
        }

        // Sin fuente elegida, la primera disponible
        return $sources[0] ?? throw new ApiException(ErrorCode::NotFound, 'No hay ninguna fuente de tipo de cambio disponible.', 404);
    }
}
