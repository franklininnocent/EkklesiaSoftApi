<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Models\DonationNotificationLog;
use Modules\Donations\Services\DonationNotificationService;

class DonationNotificationsController extends Controller
{
    public function __construct(private readonly DonationNotificationService $notificationService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;
        $query = DonationNotificationLog::forTenant($tenantId)->orderByDesc('created_at');

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        return response()->json([
            'success' => true,
            'data' => $query->paginate((int) $request->input('per_page', 20)),
        ]);
    }

    public function queueReminder(Request $request): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;
        $log = $this->notificationService->queue(
            $tenantId,
            $request->input('notification_type', 'due.reminder'),
            $request->input('channel', 'in_app'),
            $request->input('recipient'),
            $request->input('payload', []),
            $request->input('target_type'),
            $request->input('target_id')
        );

        return response()->json([
            'success' => true,
            'message' => 'Reminder queued successfully.',
            'data' => $log,
        ], 201);
    }
}
