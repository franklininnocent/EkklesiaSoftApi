<?php

namespace Modules\MinistriesAssociations\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\MinistriesAssociations\Services\Admin\AdminMinistriesHealthService;

class AdminMinistriesHealthController extends Controller
{
    public function __construct(
        private readonly AdminMinistriesHealthService $health,
    ) {
    }

    public function show(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->health->summary(),
        ]);
    }
}
