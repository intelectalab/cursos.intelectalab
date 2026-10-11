<?php

namespace App\Modules\Sales\Application\Payments\Online;

use App\Shared\Http\ApiException;
use App\Shared\Http\ErrorCode;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Convierte lo que falla al hablar con una pasarela en un mensaje que diga
 * qué pasó y qué hacer. Lo que la pasarela respondió queda siempre en el
 * registro del servidor; lo que no se reconoce se muestra tal cual, entre
 * comillas, en vez de esconderlo tras un «inténtalo de nuevo».
 */
class GatewayErrors
{
    /**
     * No se pudo llegar a la pasarela: se distingue por qué, porque cada
     * causa la arregla una persona distinta.
     */
    public static function connection(string $gateway, Throwable $error): ApiException
    {
        Log::warning("{$gateway} no respondió: ".$error->getMessage());

        $reason = Str::lower($error->getMessage());

        [$cause, $message] = match (true) {
            Str::contains($reason, ['curl error 28', 'timed out', 'timeout']) => [
                'timeout',
                "{$gateway} tardó demasiado en responder y no sabemos si la operación llegó a hacerse. Antes de repetirla, compruébalo en tu cuenta de {$gateway}.",
            ],
            Str::contains($reason, ['curl error 60', 'curl error 77', 'curl error 35', 'ssl certificate', 'certificate']) => [
                'certificate',
                "El servidor no pudo verificar la conexión segura con {$gateway}: faltan o están vencidos los certificados del servidor. No es un problema de tu cuenta; lo resuelve quien administra el servidor.",
            ],
            Str::contains($reason, ['curl error 6', 'curl error 7', 'could not resolve', 'failed to connect', 'connection refused']) => [
                'unreachable',
                "El servidor no pudo conectarse con {$gateway}: no tiene salida a internet hacia ese servicio, o {$gateway} está caído. No es un problema de tu cuenta.",
            ],
            default => ['unknown', "{$gateway} no respondió. Inténtalo de nuevo en unos minutos."],
        };

        return new ApiException(ErrorCode::GatewayError, $message, 502, ['gateway_cause' => $cause]);
    }

    /**
     * Mercado Pago rechazó una operación.
     *
     * @param  bool  $refund  lo que se pedía era una devolución
     */
    public static function mercadoPago(Response $response, bool $refund = false): ApiException
    {
        Log::warning('Mercado Pago rechazó una operación.', ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500)]);

        $status = $response->status();
        $said = self::text($response->json('cause.0.description') ?? $response->json('message') ?? $response->json('error'));
        $reason = Str::lower($said.' '.self::text($response->json('message')).' '.self::text($response->json('error')));

        $message = match (true) {
            // La clave es válida, pero Mercado Pago no deja usarla para esta operación (pasa con las cuentas de prueba y con aplicaciones sin habilitar)
            $status === 401 && Str::contains($reason, 'live credentials') => 'Mercado Pago no permite hacer esta operación desde el sistema con esta cuenta (respondió «Unauthorized use of live credentials»). Devuelve el dinero desde tu cuenta de Mercado Pago, en el detalle del pago, y vuelve a pulsar «Reembolsar» aquí: el sistema verá que ya está devuelto y dejará la orden reembolsada.',
            $status === 401 => 'Mercado Pago rechazó la clave guardada: pudo cambiarse o revocarse. Conecta la cuenta otra vez en Configuración, en «Cobros en línea».',
            // Con una clave que no existe o ya no vale, Mercado Pago no responde 401 sino este 403 (comprobado con su API el 2026-10-11)
            $status === 403 => 'Mercado Pago no reconoce la clave guardada o no le permite esta operación'.self::quoted($said).'. Lo más probable es que la clave haya cambiado o se haya revocado: conecta la cuenta otra vez en Configuración, en «Cobros en línea». Si acabas de conectarla, revisa en Mercado Pago que la cuenta esté verificada y habilitada para cobrar.',
            $status === 404 => 'Mercado Pago no encuentra ese pago. Puede haberse cobrado con otra cuenta: comprueba que la cuenta conectada sea la misma con la que se cobró.',
            $status === 429 => 'Mercado Pago está recibiendo demasiadas consultas de esta cuenta. Espera un minuto y vuelve a intentarlo.',
            $status >= 500 => "Mercado Pago tiene un problema en este momento (error {$status} de su lado). No es tu cuenta ni el sistema: inténtalo de nuevo en unos minutos.",
            $refund && Str::contains($reason, ['too old', 'expired', 'time limit']) => 'Mercado Pago ya no permite devolver este pago: pasó el plazo que da para devoluciones. Tendrás que devolver el dinero por otro medio.',
            $refund && Str::contains($reason, ['insufficient', 'not enough', 'available money', 'available balance']) => 'Tu cuenta de Mercado Pago no tiene saldo disponible para devolver este pago. Agrega saldo, o espera a que se libere el dinero, y vuelve a intentarlo.',
            $refund && Str::contains($reason, ['already refunded', 'payment state', 'current state', 'not valid for the current']) => 'Mercado Pago dice que este pago no se puede devolver en su estado actual (por ejemplo, ya está devuelto o tiene un reclamo abierto). Revísalo en tu cuenta de Mercado Pago.',
            Str::contains($reason, 'currency') => 'Mercado Pago no acepta la moneda de este cobro con la cuenta conectada'.self::quoted($said).'.',
            Str::contains($reason, ['unit_price', 'transaction_amount', 'amount']) => 'Mercado Pago no acepta el importe de esta operación'.self::quoted($said).'. Puede estar por debajo del mínimo o por encima del máximo que permite.',
            Str::contains($reason, ['payer', 'email']) => 'Mercado Pago no acepta los datos del comprador'.self::quoted($said).'. Revisa que su correo sea válido.',
            $said !== '' => "Mercado Pago no aceptó la operación y respondió: «{$said}».".($refund ? ' Revisa el pago en tu cuenta de Mercado Pago.' : ''),
            default => "Mercado Pago no aceptó la operación (error {$status}) y no explicó por qué. Revísalo en tu cuenta de Mercado Pago.",
        };

        return new ApiException(ErrorCode::GatewayError, $message, 502, ['gateway_status' => $status, 'gateway_reason' => $said ?: null]);
    }

    /**
     * PayPal rechazó una operación.
     *
     * @param  bool  $refund  lo que se pedía era una devolución
     */
    public static function payPal(Response $response, bool $refund = false): ApiException
    {
        Log::warning('PayPal rechazó una operación.', [
            'status' => $response->status(),
            'debug_id' => $response->json('debug_id'),
            'body' => mb_substr($response->body(), 0, 500),
        ]);

        $status = $response->status();
        // PayPal nombra cada problema con un código en mayúsculas y lo explica en una frase
        $issue = Str::upper(self::text($response->json('details.0.issue') ?? $response->json('name') ?? $response->json('error')));
        $said = self::text($response->json('details.0.description') ?? $response->json('message') ?? $response->json('error_description'));
        $shown = trim($issue.($issue !== '' && $said !== '' ? ': ' : '').$said);

        $message = match (true) {
            $status === 401 || Str::contains($issue, ['AUTHENTICATION_FAILURE', 'INVALID_CLIENT']) => 'PayPal rechazó las claves guardadas: pudieron cambiarse o revocarse. Conecta la cuenta otra vez en Configuración, en «Cobros en línea».',
            Str::contains($issue, ['PAYEE_ACCOUNT', 'ACCOUNT_RESTRICTED', 'ACCOUNT_LOCKED']) => 'PayPal tiene restringida la cuenta que cobra. Entra a tu cuenta de PayPal y resuelve lo que te pide antes de volver a cobrar o devolver.',
            $status === 403 || Str::contains($issue, ['PERMISSION_DENIED', 'NOT_AUTHORIZED']) => 'La aplicación de PayPal conectada no tiene permiso para esta operación. Revisa en PayPal que tenga habilitados los cobros y las devoluciones.',
            Str::contains($issue, 'CURRENCY') => 'La cuenta de PayPal conectada no puede cobrar en esa moneda'.self::quoted($said).'.',
            Str::contains($issue, 'FULLY_REFUNDED') => 'PayPal dice que este pago ya fue devuelto por completo. Revísalo en tu cuenta de PayPal.',
            Str::contains($issue, 'TIME_LIMIT') => 'PayPal ya no permite devolver este pago: pasó el plazo que da para devoluciones. Tendrás que devolver el dinero por otro medio.',
            Str::contains($issue, 'INSUFFICIENT_FUNDS') => 'Tu cuenta de PayPal no tiene saldo disponible para devolver este pago. Agrega saldo y vuelve a intentarlo.',
            Str::contains($issue, ['INSTRUMENT_DECLINED', 'PAYER_CANNOT_PAY', 'TRANSACTION_REFUSED']) => 'PayPal rechazó el medio de pago del comprador. Debe intentarlo con otra tarjeta o con su saldo de PayPal.',
            Str::contains($issue, ['ORDER_NOT_APPROVED', 'PAYER_ACTION_REQUIRED']) => 'El comprador todavía no aprobó el pago en PayPal.',
            $status === 404 || Str::contains($issue, ['RESOURCE_NOT_FOUND', 'INVALID_RESOURCE_ID']) => 'PayPal no encuentra ese pago. Puede haberse cobrado con otra cuenta: comprueba que la cuenta conectada sea la misma con la que se cobró.',
            $status === 429 || Str::contains($issue, 'RATE_LIMIT') => 'PayPal está recibiendo demasiadas consultas de esta cuenta. Espera un minuto y vuelve a intentarlo.',
            $status >= 500 => "PayPal tiene un problema en este momento (error {$status} de su lado). No es tu cuenta ni el sistema: inténtalo de nuevo en unos minutos.",
            $shown !== '' => "PayPal no aceptó la operación y respondió: «{$shown}».".($refund ? ' Revisa el pago en tu cuenta de PayPal.' : ''),
            default => "PayPal no aceptó la operación (error {$status}) y no explicó por qué. Revísalo en tu cuenta de PayPal.",
        };

        return new ApiException(ErrorCode::GatewayError, $message, 502, ['gateway_status' => $status, 'gateway_reason' => $shown ?: null]);
    }

    /**
     * Quien paga no administra la cuenta de la pasarela: no le sirve saber
     * que una clave venció o que falta saldo. Recibe un mensaje sencillo; la
     * causa queda en el registro del servidor, que se ubica con el código
     * que la pantalla le muestra junto al mensaje.
     *
     * @template T
     *
     * @param  callable(): T  $action
     * @return T
     */
    public static function forPayer(callable $action, string $message): mixed
    {
        try {
            return $action();
        } catch (ApiException $error) {
            if ($error->errorCode !== ErrorCode::GatewayError) {
                throw $error;
            }

            throw new ApiException(ErrorCode::GatewayError, $message, 502);
        }
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim(mb_substr((string) $value, 0, 200)) : '';
    }

    private static function quoted(string $said): string
    {
        return $said !== '' ? " (respondió «{$said}»)" : '';
    }
}
