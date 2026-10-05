<?php

namespace App\Modules\Sales\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo global: no pertenece a ninguna organización.
 */
class Currency extends Model
{
    use HasUuids;

    protected $fillable = [
        'code',
        'name',
        'symbol',
    ];

    public static function byCode(string $code): self
    {
        return self::query()->where('code', $code)->firstOrFail();
    }
}
