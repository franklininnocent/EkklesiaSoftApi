<?php

namespace Modules\Authentication\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Authentication\Support\RecoveryMailEnvelope;

class PasswordRecoveryRequestNotificationMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $requesterName,
        public readonly string $requesterEmail,
        public readonly ?string $tenantName,
        public readonly string $requesterRole,
        public readonly string $requestedAt,
        public readonly string $requestId,
    ) {
    }

    public function envelope(): Envelope
    {
        return RecoveryMailEnvelope::make('EkklesiaSoft — Password Recovery Request');
    }

    public function content(): Content
    {
        return new Content(
            text: 'authentication::emails.password-recovery-request-notification',
            with: [
                'requesterName' => $this->requesterName,
                'requesterEmail' => $this->requesterEmail,
                'tenantName' => $this->tenantName,
                'requesterRole' => $this->requesterRole,
                'requestedAt' => $this->requestedAt,
                'requestId' => $this->requestId,
            ],
        );
    }
}
