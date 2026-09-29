<?php

namespace Modules\MassIntentions\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\MassIntentions\Http\Requests\UpdateMassIntentionSettingsRequest;
use Modules\MassIntentions\Services\MassIntentionSettingsService;
use Modules\Tenants\Support\TenantContext;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MassIntentionsSettingsController extends Controller
{
    public function __construct(
        private readonly MassIntentionSettingsService $settings,
    ) {
    }

    public function show(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->settings->getOrCreate($this->tenantId()),
        ]);
    }

    public function update(UpdateMassIntentionSettingsRequest $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Settings saved.',
            'data' => $this->settings->update($this->tenantId(), $request->validated()),
        ]);
    }

    private function tenantId(): int
    {
        $tenantId = app(TenantContext::class)->effectiveTenantId();
        if ($tenantId === null) {
            throw new HttpException(403, 'Tenant context is required.');
        }

        return (int) $tenantId;
    }
}
