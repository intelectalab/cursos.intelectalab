<?php

namespace App\Shared\Http;

use Illuminate\Database\Eloquent\Builder;

/**
 * Cuántos registros de un listado hay en cada estado. La pantalla los muestra
 * como tarjetas: al tocar una, el listado se filtra por ese estado.
 */
class StateCounts
{
    /**
     * @param  Builder<*>  $base  listado con sus filtros, sin el de estado
     * @param  list<string>  $states
     * @param  callable(Builder<*>, string): mixed  $filter  cómo se filtra por un estado
     * @return array<string, int> incluye la clave ALL con el total
     */
    public static function of(Builder $base, array $states, callable $filter): array
    {
        $counts = ['ALL' => (clone $base)->count()];

        foreach ($states as $state) {
            $query = clone $base;
            $filter($query, $state);
            $counts[$state] = $query->count();
        }

        return $counts;
    }
}
