<?php

namespace Modules\Sacraments\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Modules\Sacraments\Exceptions\SacramentBusinessRuleException;
use Modules\Sacraments\Http\Requests\UpdateTenantSacramentSettingRequest;
use Modules\Sacraments\Services\TenantSacramentSettingsService;
use Modules\Tenants\Support\TenantContext;

class TenantSacramentSettingsController extends Controller
{
    public function __construct(
        protected TenantSacramentSettingsService $settings
    ) {}

    public function index(Request $request): JsonResponse
    {
        if ($error = $this->verifyTenantUser($request)) {
            return $error;
        }

        if ($error = $this->authorizeSettings($request, manage: false)) {
            return $error;
        }

        try {
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $rows = $this->settings->listForTenant($tenantId, $request->user()?->id);

            return response()->json([
                'success' => true,
                'data' => $rows,
                'message' => 'Sacrament settings retrieved successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Error listing tenant sacrament settings', [
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve sacrament settings',
            ], 500);
        }
    }

    public function update(UpdateTenantSacramentSettingRequest $request, int $sacramentType): JsonResponse
    {
        if ($error = $this->verifyTenantUser($request)) {
            return $error;
        }

        if ($error = $this->authorizeSettings($request, manage: true)) {
            return $error;
        }

        try {
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $row = $this->settings->updateAvailability(
                $tenantId,
                $sacramentType,
                $request->boolean('is_active'),
                $request->user()?->id
            );

            return response()->json([
                'success' => true,
                'data' => $row,
                'message' => $row['is_active']
                    ? 'Sacrament activated for this church'
                    : 'Sacrament deactivated for this church',
            ]);
        } catch (SacramentBusinessRuleException $e) {
            return response()->json($e->toResponse(), $e->httpStatus());
        } catch (\Exception $e) {
            Log::error('Error updating tenant sacrament setting', [
                'sacrament_type_id' => $sacramentType,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update sacrament settings',
            ], 500);
        }
    }

    private function verifyTenantUser(Request $request): ?JsonResponse
    {
        $user = $request->user();

        if (app(TenantContext::class)->effectiveTenantId() === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only tenant users can manage sacrament settings.',
            ], 403);
        }

        if ($user && method_exists($user, 'hasEkklesiaRole') && $user->hasEkklesiaRole()) {
            return response()->json([
                'success' => false,
                'message' => 'Ekklesia users cannot access tenant sacrament settings.',
            ], 403);
        }

        return null;
    }

    private function authorizeSettings(Request $request, bool $manage): ?JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient permission for this tenant action.',
            ], 403);
        }

        $isAdmin = method_exists($user, 'isTenantAdmin') && $user->isTenantAdmin();
        $canView = $isAdmin
            || (method_exists($user, 'hasPermission') && (
                $user->hasPermission('sacraments.settings.view')
                || $user->hasPermission('sacraments.settings.manage')
            ));
        $canManage = $isAdmin
            || (method_exists($user, 'hasPermission') && $user->hasPermission('sacraments.settings.manage'));

        if ($manage ? ! $canManage : ! $canView) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient permission for this tenant action.',
            ], 403);
        }

        return null;
    }
}
