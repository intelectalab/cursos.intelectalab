<?php

namespace App\Modules\Tenancy\Application;

use App\Modules\Audit\Application\AuditLogger;
use App\Modules\Tenancy\Domain\Models\GradeScale;
use App\Modules\Tenancy\Domain\Models\TenantSetting;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Illuminate\Support\Facades\DB;

/**
 * Escalas de notas de la organización: las del sistema, fijas, y las que la
 * organización guarda para usarlas cuando quiera. La escala en uso es la nota
 * máxima guardada en la configuración general (grade_scale).
 */
class GradeScaleService
{
    /** Escalas del sistema: no se editan ni se eliminan */
    public const BUILT_IN = [
        20 => 'Vigesimal',
        100 => 'Centesimal',
    ];

    public const MAX_SCORE_MIN = 1;

    public const MAX_SCORE_MAX = 1000;

    public function __construct(
        private readonly TenantSettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return list<array{id: string|null, name: string, max_score: int, built_in: bool, in_use: bool}>
     */
    public function all(): array
    {
        $current = $this->settings->gradeScale();

        $builtIn = collect(self::BUILT_IN)->map(fn (string $name, int $maxScore) => [
            'id' => null,
            'name' => $name,
            'max_score' => $maxScore,
            'built_in' => true,
            'in_use' => $maxScore === $current,
        ])->values();

        $saved = GradeScale::query()->orderBy('max_score')->get()->map(fn (GradeScale $scale) => [
            'id' => $scale->id,
            'name' => $scale->name,
            'max_score' => $scale->max_score,
            'built_in' => false,
            'in_use' => $scale->max_score === $current,
        ]);

        return $builtIn->concat($saved)->all();
    }

    /**
     * Notas máximas que la organización puede elegir como escala en uso.
     *
     * @return list<int>
     */
    public function availableMaxScores(): array
    {
        return array_values(array_unique([
            ...array_keys(self::BUILT_IN),
            ...GradeScale::query()->pluck('max_score')->all(),
        ]));
    }

    /**
     * @param  array{name: string, max_score: int}  $data
     */
    public function create(array $data): GradeScale
    {
        $scale = GradeScale::create($data);

        $this->audit->record('grade_scales.created', $scale, null, $scale->only(['name', 'max_score']));

        return $scale;
    }

    /**
     * Si la escala está en uso y cambia su nota máxima, la configuración la sigue.
     *
     * @param  array{name: string, max_score: int}  $data
     */
    public function update(GradeScale $scale, array $data): GradeScale
    {
        return DB::transaction(function () use ($scale, $data) {
            $before = $scale->only(['name', 'max_score']);
            $wasInUse = $scale->max_score === $this->settings->gradeScale();

            $scale->update($data);

            if ($wasInUse && $scale->wasChanged('max_score')) {
                $this->useScale($scale->max_score);
            }

            if ($scale->wasChanged()) {
                $this->audit->record('grade_scales.updated', $scale, $before, $scale->only(['name', 'max_score']));
            }

            return $scale;
        });
    }

    public function delete(GradeScale $scale): void
    {
        if ($scale->max_score === $this->settings->gradeScale()) {
            throw new ApiException(
                ErrorCode::GradeScaleInUse,
                'Esta escala está en uso. Elige otra escala de notas y guarda antes de eliminarla.',
                409
            );
        }

        $this->audit->record('grade_scales.deleted', $scale, $scale->only(['name', 'max_score']));

        $scale->delete();
    }

    private function useScale(int $maxScore): void
    {
        $setting = TenantSetting::query()->where('key', TenantSettingsService::KEY)->firstOrFail();

        $setting->update(['value' => ['grade_scale' => $maxScore] + $setting->value]);
    }
}
