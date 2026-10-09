<?php

namespace App\Modules\Sales\Application\Payments\Online;

use Illuminate\Http\Request;

/**
 * Pasarela de cobro en línea (Maestro §9.1). No sabe de órdenes ni de
 * organizaciones: recibe la cuenta con la que cobrar y qué cobrar. Con ella
 * cada organización cobra a sus alumnos con su propia cuenta, y la
 * plataforma cobra los planes con la suya. Qué pasa después con lo cobrado
 * lo decide quien la usa.
 */
interface OnlineGateway
{
    /** Etiqueta con la que el contenedor reúne las pasarelas disponibles */
    public const TAG = 'payments.online_gateways';

    /** Código que queda en payments.gateway */
    public function code(): string;

    /** Nombre que ve el comprador */
    public function label(): string;

    /**
     * Moneda en la que cobraría algo cuyo precio está en $currency: la
     * misma, otra (hace falta convertir con un tipo de cambio) o null si no
     * puede cobrarlo.
     */
    public function chargeCurrency(string $currency): ?string;

    /**
     * ¿Para saber si un cobro se pagó necesita el identificador de ese
     * cobro (startCheckout: external_id), o le basta la referencia?
     */
    public function tracksByCheckout(): bool;

    /**
     * Reglas de validación de las claves que pide para conectarse.
     *
     * @return array<string, list<mixed>>
     */
    public function credentialRules(): array;

    /**
     * Comprueba las claves con el proveedor y devuelve lo que se puede
     * mostrar de la cuenta. Con claves inválidas rechaza con un error de validación.
     *
     * @param  array<string, string|null>  $credentials
     * @return array<string, mixed>
     */
    public function describeAccount(array $credentials): array;

    /**
     * Prepara el cobro y dice a dónde enviar a quien paga.
     *
     * @param  array{return: string, webhook: ?string}  $urls
     * @return array{external_id: string, checkout_url: string}
     */
    public function startCheckout(GatewayCredentials $account, Charge $charge, array $urls): array;

    /**
     * Pagos que la pasarela tiene para esa referencia (Charge::$reference)
     * o para ese cobro (ver tracksByCheckout), del más reciente al más
     * antiguo. Si el cobro está aprobado y falta cerrarlo, lo cierra.
     *
     * @return list<GatewayPayment>
     */
    public function paymentsFor(GatewayCredentials $account, string $reference, ?string $checkoutId = null): array;

    /**
     * El pago del que avisa una notificación de la pasarela, consultado en la
     * propia pasarela (lo que diga la notificación no se da por cierto).
     * Null si el aviso no es de un pago.
     */
    public function paymentFromWebhook(GatewayCredentials $account, Request $request): ?GatewayPayment;

    /**
     * Devuelve completo un pago ya cobrado. Repetir la llamada no devuelve
     * dos veces. Si la pasarela no lo acepta, rechaza con un error.
     *
     * @return string identificador de la devolución en la pasarela
     */
    public function refund(GatewayCredentials $account, string $paymentId): string;
}
