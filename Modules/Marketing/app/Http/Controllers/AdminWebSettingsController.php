<?php

namespace Modules\Marketing\Http\Controllers;

use App\Support\UserFacingDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Modules\Marketing\Http\Requests\UpdateWebSettingsRequest;
use Modules\Marketing\Mail\WebSettingsTestMail;
use Modules\Marketing\Services\WebSettingsService;
use Throwable;

class AdminWebSettingsController extends Controller
{
    public function show(WebSettingsService $settings): JsonResponse
    {
        $row = $settings->current();
        $diagnostics = $settings->diagnostics();

        return response()->json([
            'success' => true,
            'data' => [
                'contact_recipient_email' => $row->contact_recipient_email,
                'contact_form_enabled' => (bool) $row->contact_form_enabled,
                'captcha_enabled' => (bool) $row->captcha_enabled,
                'captcha_ttl_seconds' => (int) $row->captcha_ttl_seconds,
                'enquiry_retention_days' => (int) $row->enquiry_retention_days,
                'updated_at' => $row->updated_at?->toIso8601String(),
                'updated_at_label' => UserFacingDate::formatDateTime($row->updated_at),
                'diagnostics' => $diagnostics,
            ],
        ]);
    }

    public function update(UpdateWebSettingsRequest $request, WebSettingsService $settings): JsonResponse
    {
        $row = $settings->update($request->validated(), $request->user());

        return response()->json([
            'success' => true,
            'message' => 'Web settings saved.',
            'data' => [
                'contact_recipient_email' => $row->contact_recipient_email,
                'contact_form_enabled' => (bool) $row->contact_form_enabled,
                'captcha_enabled' => (bool) $row->captcha_enabled,
                'captcha_ttl_seconds' => (int) $row->captcha_ttl_seconds,
                'enquiry_retention_days' => (int) $row->enquiry_retention_days,
                'diagnostics' => $settings->diagnostics(),
            ],
        ]);
    }

    public function sendTestEmail(WebSettingsService $settings): JsonResponse
    {
        $recipient = $settings->recipientEmail();
        if ($recipient === null) {
            return response()->json([
                'success' => false,
                'message' => 'Save a valid contact enquiry recipient before sending a test email.',
            ], 422);
        }

        try {
            Mail::to($recipient)->send(new WebSettingsTestMail);
        } catch (Throwable $e) {
            Log::warning('web.settings.test_email_failed', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'The mail transport rejected the test. The enquiry recipient was not changed.',
            ], 502);
        }

        return response()->json([
            'success' => true,
            'message' => 'A test message was handed to the mail transport. This does not confirm inbox delivery.',
        ]);
    }
}
