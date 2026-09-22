<?php

namespace App\Mail;

use App\Support\Email\EmailSubject;
use App\Support\Email\EmailTheme;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Synchronous HTML+text wrapper for former Mail::raw transactional notices.
 * Callers must send() inside their own try/catch or queued job — do not queue this mailable.
 */
class TransactionalNotificationMail extends Mailable
{
    public function __construct(
        private readonly string $mailSubject,
        private readonly string $heading,
        private readonly string $body,
        private readonly ?string $preheader = null,
        private readonly ?string $ctaUrl = null,
        private readonly ?string $ctaLabel = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: EmailSubject::sanitize($this->mailSubject),
        );
    }

    public function content(): Content
    {
        $paragraphs = $this->splitParagraphs($this->body);

        return new Content(
            html: 'emails.messages.transactional-notification',
            text: 'emails.messages.transactional-notification-text',
            with: [
                'heading' => $this->heading,
                'preheader' => $this->preheader ?? $this->heading,
                'paragraphs' => $paragraphs,
                'plainBody' => $this->body,
                'ctaUrl' => $this->ctaUrl,
                'ctaLabel' => $this->ctaLabel,
                'productName' => EmailTheme::productName(),
            ],
        );
    }

    /**
     * @return list<string>
     */
    private function splitParagraphs(string $body): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $body);
        $parts = preg_split("/\n{2,}/", trim($normalized)) ?: [];

        $paragraphs = [];
        foreach ($parts as $part) {
            $line = trim($part);
            if ($line !== '') {
                $paragraphs[] = $line;
            }
        }

        return $paragraphs !== [] ? $paragraphs : [trim($normalized)];
    }
}
