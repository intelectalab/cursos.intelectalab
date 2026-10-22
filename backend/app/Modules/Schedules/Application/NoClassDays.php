<?php

namespace App\Modules\Schedules\Application;

use App\Modules\Schedules\Domain\Models\ScheduleException;
use App\Modules\Tenancy\Domain\Models\Holiday;

/**
 * Días sin clase en vivo: los feriados de la academia (para todos) y las
 * excepciones de cada docente. Se cargan de una vez para un rango de fechas.
 */
class NoClassDays
{
    /** @var array<string, string> fecha => motivo */
    private array $holidays = [];

    /** @var array<string, array<string, ?string>> docente => fecha => motivo */
    private array $exceptions = [];

    /**
     * @param  iterable<string>  $teacherIds
     */
    public static function between(iterable $teacherIds, string $from, string $to): self
    {
        $days = new self;

        $days->holidays = Holiday::query()->whereBetween('date', [$from, $to])->get(['date', 'name'])
            ->mapWithKeys(fn (Holiday $holiday) => [$holiday->date->toDateString() => "Feriado: {$holiday->name}"])
            ->all();

        ScheduleException::query()
            ->whereIn('teacher_id', collect($teacherIds)->all())
            ->whereBetween('date', [$from, $to])
            ->get(['teacher_id', 'date', 'reason'])
            ->each(function (ScheduleException $exception) use ($days) {
                $days->exceptions[$exception->teacher_id][$exception->date->toDateString()] = $exception->reason;
            });

        return $days;
    }

    public function blocks(string $teacherId, string $date): bool
    {
        return isset($this->holidays[$date]) || array_key_exists($date, $this->exceptions[$teacherId] ?? []);
    }

    /**
     * Por qué no hay clase ese día, o null si sí hay.
     */
    public function reason(string $teacherId, string $date): ?string
    {
        if (isset($this->holidays[$date])) {
            return $this->holidays[$date];
        }

        return array_key_exists($date, $this->exceptions[$teacherId] ?? []) ? ($this->exceptions[$teacherId][$date] ?? 'El docente no tiene clase ese día') : null;
    }
}
