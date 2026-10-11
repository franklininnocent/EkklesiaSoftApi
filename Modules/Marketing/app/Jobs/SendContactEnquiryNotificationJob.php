<?php

namespace Modules\Marketing\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Modules\Marketing\Mail\ContactEnquiryNotificationMail;
use Modules\Marketing\Models\ContactEnquiry;
use Modules\Marketing\Services\ContactEnquiryService;
use Modules\Marketing\Services\WebSettingsService;
use Throwable;

class SendContactEnquiryNotificationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [60, 120, 300, 600, 900];

    public int $uniqueFor = 120;

    public function __construct(public readonly string $enquiryId) {}

    public function uniqueId(): string
    {
        return $this->enquiryId;
    }

    public function handle(ContactEnquiryService $enquiries, WebSettingsService $settings): void
    {
        $enquiry = ContactEnquiry::query()->find($this->enquiryId);
        if ($enquiry === null) {
            return;
        }

        $recipient = $settings->recipientEmail();
        if ($recipient === null) {
            $enquiries->markFailed($enquiry, 'Contact recipient is not configured.');

            return;
        }

        $enquiries->markQueued($enquiry);

        Mail::to($recipient)->send(new ContactEnquiryNotificationMail($enquiry->fresh()));

        $enquiries->markSent($enquiry->fresh());
    }

    public function failed(?Throwable $exception): void
    {
        $enquiry = ContactEnquiry::query()->find($this->enquiryId);
        if ($enquiry === null) {
            return;
        }

        app(ContactEnquiryService::class)->handleJobException(
            $exception ?? new \RuntimeException('Unknown mail failure.'),
            $enquiry
        );
    }
}
