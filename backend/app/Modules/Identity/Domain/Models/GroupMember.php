<?php

namespace App\Modules\Identity\Domain\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

class GroupMember extends Pivot
{
    use BelongsToTenant, HasUuids;

    public const UPDATED_AT = null;

    protected $table = 'group_members';

    public $incrementing = false;
}
