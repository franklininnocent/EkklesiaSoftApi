<?php

namespace Modules\Authentication\Support;

use App\Support\Email\EmailSubject;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Envelope;

final class RecoveryMailEnvelope
{
    public static function make(string $subject): Envelope
    {
        $from = config('authentication.recovery.mail_from', []);

        return new Envelope(
            from: new Address(
                (string) ($from['address'] ?? 'franklininnocent.fs@gmail.com'),
                (string) ($from['name'] ?? 'EkklesiaSoft'),
            ),
            subject: EmailSubject::sanitize($subject),
        );
    }
}
