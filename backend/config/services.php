<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    // Avisos al teléfono (Web Push): llaves VAPID de la plataforma, generadas con `php artisan webpush:keys`
    'webpush' => [
        'public_key' => env('WEBPUSH_PUBLIC_KEY'),
        'private_key' => env('WEBPUSH_PRIVATE_KEY'),
        // Contacto que ven los servicios de avisos: la dirección del sitio (debe ser https) o un mailto:
        'subject' => env('WEBPUSH_SUBJECT', env('APP_URL')),
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
