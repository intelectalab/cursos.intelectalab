<?php

namespace App\Modules\Identity\Mail;

use App\Modules\Identity\Domain\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class PasswordResetMail extends Mailable
{
    public function __construct(
        public readonly User $user,
        public readonly string $organization,
        public readonly string $url,
        public readonly int $minutes,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Restablece tu contraseña de {$this->organization}");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.password-reset');
    }
}
