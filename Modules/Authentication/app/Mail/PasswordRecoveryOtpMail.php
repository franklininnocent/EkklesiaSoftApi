<?php

namespace Modules\Authentication\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Authentication\Support\RecoveryMailEnvelope;
class PasswordRecoveryOtpMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $recipientEmail,
        public readonly string $otp,
        public readonly int $expiresInSeconds,
        public readonly string $challengeId,
    ) {
    }

    public function envelope(): Envelope
    {
        return RecoveryMailEnvelope::make('EkklesiaSoft — Password Reset Verification');
    }

    public function content(): Content
    {
        return new Content(
            text: 'authentication::emails.password-recovery-otp',
            with: [
                'otp' => $this->otp,
                'expiresInSeconds' => $this->expiresInSeconds,
            ],
        );
    }

}
