<?php

namespace App\Modules\Billing\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Aviso sobre una orden de plan, a la plataforma o a la organización.
 */
class PlanOrderMail extends Mailable
{
    /**
     * @param  list<string>  $lines  párrafos del mensaje
     */
    public function __construct(
        public readonly string $title,
        public readonly array $lines,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.plan-order');
    }
}
