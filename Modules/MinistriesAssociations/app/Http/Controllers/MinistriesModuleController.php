<?php

namespace Modules\MinistriesAssociations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class MinistriesModuleController extends Controller
{
    use AuthorizesRequests;

    private const FEATURE_KEY = 'ministries_associations';

    public function moduleStatus(): JsonResponse
    {
        $this->authorize('ministries.viewModuleStatus');

        $user = Auth::user();
        if (! $user || ! $user->tenant) {
            return response()->json([
                'success' => false,
                'message' => 'Tenant context is required.',
                'errors' => [],
            ], 403);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'enabled' => $user->tenant->supportsMinistriesAssociations(),
                'feature_key' => self::FEATURE_KEY,
            ],
        ]);
    }
}
