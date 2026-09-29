<?php

namespace Modules\ApplicationAccess\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\ApplicationAccess\Http\Requests\ListApplicationAccessEventsRequest;
use Modules\ApplicationAccess\Http\Resources\ApplicationAccessEventResource;
use Modules\ApplicationAccess\Services\ApplicationAccessEventQueryService;

class ApplicationAccessEventController extends Controller
{
    public function __construct(
        private readonly ApplicationAccessEventQueryService $events,
    ) {}

    public function index(ListApplicationAccessEventsRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $paginator = $this->events->paginate(
            $validated,
            $request->resolvedPage($validated),
            $request->resolvedPerPage($validated),
        );

        return response()->json([
            'success' => true,
            'data' => ApplicationAccessEventResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }
}
