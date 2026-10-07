<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // Orígenes adicionales exactos, separados por coma (por ejemplo, una aplicación externa)
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))))),

    // Por defecto solo la propia plataforma: el dominio central y los subdominios de las organizaciones
    'allowed_origins_patterns' => [
        '#^https?://([a-z0-9-]+\.)?'.preg_quote((string) env('TENANCY_CENTRAL_DOMAIN', 'localhost'), '#').'(:\d+)?$#i',
    ],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['X-Request-ID', 'Retry-After'],

    'max_age' => 600,

    'supports_credentials' => false,

];
