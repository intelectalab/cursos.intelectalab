<?php

namespace App\Shared\Dashboard;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;

/**
 * Periodo que se mira en un panel: del día «desde» al día «hasta», en la
 * zona horaria de quien lo mira, y el periodo anterior de igual largo para
 * comparar. Sin fechas, el mes en curso.
 */
final readonly class PanelPeriod
{
    /** Meses que muestran los gráficos «por mes» */
    public const MONTHS = 12;

    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $timezone,
    ) {}

    public static function fromRequest(Request $request, string $timezone): self
    {
        $data = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $today = CarbonImmutable::now($timezone);
        $from = isset($data['from']) ? CarbonImmutable::createFromFormat('Y-m-d', $data['from'], $timezone)->startOfDay() : $today->startOfMonth();
        $to = isset($data['to']) ? CarbonImmutable::createFromFormat('Y-m-d', $data['to'], $timezone)->endOfDay() : $today->endOfDay();

        // Un periodo de más de dos años ya no se lee en un panel
        if ($from->diffInDays($to) > 731) {
            $from = $to->subYears(2)->startOfDay();
        }

        return new self($from, $to, $timezone);
    }

    /** Inicio, en UTC, para comparar con las fechas guardadas */
    public function start(): CarbonImmutable
    {
        return $this->from->utc();
    }

    public function end(): CarbonImmutable
    {
        return $this->to->utc();
    }

    /**
     * El periodo anterior, del mismo largo, que termina justo antes de este.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} inicio y fin en UTC
     */
    public function previous(): array
    {
        $days = (int) $this->from->diffInDays($this->to->startOfDay()) + 1;

        return [$this->from->subDays($days)->utc(), $this->from->subSecond()->utc()];
    }

    /**
     * Los últimos meses hasta el del «hasta»: clave AAAA-MM y su nombre corto.
     *
     * @return array<string, string>
     */
    public function months(): array
    {
        $months = [];
        $month = $this->to->startOfMonth()->subMonths(self::MONTHS - 1);

        for ($i = 0; $i < self::MONTHS; $i++) {
            $months[$month->format('Y-m')] = ucfirst($month->locale('es')->translatedFormat('M y'));
            $month = $month->addMonth();
        }

        return $months;
    }

    /** Inicio, en UTC, del primer mes de los gráficos por mes */
    public function monthsStart(): CarbonImmutable
    {
        return $this->to->startOfMonth()->subMonths(self::MONTHS - 1)->utc();
    }

    /** Expresión SQL que agrupa una fecha por mes en la zona del panel */
    public function monthExpression(string $column): string
    {
        return "to_char(timezone('{$this->timezone}', {$column}), 'YYYY-MM')";
    }

    /**
     * Valores por mes de los últimos 12 meses, con los meses vacíos en cero.
     *
     * @param  Builder|\Illuminate\Database\Eloquent\Builder  $query  ya filtrado desde monthsStart()
     * @param  string  $aggregate  expresión SQL, por ejemplo count(*) o sum(amount)
     * @return array<string, float|int> nombre del mes => valor
     */
    public function perMonth($query, string $column, string $aggregate, bool $money = false): array
    {
        $values = $query->where($column, '<=', $this->end())
            ->selectRaw($this->monthExpression($column).' as month, '.$aggregate.' as total')
            ->groupBy('month')->pluck('total', 'month');

        $result = [];
        foreach ($this->months() as $key => $label) {
            $result[$label] = $money ? round((float) ($values[$key] ?? 0), 2) : (int) ($values[$key] ?? 0);
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        [$previousStart, $previousEnd] = $this->previous();

        return [
            'from' => $this->from->toDateString(),
            'to' => $this->to->toDateString(),
            'previous_from' => $previousStart->setTimezone($this->timezone)->toDateString(),
            'previous_to' => $previousEnd->setTimezone($this->timezone)->toDateString(),
        ];
    }
}
