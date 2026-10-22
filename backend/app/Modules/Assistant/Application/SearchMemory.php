<?php

namespace App\Modules\Assistant\Application;

use App\Modules\Assistant\Domain\Models\SearchAlias;
use App\Modules\Audit\Application\AuditLogger;
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
     * Los sinónimos aprendidos con el nombre de lo elegido, para revisarlos.
     *
     * @return list<array{id: string, term: string, target_type: string, target_id: string, title: ?string, hits: int}>
     */
    public function review(): array
    {
        $aliases = SearchAlias::query()->orderByDesc('hits')->orderBy('term')->get();
        $courses = Course::query()->whereIn('id', $aliases->where('target_type', SearchAlias::TYPE_COURSE)->pluck('target_id'))->pluck('title', 'id');
        $packages = Package::query()->whereIn('id', $aliases->where('target_type', SearchAlias::TYPE_PACKAGE)->pluck('target_id'))->pluck('title', 'id');

        return $aliases->map(fn (SearchAlias $alias) => [
            'id' => $alias->id,
            'term' => $alias->term,
            'target_type' => $alias->target_type,
            'target_id' => $alias->target_id,
            'title' => $alias->target_type === SearchAlias::TYPE_COURSE ? $courses->get($alias->target_id) : $packages->get($alias->target_id),
            'hits' => $alias->hits,
        ])->values()->all();
    }

    /**
     * Olvida un sinónimo equivocado.
     */
    public function forget(string $id, AuditLogger $audit): void
    {
        $alias = SearchAlias::query()->findOrFail($id);
        $audit->record('search_aliases.deleted', $alias, ['term' => $alias->term, 'target_id' => $alias->target_id], null);
        $alias->delete();
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
