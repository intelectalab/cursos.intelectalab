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
    | Cómo se llega a cada organización:
    |   subdomain  {slug}.{central_domain}           (por defecto)
    |   path       {central_domain}/a/{slug}         (sin registro DNS comodín)
    | En el modo por ruta la aplicación web envía la organización en el
    | encabezado X-Tenant; los avisos de las pasarelas, en ?tenant=.
    */

    'mode' => env('TENANCY_MODE', 'subdomain'),

    /*
    | Dirección pública de cada organización, con {tenant} en el lugar de su
    | identificador. La usan los enlaces de los correos que salen de tareas
    | programadas. Vacío: el esquema de APP_URL, el dominio central y el modo.
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
