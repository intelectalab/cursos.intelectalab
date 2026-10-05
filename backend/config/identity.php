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

];
