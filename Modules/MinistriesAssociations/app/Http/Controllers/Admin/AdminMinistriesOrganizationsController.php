<?php

namespace Modules\MinistriesAssociations\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\MinistriesAssociations\Services\Admin\AdminMinistriesOrganizationsService;

class AdminMinistriesOrganizationsController extends Controller
{
    public function __construct(
        private readonly AdminMinistriesOrganizationsService $organizations,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:120'],
            'tenant_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'nullable', 'in:active,inactive'],
            'type_id' => ['sometimes', 'nullable', 'uuid'],
            'category_id' => ['sometimes', 'nullable', 'uuid'],
            'health' => ['sometimes', 'nullable', 'in:ok,attention,critical,without_members,without_leadership,stale'],
            'sort' => ['sometimes', 'nullable', 'string', 'max:60'],
            'direction' => ['sometimes', 'nullable', 'in:asc,desc'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $payload = $this->organizations->list($filters);

        return response()->json([
            'success' => true,
            'data' => $payload['data'],
            'meta' => $payload['meta'],
        ]);
    }
}
