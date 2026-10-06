<?php

namespace App\Modules\Enrollments\Domain\Models;

use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Identity\Domain\Models\User;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Enrollment extends Model
{
    use BelongsToTenant, HasUuids;

    /** Estado guardado: la matrícula existe o fue cancelada */
    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_CANCELLED = 'CANCELLED';

    /** Situaciones que se deducen de las fechas (no se guardan) */
    public const STATE_UPCOMING = 'UPCOMING';

    public const STATE_EXPIRED = 'EXPIRED';

    public const STATE_COMPLETED = 'COMPLETED';

    public const STATES = [
        self::STATUS_ACTIVE, self::STATE_UPCOMING, self::STATE_EXPIRED, self::STATE_COMPLETED, self::STATUS_CANCELLED,
    ];

    public const SOURCE_MANUAL = 'MANUAL';

    /** La creó el pago de una orden de compra */
    public const SOURCE_PURCHASE = 'PURCHASE';

    /** La creó la asignación de una licencia corporativa */
    public const SOURCE_LICENSE = 'LICENSE';

    protected $fillable = [
        'tenant_id',
        'course_id',
        'student_id',
        'status',
        'source',
        'enrolled_by',
        'enrolled_at',
        'cancelled_at',
        'starts_at',
        'ends_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'enrolled_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * Situación de la matrícula hoy. Vencida y por iniciar mandan sobre
     * completada: lo que importa primero es si el alumno puede entrar.
     */
    public function state(): string
    {
        return match (true) {
            ! $this->isActive() => self::STATUS_CANCELLED,
            $this->ends_at !== null && $this->ends_at->isPast() => self::STATE_EXPIRED,
            $this->starts_at !== null && $this->starts_at->isFuture() => self::STATE_UPCOMING,
            $this->completed_at !== null => self::STATE_COMPLETED,
            default => self::STATUS_ACTIVE,
        };
    }

    /**
     * RN-003: el acceso exige una matrícula activa y dentro de su vigencia.
     */
    public function hasAccess(): bool
    {
        return in_array($this->state(), [self::STATUS_ACTIVE, self::STATE_COMPLETED], true);
    }

    /**
     * Matrículas que hoy dan acceso al curso.
     *
     * @param  Builder<Enrollment>  $query
     */
    public function scopeAccessible(Builder $query): void
    {
        $this->scopeInState($query, self::STATUS_ACTIVE);
    }

    /**
     * Filtra por la situación deducida de las fechas. ACTIVE incluye las completadas vigentes.
     *
     * @param  Builder<Enrollment>  $query
     */
    public function scopeInState(Builder $query, string $state): void
    {
        $now = now();
        $started = fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
        $notEnded = fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $now);

        match ($state) {
            self::STATUS_CANCELLED => $query->where('status', self::STATUS_CANCELLED),
            self::STATE_EXPIRED => $query->where('status', self::STATUS_ACTIVE)->where('ends_at', '<', $now),
            self::STATE_UPCOMING => $query->where('status', self::STATUS_ACTIVE)->where('starts_at', '>', $now)->where($notEnded),
            self::STATE_COMPLETED => $query->where('status', self::STATUS_ACTIVE)->whereNotNull('completed_at')->where($started)->where($notEnded),
            default => $query->where('status', self::STATUS_ACTIVE)->where($started)->where($notEnded),
        };
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }
}
