<?php

namespace App\Modules\Tenancy\Application;

use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Avisos con el estándar Web Push: cada aviso va cifrado para su dispositivo
 * y firmado con las llaves VAPID de la plataforma (WEBPUSH_* en el .env).
 */
class WebPushGateway implements PushGateway
{
    public function send(iterable $subscriptions, string $payload): array
    {
        $push = new WebPush(['VAPID' => [
            'subject' => config('services.webpush.subject'),
            'publicKey' => config('services.webpush.public_key'),
            'privateKey' => config('services.webpush.private_key'),
        ]], ['TTL' => 86400, 'urgency' => 'normal']);

        foreach ($subscriptions as $subscription) {
            $push->queueNotification(Subscription::create([
                'endpoint' => $subscription->endpoint,
                'publicKey' => $subscription->public_key,
                'authToken' => $subscription->auth_token,
            ]), $payload);
        }

        $result = ['delivered' => [], 'expired' => []];

        foreach ($push->flush() as $report) {
            if ($report->isSuccess()) {
                $result['delivered'][] = $report->getEndpoint();
            } elseif ($report->isSubscriptionExpired()) {
                $result['expired'][] = $report->getEndpoint();
            } else {
                Log::warning('No se pudo entregar un aviso al teléfono: '.$report->getReason());
            }
        }

        return $result;
    }
}
