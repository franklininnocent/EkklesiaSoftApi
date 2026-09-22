<?php

namespace Modules\Authentication\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Authentication\Mail\PasswordRecoveryTemporaryPasswordMail;
use Modules\Authentication\Models\User;
use Throwable;

class PasswordRecoveryDeliveryService
{
    public function sendTemporaryPassword(User $recipient, #[\SensitiveParameter] string $temporaryPassword): void
    {
        if (! config('authentication.recovery.mail_enabled', true)) {
            return;
        }

        $mailable = new PasswordRecoveryTemporaryPasswordMail(
            recipientName: (string) $recipient->name,
            temporaryPassword: $temporaryPassword,
        );

        try {
            Mail::to((string) $recipient->email)->send($mailable);
        } catch (Throwable $e) {
            Log::error('Password recovery mail delivery failed', [
                'user_id' => $recipient->id,
                'mailer' => config('mail.default'),
                'host' => config('mail.mailers.smtp.host'),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
