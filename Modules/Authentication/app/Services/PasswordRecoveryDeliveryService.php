<?php

namespace Modules\Authentication\Services;

use Illuminate\Support\Facades\Mail;
use Modules\Authentication\Mail\PasswordRecoveryTemporaryPasswordMail;
use Modules\Authentication\Models\User;

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

        Mail::to((string) $recipient->email)->send($mailable);
    }
}
