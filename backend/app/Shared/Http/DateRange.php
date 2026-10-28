<?php

namespace App\Shared\Http;

use App\Modules\Tenancy\Application\TenantSettingsService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Filtro «entre tal día y tal día» de los listados. Los días llegan como
 * AAAA-MM-DD y se cuentan en la hora de la academia: «hoy» empieza a su
 * medianoche, no a la del servidor.
 */
final class DateRange
{
    /**
     * Reglas para validar el rango; los nombres llevan un prefijo si la pantalla tiene más de uno.
     *
     * @return array<string, list<string>>
     */
    public static function rules(string $prefix = ''): array
    {
        return [
            "{$prefix}from" => ['nullable', 'date_format:Y-m-d'],
            "{$prefix}to" => ['nullable', 'date_format:Y-m-d', "after_or_equal:{$prefix}from"],
        ];
    }

    /**
     * Limita el listado a lo que pasó entre esos días, ambos incluidos.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $filters  ya validados
     * @return Builder<TModel>
     */
    public static function apply(Builder $query, string $column, array $filters, string $prefix = ''): Builder
    {
        $timezone = app(TenantSettingsService::class)->timezone();
        $from = $filters["{$prefix}from"] ?? null;
        $to = $filters["{$prefix}to"] ?? null;

        return $query
            ->when($from, fn (Builder $q) => $q->where($column, '>=', CarbonImmutable::parse($from, $timezone)->startOfDay()->utc()))
            ->when($to, fn (Builder $q) => $q->where($column, '<=', CarbonImmutable::parse($to, $timezone)->endOfDay()->utc()));
    }

    /**
     * Para columnas que guardan solo el día (sin hora): se comparan tal cual.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<TModel>
     */
    public static function applyDays(Builder $query, string $column, array $filters, string $prefix = ''): Builder
    {
        return $query
            ->when($filters["{$prefix}from"] ?? null, fn (Builder $q, string $from) => $q->whereDate($column, '>=', $from))
            ->when($filters["{$prefix}to"] ?? null, fn (Builder $q, string $to) => $q->whereDate($column, '<=', $to));
    }
}
