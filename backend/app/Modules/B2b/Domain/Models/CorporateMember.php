<?php

namespace App\Modules\B2b\Domain\Models;

use App\Modules\Identity\Domain\Models\User;
use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CorporateMember extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id',
        'corporate_account_id',
        'user_id',
        'is_manager',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_manager' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CorporateAccount::class, 'corporate_account_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
