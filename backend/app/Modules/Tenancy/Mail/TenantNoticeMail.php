<?php

namespace App\Modules\Tenancy\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

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
     */
    public function __construct(
        public readonly string $organization,
        public readonly ?string $logoUrl,
        public readonly string $title,
        public readonly ?string $greeting,
        public readonly array $lines,
        public readonly ?array $action = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->title} · {$this->organization}");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.notice');
    }
}
