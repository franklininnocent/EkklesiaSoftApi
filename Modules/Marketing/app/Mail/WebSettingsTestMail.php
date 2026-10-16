<?php

namespace Modules\Marketing\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WebSettingsTestMail extends Mailable
{
    public function envelope(): Envelope
    {
        $product = (string) config('email.product_name', 'EkklesiaNexus');

        return new Envelope(
            subject: $product.' — Web Settings test email',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'marketing::emails.web-settings-test',
            text: 'marketing::emails.web-settings-test-text',
            with: [
                'preheader' => 'EkklesiaNexus mail transport test',
            ],
        );
    }
}
