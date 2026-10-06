<?php

namespace App\Modules\Support\Domain\Models;

use App\Modules\Files\Domain\Models\File;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketAttachment extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'ticket_message_id',
        'file_id',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(TicketMessage::class, 'ticket_message_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
