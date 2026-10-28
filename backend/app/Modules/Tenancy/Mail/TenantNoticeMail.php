<?php

namespace App\Modules\Tenancy\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * Aviso de una organización a una persona, con su nombre y su logo. Lleva
 * todo ya resuelto en texto: se puede enviar en segundo plano sin volver a
 * consultar la base.
 */
class TenantNoticeMail extends Mailable
{
    /**
     * @param  list<string>  $lines  párrafos del mensaje
     * @param  array{label: string, url: string}|null  $action  botón principal
     * @param  array{label: string, url: string, one_click: string}|null  $unsubscribe  baja de este tipo de correo: enlace al pie y cabecera para el programa de correo
     */
    public function __construct(
        public readonly string $organization,
        public readonly ?string $logoUrl,
        public readonly string $title,
        public readonly ?string $greeting,
        public readonly array $lines,
        public readonly ?array $action = null,
        public readonly ?array $unsubscribe = null,
        // Color principal de la academia (cabecera y botón); null = el de IntelectaLab
        public readonly ?string $color = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->title} · {$this->organization}");
    }

    /**
     * Con la baja, Gmail y otros muestran «Anular suscripción» junto al remitente (RFC 8058).
     */
    public function headers(): Headers
    {
        return new Headers(text: $this->unsubscribe === null ? [] : [
            'List-Unsubscribe' => '<'.$this->unsubscribe['one_click'].'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.notice');
    }
}
