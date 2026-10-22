<?php

namespace App\Modules\Schedules\Http;

use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Schedules\Domain\Models\TeacherSchedule;
use Illuminate\Support\Collection;

/**
 * Forma de un horario en la API, con el nombre de cada curso a la vista.
 */
class SchedulePresenter
{
    /**
     * @param  Collection<string, string>  $courseTitles  id del curso => título
     * @return array<string, mixed>|null
     */
    public static function schedule(?TeacherSchedule $schedule, Collection $courseTitles): ?array
    {
        if ($schedule === null) {
            return null;
        }

        return [
            'id' => $schedule->id,
            'status' => $schedule->status,
            'valid_from' => $schedule->valid_from?->toDateString(),
            'blocks' => self::blocks($schedule->blocks, $courseTitles),
            'observation' => $schedule->observation,
            'sent_at' => $schedule->sent_at?->toIso8601String(),
            'sent_by' => $schedule->sender ? self::name($schedule->sender) : null,
            'responded_at' => $schedule->responded_at?->toIso8601String(),
            'confirmed' => $schedule->confirmed_blocks === null ? null : [
                'valid_from' => $schedule->confirmed_valid_from?->toDateString(),
                'blocks' => self::blocks($schedule->confirmed_blocks, $courseTitles),
            ],
        ];
    }

    /**
     * Horas por tipo de bloque en la semana, para el resumen del listado.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return array<string, float>
     */
    public static function hours(array $blocks): array
    {
        $hours = array_fill_keys(TeacherSchedule::TYPES, 0.0);

        foreach ($blocks as $block) {
            $hours[$block['type']] += (self::minutes($block['end']) - self::minutes($block['start'])) / 60;
        }

        return array_map(fn (float $value) => round($value, 2), $hours);
    }

    /**
     * Títulos de los cursos que aparecen en los horarios, vigentes o confirmados.
     *
     * @param  Collection<int|string, TeacherSchedule>  $schedules
     * @return Collection<string, string>
     */
    public static function courseTitles(Collection $schedules): Collection
    {
        $ids = $schedules
            ->flatMap(fn (TeacherSchedule $schedule) => array_merge($schedule->blocks ?? [], $schedule->confirmed_blocks ?? []))
            ->pluck('course_id')
            ->filter()
            ->unique();

        return $ids->isEmpty() ? collect() : Course::query()->whereIn('id', $ids)->pluck('title', 'id');
    }

    public static function name(User $user): string
    {
        return trim("{$user->first_name} {$user->last_name}");
    }

    /**
     * @param  list<array<string, mixed>>|null  $blocks
     * @param  Collection<string, string>  $courseTitles
     * @return list<array<string, mixed>>
     */
    private static function blocks(?array $blocks, Collection $courseTitles): array
    {
        return array_map(fn (array $block) => [
            'day' => $block['day'],
            'start' => $block['start'],
            'end' => $block['end'],
            'type' => $block['type'],
            'course_id' => $block['course_id'] ?? null,
            'course_title' => isset($block['course_id']) ? $courseTitles->get($block['course_id']) : null,
            'meeting_url' => $block['meeting_url'] ?? null,
            'note' => $block['note'] ?? null,
        ], $blocks ?? []);
    }

    private static function minutes(string $time): int
    {
        [$hours, $minutes] = array_map('intval', explode(':', $time));

        return $hours * 60 + $minutes;
    }
}
