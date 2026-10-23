<?php

namespace App\Modules\Tenancy\Application;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Avisos por correo al personal: le llegan a quien tiene el permiso de
 * atender eso (soporte, reclamos, pagos, páginas). Son correos editables
 * de la academia, que puede apagar los que no necesite.
 */
class StaffNotifier
{
    public function __construct(private readonly TenantNotifier $notifier) {}

    /**
     * @param  string|list<string>  $permissions  basta con tener uno
     * @param  array<string, string|int|float|null>  $values
     */
    public function notify(string|array $permissions, string $key, array $values = [], ?string $path = null, ?string $exceptUserId = null): void
    {
        foreach (self::holders((array) $permissions)->when($exceptUserId, fn (Builder $query) => $query->whereKeyNot($exceptUserId))->get() as $user) {
            $this->notifier->notify($user, $key, $values, $path);
        }
    }

    /**
     * Personal activo con alguno de esos permisos, contando los comodines («tickets.*», «*»).
     *
     * @param  list<string>  $permissions
     * @return Builder<User>
     */
    public static function holders(array $permissions): Builder
    {
        $codes = collect($permissions)
            ->flatMap(fn (string $permission) => [$permission, Str::before($permission, '.').'.*'])
            ->push('*')
            ->unique()
            ->values()
            ->all();

        return User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->whereHas('roles.permissions', fn (Builder $query) => $query->whereIn('code', $codes));
    }
}
