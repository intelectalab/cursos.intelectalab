<?php

namespace App\Modules\Assistant\Application;

use App\Modules\Assistant\Domain\Models\SearchAlias;
use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Sales\Domain\Models\Package;
use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Memoria del buscador del asistente. Cuando alguien busca un término y
 * elige un curso o paquete cuyo nombre no lo contiene, se aprende el
 * sinónimo: la próxima vez ese resultado sale primero. Cada academia
 * aprende lo suyo y nunca se aprenden sinónimos de personas.
 */
class SearchMemory
{
    /** Cuántos sinónimos se entregan al navegador, los más usados primero */
    public const LIMIT = 500;

    /**
     * Igual que el intérprete del navegador: minúsculas, sin tildes ni signos y sin espacios de más.
     */
    public static function normalize(string $text): string
    {
        $plain = Str::lower(Str::ascii($text));

        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9 ]+/', ' ', $plain)));
    }

    /**
     * Término normalizado => ids elegidos con él.
     *
     * @return array<string, list<string>>
     */
    public function aliases(): array
    {
        return SearchAlias::query()
            ->orderByDesc('hits')
            ->orderBy('term')
            ->limit(self::LIMIT)
            ->get(['term', 'target_id'])
            ->groupBy('term')
            ->map(fn ($group) => $group->pluck('target_id')->values()->all())
            ->all();
    }

    /**
     * Registra que con ese término se eligió ese resultado. Devuelve si se aprendió.
     */
    public function learn(string $term, string $type, string $id): bool
    {
        $normalized = self::normalize($term);
        $title = $this->titleOf($type, $id);

        // Si el nombre ya contiene lo buscado, no hay nada que aprender
        if (mb_strlen($normalized) < 2 || str_contains(self::normalize($title), $normalized)) {
            return false;
        }

        $match = ['term' => $normalized, 'target_type' => $type, 'target_id' => $id];

        if (SearchAlias::query()->where($match)->increment('hits') === 0) {
            try {
                SearchAlias::create($match + ['hits' => 1]);
            } catch (UniqueConstraintViolationException) {
                // Otra persona lo aprendió al mismo tiempo
                SearchAlias::query()->where($match)->increment('hits');
            }
        }

        return true;
    }

    private function titleOf(string $type, string $id): string
    {
        $title = $type === SearchAlias::TYPE_COURSE
            ? Course::query()->whereKey($id)->where('status', '!=', Course::STATUS_ARCHIVED)->value('title')
            : Package::query()->whereKey($id)->value('title');

        if ($title === null) {
            throw new ApiException(ErrorCode::NotFound, 'Ese curso o paquete no existe.', 404);
        }

        return $title;
    }
}
