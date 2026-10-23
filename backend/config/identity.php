<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Política de sesiones (RF-AUTH-002)
    |--------------------------------------------------------------------------
    |
    | Minutos de inactividad tras los cuales una sesión se cierra. Es el valor
    | por defecto: cada organización puede cambiarlo en su configuración.
    |
    */

    'session_lifetime_minutes' => (int) env('AUTH_SESSION_LIFETIME', 30),

    'session_lifetime_min' => 5,

    'session_lifetime_max' => 1440,

    /*
    |--------------------------------------------------------------------------
    | Recuperación de contraseña
    |--------------------------------------------------------------------------
    |
    | Minutos de vigencia del enlace enviado por correo. Es de un solo uso.
    |
    */

    'password_reset_minutes' => (int) env('AUTH_PASSWORD_RESET_MINUTES', 60),

    // Días que vale el enlace de una invitación para crear la contraseña
    'invitation_days' => (int) env('AUTH_INVITATION_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Bloqueo por intentos fallidos
    |--------------------------------------------------------------------------
    |
    | Tras esta cantidad de fallos seguidos, la cuenta no admite inicios de
    | sesión durante los minutos indicados. Un acceso correcto o recuperar la
    | contraseña reinician la cuenta.
    |
    */

    'login_lock_attempts' => (int) env('AUTH_LOGIN_LOCK_ATTEMPTS', 10),

    'login_lock_minutes' => (int) env('AUTH_LOGIN_LOCK_MINUTES', 15),

    /*
    |--------------------------------------------------------------------------
    | Tope general de peticiones
    |--------------------------------------------------------------------------
    |
    | Peticiones por minuto que admite la API de un mismo usuario o, sin
    | sesión, de una misma dirección. Las rutas públicas y de acceso tienen
    | además su propio tope, más bajo.
    |
    */

    'api_rate_limit' => (int) env('API_RATE_LIMIT', 300),

];
