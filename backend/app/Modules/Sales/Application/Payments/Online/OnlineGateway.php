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

    /** ¿Puede cobrar órdenes en esta moneda? */
    public function supportsCurrency(string $currency): bool;

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
     * Pagos que la pasarela tiene para esa referencia (Charge::$reference),
     * del más reciente al más antiguo.
     *
     * @return list<GatewayPayment>
     */
    public function paymentsFor(GatewayCredentials $account, string $reference): array;

    /**
     * El pago del que avisa una notificación de la pasarela, consultado en la
     * propia pasarela (lo que diga la notificación no se da por cierto).
     * Null si el aviso no es de un pago.
     */
    public function paymentFromWebhook(GatewayCredentials $account, Request $request): ?GatewayPayment;
}
