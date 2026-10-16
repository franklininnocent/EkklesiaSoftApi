<?php

namespace Modules\Authentication\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;

class PasswordRecoveryTestInboxController extends Controller
{
    public function show(string $recoveryId): JsonResponse
    {
        if (! $this->isEnabled()) {
            abort(404);
        }

        $otp = Cache::get('recovery_test_otp:'.$recoveryId);

        if (! is_string($otp) || $otp === '') {
            return response()->json([
                'success' => false,
                'message' => 'No OTP available for this recovery session.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'otp' => $otp,
            ],
        ]);
    }

    private function isEnabled(): bool
    {
        if (! in_array(config('app.env'), ['local', 'testing'], true)) {
            return false;
        }

        return (bool) config('authentication.recovery.test_inbox_enabled', false);
    }
}
