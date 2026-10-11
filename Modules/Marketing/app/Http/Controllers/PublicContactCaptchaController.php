<?php

namespace Modules\Marketing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Marketing\Services\ContactCaptchaService;
use Modules\Marketing\Services\WebSettingsService;

class PublicContactCaptchaController extends Controller
{
    public function show(ContactCaptchaService $captcha, WebSettingsService $settings): JsonResponse
    {
        $web = $settings->current();
        if (! $web->contact_form_enabled) {
            return response()->json([
                'success' => false,
                'message' => 'The contact form is temporarily unavailable.',
            ], 503)->header('Cache-Control', 'no-store');
        }

        $issued = $captcha->issue();

        return response()->json([
            'success' => true,
            'data' => [
                'challenge_id' => $issued['challenge_id'],
                'image' => $issued['image_svg'],
                'expires_in' => $issued['expires_in'],
            ],
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate, private');
    }
}
