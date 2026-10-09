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

];
