<?php

namespace Modules\Marketing\Mail;

use App\Support\UserFacingDate;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Modules\Marketing\Models\ContactEnquiry;

class ContactEnquiryNotificationMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly ContactEnquiry $enquiry) {}

    public function envelope(): Envelope
    {
        $product = (string) config('email.product_name', 'EkklesiaNexus');
        $visitorEmail = $this->safeEmail($this->enquiry->email);

        $envelope = new Envelope(
            subject: $product.' — Contact enquiry '.$this->enquiry->reference_number,
        );

        if ($visitorEmail !== null) {
            $envelope->replyTo([
                new Address($visitorEmail, $this->safeName($this->enquiry->full_name)),
            ]);
        }

        return $envelope;
    }

    public function content(): Content
    {
        return new Content(
            view: 'marketing::emails.contact-enquiry-notification',
            text: 'marketing::emails.contact-enquiry-notification-text',
            with: [
                'enquiry' => $this->enquiry,
                'submittedAtLabel' => UserFacingDate::formatDateTime($this->enquiry->created_at),
                'preheader' => 'New website contact enquiry '.$this->enquiry->reference_number,
            ],
        );
    }

    private function safeEmail(string $email): ?string
    {
        $clean = strtolower(trim(str_replace(["\r", "\n"], '', $email)));

        return filter_var($clean, FILTER_VALIDATE_EMAIL) ?: null;
    }

    private function safeName(string $name): string
    {
        $clean = trim(str_replace(["\r", "\n"], ' ', $name));

        return mb_substr($clean, 0, 80);
    }
}
