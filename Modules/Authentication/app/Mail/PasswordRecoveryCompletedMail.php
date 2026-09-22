<?php

namespace Modules\Authentication\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Authentication\Support\RecoveryMailEnvelope;

class PasswordRecoveryCompletedMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $targetUserName,
        public readonly string $targetUserEmail,
        public readonly ?string $tenantName,
        public readonly string $completedAt,
    ) {
    }

    public function envelope(): Envelope
    {
        return RecoveryMailEnvelope::make('EkklesiaSoft — Password reset completed');
    }

    public function content(): Content
    {
        return new Content(
            html: 'emails.messages.password-recovery-completed',
            text: 'authentication::emails.password-recovery-completed',
            with: [
                'targetUserName' => $this->targetUserName,
                'targetUserEmail' => $this->targetUserEmail,
                'tenantName' => $this->tenantName,
                'completedAt' => $this->completedAt,
                'preheader' => 'A password reset was completed for an account you oversee.',
            ],
        );
    }
}
