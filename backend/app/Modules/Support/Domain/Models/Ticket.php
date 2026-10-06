<?php

namespace App\Modules\Support\Domain\Models;

use App\Modules\Courses\Domain\Models\Course;
use App\Modules\Identity\Domain\Models\User;
use App\Modules\Sales\Domain\Models\Order;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ticket extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUS_OPEN = 'OPEN';

    public const STATUS_IN_PROGRESS = 'IN_PROGRESS';

    public const STATUS_RESOLVED = 'RESOLVED';

    public const STATUS_CLOSED = 'CLOSED';

    public const STATUSES = [self::STATUS_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_RESOLVED, self::STATUS_CLOSED];

    public const CATEGORIES = ['ACCESS', 'COURSE', 'PAYMENT', 'CERTIFICATE', 'OTHER'];

    public const PRIORITIES = ['LOW', 'NORMAL', 'HIGH'];

    public const BY_REQUESTER = 'REQUESTER';

    public const BY_STAFF = 'STAFF';

    /** Días tras los cuales un ticket resuelto y sin respuesta se cierra solo */
    public const AUTO_CLOSE_DAYS = 7;

    /** Uso de archivos (config/files.php) y máximo de adjuntos por mensaje */
    public const FILE_PURPOSE = 'ticket_attachment';

    public const MAX_ATTACHMENTS = 3;

    protected $fillable = [
        'tenant_id',
        'number',
        'requester_id',
        'subject',
        'category',
        'priority',
        'status',
        'assignee_id',
        'course_id',
        'order_id',
        'resolved_at',
        'closed_at',
        'last_message_at',
        'last_message_by',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'resolved_at' => 'datetime',
            'closed_at' => 'datetime',
            'last_message_at' => 'datetime',
        ];
    }

    public function isClosed(): bool
    {
        return $this->status === self::STATUS_CLOSED;
    }

    /**
     * Sigue abierto y lo último lo dijo quien consulta: le toca al personal.
     */
    public function awaitsStaff(): bool
    {
        return in_array($this->status, [self::STATUS_OPEN, self::STATUS_IN_PROGRESS], true)
            && $this->last_message_by === self::BY_REQUESTER;
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)->orderBy('created_at')->orderBy('id');
    }
}
