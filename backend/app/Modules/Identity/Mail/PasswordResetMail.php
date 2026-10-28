<?php

namespace App\Modules\Identity\Mail;

use App\Modules\Tenancy\Mail\TenantNoticeMail;

/**
 * Enlace para crear una contraseña nueva. Tiene el diseño de los avisos de
 * la academia y el texto del correo «recuperar_contrasena»; guarda además el
 * enlace, que el botón abre.
 */
class PasswordResetMail extends TenantNoticeMail
{
    /**
     * @param  list<string>  $lines
     * @param  array{label: string, url: string}|null  $action
     */
    public function __construct(
        string $organization,
        ?string $logoUrl,
        string $title,
        ?string $greeting,
        array $lines,
        ?array $action,
        public readonly string $url,
        ?string $color = null,
    ) {
        parent::__construct($organization, $logoUrl, $title, $greeting, $lines, $action, color: $color);
    }
}
