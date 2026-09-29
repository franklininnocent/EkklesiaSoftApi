<?php

namespace Modules\ApplicationAccess\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\ApplicationAccess\Http\Requests\ListApplicationSecurityEventsRequest;
use Modules\ApplicationAccess\Http\Resources\ApplicationSecurityEventResource;
use Modules\ApplicationAccess\Services\ApplicationAccessSecurityQueryService;

class ApplicationAccessSecurityEventController extends Controller
{
    public function __construct(
        private readonly ApplicationAccessSecurityQueryService $security,
    ) {}

    public function index(ListApplicationSecurityEventsRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $paginator = $this->security->paginateSecurityEvents(
            $validated,
            $request->resolvedPage($validated),
            $request->resolvedPerPage($validated),
        );

        return response()->json([
            'success' => true,
            'data' => ApplicationSecurityEventResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
