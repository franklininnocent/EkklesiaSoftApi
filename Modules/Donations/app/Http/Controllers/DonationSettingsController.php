<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Http\Requests\UpdateDonationSettingsRequest;
use Modules\Donations\Models\DonationSetting;
use Modules\Donations\Services\DonationAuditService;

class DonationSettingsController extends Controller
{
    public function __construct(private readonly DonationAuditService $auditService)
    {
    }

    public function show(): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;
        $settings = DonationSetting::forTenant($tenantId)->first();

        return response()->json([
            'success' => true,
            'data' => $settings,
        ]);
    }

    public function update(UpdateDonationSettingsRequest $request): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;
        $userId = (int) Auth::id();
        $payload = $request->validated();

        $settings = DonationSetting::forTenant($tenantId)->first();
        $oldValues = $settings?->toArray();

        if ($settings) {
            $payload['updated_by'] = $userId;
            $settings->update($payload);
        } else {
            $payload['tenant_id'] = $tenantId;
            $payload['created_by'] = $userId;
            $payload['updated_by'] = $userId;
            $settings = DonationSetting::create($payload);
        }

        $this->auditService->log($tenantId, 'settings.updated', 'donation_settings', $settings->id, $oldValues, $settings->toArray());

        return response()->json([
            'success' => true,
            'message' => 'Donation settings saved.',
            'data' => $settings,
        ]);
    }
}
