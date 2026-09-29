<?php

namespace Modules\Tenants\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Authentication\Models\User;
use Modules\Tenants\Http\Requests\ExecuteDefaultSeedsRequest;
use Modules\Tenants\Services\DefaultSeedCatalogService;
use Modules\Tenants\Services\DefaultSeedExecutionService;
use Modules\Tenants\Support\TenantContext;

class DefaultSeedsController extends Controller
{
    public function __construct(
        private readonly DefaultSeedCatalogService $catalogService,
        private readonly DefaultSeedExecutionService $executionService,
    ) {
    }

    public function index(): JsonResponse
    {
        /** @var User $user */
        $user = request()->user();
        $context = app(TenantContext::class);
        $payload = $this->catalogService->catalog($user, $context);

        return response()->json([
            'success' => true,
            'data' => $payload,
        ]);
    }

    public function store(ExecuteDefaultSeedsRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = request()->user();
        $context = app(TenantContext::class);
        $payload = $this->executionService->execute($request->validated('ids'), $user, $context);

        $hasFailure = ($payload['summary']['failed'] ?? 0) > 0;
        $allFailed = $hasFailure && ($payload['summary']['completed'] ?? 0) === 0
            && ($payload['summary']['already_initialized'] ?? 0) === 0
            && ($payload['summary']['partially_completed'] ?? 0) === 0;

        return response()->json([
            'success' => ! $allFailed,
            'message' => $this->buildMessage($payload),
            'data' => $payload,
        ], $allFailed ? 422 : 200);
    }

    /**
     * @param  array{results: list<array<string, mixed>>, summary: array<string, int>}  $payload
     */
    private function buildMessage(array $payload): string
    {
        $summary = $payload['summary'];
        $created = 0;
        foreach ($payload['results'] as $result) {
            $created += (int) ($result['created_count'] ?? 0);
        }

        if (($summary['failed'] ?? 0) > 0) {
            return 'Some lists could not be added. Review the results below.';
        }

        if ($created === 0 && ($summary['already_initialized'] ?? 0) > 0) {
            return 'Recommended lists are already in place. Nothing new was added.';
        }

        if ($created > 0) {
            return "Added {$created} recommended list".($created === 1 ? '' : 's').'. Existing parish labels were not changed.';
        }

        return 'Operation completed.';
    }
}
