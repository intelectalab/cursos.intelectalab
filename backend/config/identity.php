<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Política de sesiones (RF-AUTH-002)
    |--------------------------------------------------------------------------
    |
    | Minutos de vigencia de una sesión desde que se inicia. Al vencer, el
    | usuario debe volver a iniciar sesión.
    |
    */

    'session_lifetime_minutes' => (int) env('AUTH_SESSION_LIFETIME', 720),

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
