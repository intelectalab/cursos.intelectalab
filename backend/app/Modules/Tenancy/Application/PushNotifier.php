<?php

namespace App\Modules\Tenancy\Application;

use App\Modules\Identity\Domain\Models\User;
use App\Modules\Tenancy\Domain\Models\PushSubscription;
use App\Modules\Tenancy\Domain\Models\Tenant;
use App\Modules\Tenancy\Jobs\SendWebPush;
use Illuminate\Support\Facades\DB;

/**
 * Avisos al teléfono de una academia. Cada persona los activa en cada
 * dispositivo («Mi cuenta»); salen junto con el correo del mismo aviso y
 * respetan las mismas bajas (TenantNotifier decide cuáles).
 */
class PushNotifier
{
    /** Largo del texto del aviso: el sistema del teléfono corta lo demás */
    private const BODY_LENGTH = 160;

    public function __construct(private readonly PushGateway $gateway) {}

    /**
     * Sin llaves VAPID en el .env no hay avisos: la pantalla lo dice y no deja activarlos.
     */
    public static function enabled(): bool
    {
        return filled(config('services.webpush.public_key')) && filled(config('services.webpush.private_key'));
    }

    /**
     * Activa (o renueva) los avisos de un dispositivo para quien inició sesión.
     * Si el dispositivo era de otra persona de la academia, pasa a esta.
     *
     * @param  array{endpoint: string, keys: array{p256dh: string, auth: string}}  $data
     */
    public function subscribe(User $user, array $data, ?string $userAgent): PushSubscription
    {
        return PushSubscription::query()->updateOrCreate(
            ['endpoint_hash' => PushSubscription::hash($data['endpoint'])],
            [
                'user_id' => $user->id,
                'endpoint' => $data['endpoint'],
                'public_key' => $data['keys']['p256dh'],
                'auth_token' => $data['keys']['auth'],
                'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 255),
            ],
        );
    }

    public function unsubscribe(User $user, string $endpoint): void
    {
        PushSubscription::query()->where('user_id', $user->id)->where('endpoint_hash', PushSubscription::hash($endpoint))->delete();
    }

    public function devices(User $user): int
    {
        return PushSubscription::query()->where('user_id', $user->id)->count();
    }

    /**
     * Manda el aviso a los dispositivos de la persona, en segundo plano y
     * recién cuando la operación quedó guardada.
     */
    public function notify(Tenant $tenant, User $user, string $title, string $text, string $url, string $tag): void
    {
        if (! self::enabled() || ! PushSubscription::query()->where('user_id', $user->id)->exists()) {
            return;
        }

        $payload = [
            'title' => $title,
            'body' => mb_strimwidth(trim(strip_tags($text)), 0, self::BODY_LENGTH, '…'),
            'url' => $url,
            // El ícono lo sirve la API, que cuelga de la raíz del sitio y no de la ruta de la academia
            'icon' => TenantUrls::root($tenant).'/api/v1/app/icon/192'.(TenantUrls::pathMode() ? '?tenant='.$tenant->slug : ''),
            // Un aviso nuevo del mismo tipo reemplaza al anterior en vez de apilarse
            'tag' => $tag,
        ];

        DB::afterCommit(fn () => SendWebPush::dispatch($tenant->id, $user->id, $payload));
    }

    /**
     * La entrega en sí (la hace la tarea en cola). Los dispositivos que ya no
     * existen (la persona desinstaló o quitó el permiso) se borran.
     *
     * @param  array<string, string>  $payload
     */
    public function deliver(string $userId, array $payload): void
    {
        $subscriptions = PushSubscription::query()->where('user_id', $userId)->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        $result = $this->gateway->send($subscriptions, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $byEndpoint = $subscriptions->keyBy('endpoint');
        $expired = collect($result['expired'])->map(fn (string $endpoint) => $byEndpoint->get($endpoint)?->id)->filter();
        $delivered = collect($result['delivered'])->map(fn (string $endpoint) => $byEndpoint->get($endpoint)?->id)->filter();

        if ($expired->isNotEmpty()) {
            PushSubscription::query()->whereKey($expired)->delete();
        }
        if ($delivered->isNotEmpty()) {
            PushSubscription::query()->whereKey($delivered)->update(['last_used_at' => now()]);
        }
    }
}
