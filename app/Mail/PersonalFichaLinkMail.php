<?php

namespace App\Mail;

use App\Models\PersonalFicha;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PersonalFichaLinkMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly PersonalFicha $ficha,
        public readonly string $url,
        public readonly bool $isResend = false,
    ) {
    }

    public function envelope(): Envelope
    {
        $document = trim($this->ficha->tipo_documento . ' ' . $this->ficha->numero_documento);

        return new Envelope(
            subject: ($this->isResend ? 'Reenvio' : 'Envio') . ' de link para completar ficha - ' . $document,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.personal.ficha-link',
        );
    }
}
