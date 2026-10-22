<?php

namespace App\Modules\Tenancy\Domain\Models;

use App\Shared\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Con qué correo salen los avisos de una academia.
 */
class TenantMailSetting extends Model
{
    use BelongsToTenant, HasUuids;

    /** Por el correo de la plataforma, con el nombre de la academia y su correo para respuestas */
    public const MODE_PLATFORM = 'PLATFORM';

    /** Por el servidor de correo propio de la academia */
    public const MODE_OWN = 'OWN';

    public const MODES = [self::MODE_PLATFORM, self::MODE_OWN];

    public const SECURITY = ['tls', 'ssl', 'none'];

    protected $fillable = [
        'tenant_id',
        'mode',
        'from_name',
        'reply_to',
        'from_address',
        'smtp',
        'last_sent_at',
        'last_error',
        'last_error_at',
    ];

    protected $hidden = ['smtp'];

    protected function casts(): array
    {
        return [
            // host, port, security, username, password
            'smtp' => 'encrypted:array',
            'last_sent_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }
}
