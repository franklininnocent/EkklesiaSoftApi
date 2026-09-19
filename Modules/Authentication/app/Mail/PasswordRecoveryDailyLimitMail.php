<?php

namespace Modules\Authentication\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Authentication\Support\RecoveryMailEnvelope;

class PasswordRecoveryDailyLimitMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $targetUserName,
        public readonly string $targetUserEmail,
        public readonly ?string $tenantName,
        public readonly int $attemptCount,
        public readonly string $detectedAt,
    ) {
    }

    public function envelope(): Envelope
    {
        return RecoveryMailEnvelope::make('EkklesiaSoft — Security alert: password recovery limit reached');
    }

    public function content(): Content
    {
        return new Content(
            text: 'authentication::emails.password-recovery-daily-limit',
            with: [
                'targetUserName' => $this->targetUserName,
                'targetUserEmail' => $this->targetUserEmail,
                'tenantName' => $this->tenantName,
                'attemptCount' => $this->attemptCount,
                'detectedAt' => $this->detectedAt,
            ],
        );
    }
}
