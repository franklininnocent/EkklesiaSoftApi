<?php

namespace Modules\Authentication\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Modules\Authentication\Support\RecoveryMailEnvelope;

/**
 * Synchronous only — never queue. Password is passed only to the view at send time.
 */
class PasswordRecoveryTemporaryPasswordMail extends Mailable
{
    public function __construct(
        private readonly string $recipientName,
        #[\SensitiveParameter]
        private readonly string $temporaryPassword,
    ) {
    }

    public function envelope(): Envelope
    {
        return RecoveryMailEnvelope::make('EkklesiaSoft — Password Recovery');
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.messages.password-recovery-temporary-password',
            text: 'authentication::emails.password-recovery-temporary-password',
            with: [
                'recipientName' => $this->recipientName,
                'temporaryPassword' => $this->temporaryPassword,
                'preheader' => 'Your password recovery request has been approved.',
            ],
        );
    }
}
