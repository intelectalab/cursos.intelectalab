<?php

namespace App\Modules\Academic\Domain\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Question extends Model
{
    use BelongsToTenant, HasUuids;

    public const TYPE_SINGLE = 'SINGLE';

    public const TYPE_MULTIPLE = 'MULTIPLE';

    public const TYPE_TRUE_FALSE = 'TRUE_FALSE';

    public const TYPE_SHORT_TEXT = 'SHORT_TEXT';

    public const TYPE_NUMERIC = 'NUMERIC';

    public const TYPE_MATCHING = 'MATCHING';

    public const TYPE_ORDERING = 'ORDERING';

    /** Todos se califican solos. La respuesta abierta (revisión manual) queda para después. */
    public const TYPES = [
        self::TYPE_SINGLE, self::TYPE_MULTIPLE, self::TYPE_TRUE_FALSE, self::TYPE_SHORT_TEXT,
        self::TYPE_NUMERIC, self::TYPE_MATCHING, self::TYPE_ORDERING,
    ];

    protected $fillable = [
        'tenant_id',
        'question_bank_id',
        'type',
        'statement',
        'config',
        'explanation',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
        ];
    }

    public function bank(): BelongsTo
    {
        return $this->belongsTo(QuestionBank::class, 'question_bank_id');
    }

    public function quizQuestions(): HasMany
    {
        return $this->hasMany(QuizQuestion::class);
    }
}
