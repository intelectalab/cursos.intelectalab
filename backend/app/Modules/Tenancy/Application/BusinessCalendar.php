<?php

namespace App\Modules\Tenancy\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Tenancy\Domain\Models\Holiday;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Días hábiles de la organización: de lunes a viernes, sin sus feriados.
 */
class BusinessCalendar
{
    /** Feriados nacionales del Perú de fecha fija (mes-día). Jueves y Viernes Santo se calculan por año. */
    public const NATIONAL_FIXED = [
        '01-01' => 'Año Nuevo',
        '05-01' => 'Día del Trabajo',
        '06-07' => 'Batalla de Arica y Día de la Bandera',
        '06-29' => 'San Pedro y San Pablo',
        '07-23' => 'Día de la Fuerza Aérea del Perú',
        '07-28' => 'Fiestas Patrias',
        '07-29' => 'Fiestas Patrias',
        '08-06' => 'Batalla de Junín',
        '08-30' => 'Santa Rosa de Lima',
        '10-08' => 'Combate de Angamos',
        '11-01' => 'Día de Todos los Santos',
        '12-08' => 'Inmaculada Concepción',
        '12-09' => 'Batalla de Ayacucho',
        '12-25' => 'Navidad',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Fecha que resulta de contar días hábiles a partir del día siguiente.
     */
    public function addBusinessDays(CarbonInterface $from, int $days): CarbonImmutable
    {
        $date = CarbonImmutable::parse($from->toDateString());
        // Margen amplio: los feriados que puedan caer dentro del plazo
        $holidays = Holiday::query()
            ->whereBetween('date', [$date->toDateString(), $date->addDays($days * 2 + 30)->toDateString()])
            ->pluck('date')
            ->map(fn (CarbonInterface $holiday) => $holiday->toDateString())
            ->flip();

        while ($days > 0) {
            $date = $date->addDay();
            if (! $date->isWeekend() && ! $holidays->has($date->toDateString())) {
                $days--;
            }
        }

        return $date;
    }

    /**
     * Agrega los feriados nacionales del año que la organización aún no tiene.
     *
     * @return int cuántos se agregaron
     */
    public function loadNational(int $year): int
    {
        $existing = Holiday::query()->whereYear('date', $year)->pluck('date')
            ->map(fn (CarbonInterface $holiday) => $holiday->toDateString())
            ->flip();

        $added = 0;
        foreach ($this->national($year) as $date => $name) {
            if (! $existing->has($date)) {
                Holiday::create(['date' => $date, 'name' => $name]);
                $added++;
            }
        }

        if ($added > 0) {
            $this->audit->record('holidays.national_loaded', 'holidays', after: ['year' => $year, 'added' => $added]);
        }

        return $added;
    }

    /**
     * @return array<string, string> fecha (Y-m-d) => nombre, en orden
     */
    public function national(int $year): array
    {
        $easter = $this->easterSunday($year);
        $holidays = [
            $easter->subDays(3)->toDateString() => 'Jueves Santo',
            $easter->subDays(2)->toDateString() => 'Viernes Santo',
        ];

        foreach (self::NATIONAL_FIXED as $monthDay => $name) {
            $holidays["{$year}-{$monthDay}"] = $name;
        }

        ksort($holidays);

        return $holidays;
    }

    /**
     * Domingo de Pascua por el algoritmo gregoriano anónimo (no depende de la extensión calendar).
     */
    private function easterSunday(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day);
    }
}
