<?php

namespace Modules\ApplicationAccess\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\ApplicationAccess\Http\Requests\ListApplicationSecuritySignalsRequest;
use Modules\ApplicationAccess\Http\Resources\ApplicationSecuritySignalResource;
use Modules\ApplicationAccess\Services\ApplicationAccessSecurityQueryService;

class ApplicationAccessSignalController extends Controller
{
    public function __construct(
        private readonly ApplicationAccessSecurityQueryService $security,
    ) {}

    public function index(ListApplicationSecuritySignalsRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $paginator = $this->security->paginateSignals(
            $validated,
            $request->resolvedPage($validated),
            $request->resolvedPerPage($validated),
        );

        return response()->json([
            'success' => true,
            'data' => ApplicationSecuritySignalResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
