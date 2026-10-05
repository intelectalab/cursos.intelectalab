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
    |--------------------------------------------------------------------------
    | Subdominios reservados
    |--------------------------------------------------------------------------
    |
    | No pueden registrarse como slug de una organización.
    |
    */

    'reserved_slugs' => ['www', 'api', 'app', 'admin', 'static', 'cdn', 'mail', 'status', 'soporte', 'support'],

];
