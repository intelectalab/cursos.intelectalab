<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dominio central
    |--------------------------------------------------------------------------
    |
    | Host de la plataforma sin tenant (registro de organizaciones y operaciones
    | globales). Cada organización vive en {slug}.{central_domain}.
    |
    */

    'central_domain' => env('TENANCY_CENTRAL_DOMAIN', 'localhost'),

    /*
    | Dirección pública de cada organización, con {tenant} en el lugar de su
    | subdominio. La usan los enlaces de los correos que salen de tareas
    | programadas. Vacío: https (o el esquema de APP_URL) y el dominio central.
    */

    'public_url' => env('TENANCY_PUBLIC_URL'),

    /*
    |--------------------------------------------------------------------------
    | Subdominios reservados
    |--------------------------------------------------------------------------
    |
    | No pueden registrarse como slug de una organización.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Proxies de confianza
    |--------------------------------------------------------------------------
    |
    | Direcciones (separadas por coma) del balanceador o proxy que recibe el
    | HTTPS y reenvía la petición, o "*" si el servidor solo es alcanzable a
    | través de él. Sin esto, la aplicación ve todas las peticiones como HTTP
    | y con la dirección del proxy en lugar de la del visitante.
    |
    */

    'trusted_proxies' => env('TRUSTED_PROXIES'),

    'reserved_slugs' => ['www', 'api', 'app', 'admin', 'static', 'cdn', 'mail', 'status', 'soporte', 'support'],

];
