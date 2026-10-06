<?php

namespace App\Modules\Sales\Domain\Models;

use App\Modules\Courses\Domain\Models\Course;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Package extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_INACTIVE = 'INACTIVE';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE];

    /** Un paquete reúne al menos dos cursos */
    public const MIN_COURSES = 2;

    protected $fillable = [
        'tenant_id',
        'title',
        'summary',
        'price',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
        ];
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * En venta: activo y con todos sus cursos todavía en venta. Si la
     * academia retira o archiva uno, el paquete sale del catálogo solo.
     */
    public function isForSale(): bool
    {
        return $this->isActive()
            && $this->courses->count() >= self::MIN_COURSES
            && $this->courses->every(fn (Course $course) => $course->isForSale());
    }

    /**
     * Lo que costarían sus cursos comprados por separado.
     */
    public function listPrice(): string
    {
        return number_format((float) $this->courses->sum(fn (Course $course) => (float) $course->price), 2, '.', '');
    }

    public function courses(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'package_items')->withPivot('position')->orderByPivot('position');
    }
}
