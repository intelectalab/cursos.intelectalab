<?php

namespace App\Modules\Academic\Domain\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class QuizAnswer extends Model
{
    use BelongsToTenant, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'attempt_id',
        'question_id',
        'answer',
        'score',
        'points_possible',
        'points_earned',
    ];

    protected function casts(): array
    {
        return [
            // Objeto JSON: una respuesta puede ser texto, número, booleano, lista o mapa
            'answer' => 'json',
            'score' => 'float',
            'points_possible' => 'float',
            'points_earned' => 'float',
        ];
    }
}
