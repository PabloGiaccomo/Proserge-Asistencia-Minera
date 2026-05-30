<?php

namespace App\Mail;

use App\Models\PersonalFicha;
use App\Modules\Personal\Services\PersonalFichaEmailTemplateService;
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
        public readonly ?string $subjectOverride = null,
        public readonly ?string $bodyHtmlOverride = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectOverride
                ?: app(PersonalFichaEmailTemplateService::class)->renderSubject($this->ficha, $this->isResend),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.personal.ficha-link',
            with: [
                'bodyHtml' => $this->bodyHtmlOverride
                    ?: app(PersonalFichaEmailTemplateService::class)->renderBodyHtml($this->ficha, $this->url, $this->isResend),
            ],
        );
    }
}
