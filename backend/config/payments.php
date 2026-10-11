<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pasarelas de pago
    |--------------------------------------------------------------------------
    |
    | Cada organización conecta sus propias cuentas desde Configuración: aquí
    | no van credenciales, solo a dónde se llama a cada proveedor y cuánto se
    | le espera. Las direcciones cambian únicamente para pruebas.
    |
    */

    'timeout' => (int) env('PAYMENTS_TIMEOUT', 15),

    /*
    | Archivo de autoridades certificadoras con el que se comprueba el HTTPS de
    | las pasarelas. Vacío = el del sistema. Solo hace falta donde PHP no
    | tiene uno configurado (típico en Windows: «cURL error 60»).
    */

    'ca_bundle' => env('PAYMENTS_CA_BUNDLE'),

    'mercadopago' => [
        'base_url' => env('MERCADOPAGO_BASE_URL', 'https://api.mercadopago.com'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tipo de cambio automático
    |--------------------------------------------------------------------------
    |
    | SUNAT no ofrece un servicio propio: su tipo de cambio se lee de un
    | servicio de terceros que lo republica. Si el servicio pide una clave,
    | va en EXCHANGE_SUNAT_TOKEN.
    */

    'exchange' => [
        'sunat_url' => env('EXCHANGE_SUNAT_URL', 'https://api.apis.net.pe/v1/tipo-cambio-sunat'),
        'sunat_token' => env('EXCHANGE_SUNAT_TOKEN'),
    ],

    // Cada cuenta dice si sus claves son de pruebas (sandbox) o reales
    'paypal' => [
        'live_url' => env('PAYPAL_LIVE_URL', 'https://api-m.paypal.com'),
        'sandbox_url' => env('PAYPAL_SANDBOX_URL', 'https://api-m.sandbox.paypal.com'),
    ],

];
