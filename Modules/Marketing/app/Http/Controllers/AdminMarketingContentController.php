<?php

namespace Modules\Marketing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use InvalidArgumentException;
use Modules\Marketing\Http\Requests\PublishMarketingContentRequest;
use Modules\Marketing\Models\MarketingContentRevision;
use Modules\Marketing\Services\MarketingContentService;

class AdminMarketingContentController extends Controller
{
    public function indexRevisions(): JsonResponse
    {
        $rows = MarketingContentRevision::query()
            ->orderByDesc('id')
            ->limit(40)
            ->get(['id', 'schema_version', 'published_at', 'published_by', 'notes', 'created_at'])
            ->map(static fn (MarketingContentRevision $row) => [
                'id' => $row->id,
                'schema_version' => $row->schema_version,
                'status' => $row->published_at === null ? 'draft' : 'published',
                'published_at' => $row->published_at?->toIso8601String(),
                'published_by' => $row->published_by,
                'notes' => $row->notes,
                'created_at' => $row->created_at?->toIso8601String(),
            ]);

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => $rows,
        ]);
    }

    public function showLatestDraft(MarketingContentService $marketingContent): JsonResponse
    {
        $draft = $marketingContent->getLatestDraft();

        if ($draft === null) {
            return response()->json([
                'success' => false,
                'message' => 'No draft found.',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => [
                'revision_id' => $draft->id,
                'notes' => $draft->notes,
                'payload' => $draft->payload,
                'created_at' => $draft->created_at?->toIso8601String(),
            ],
        ]);
    }

    public function storeDraft(
        PublishMarketingContentRequest $request,
        MarketingContentService $marketingContent,
    ): JsonResponse {
        try {
            $revision = $marketingContent->saveDraft(
                $request->validated('payload'),
                $request->user(),
                $request->validated('notes'),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => ['payload' => [$e->getMessage()]],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Draft saved.',
            'data' => [
                'revision_id' => $revision->id,
                'created_at' => $revision->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function publishDraftRevision(
        int $revision,
        MarketingContentService $marketingContent,
    ): JsonResponse {
        try {
            $published = $marketingContent->publishDraftRevision($revision, request()->user());
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Draft published.',
            'data' => [
                'revision_id' => $published->id,
                'published_at' => $published->published_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function showPublished(MarketingContentService $marketingContent): JsonResponse
    {
        $revision = $marketingContent->getPublishedRevision();

        if ($revision === null) {
            return response()->json([
                'success' => false,
                'message' => 'No published marketing content.',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'OK',
            'data' => [
                'revision_id' => $revision->id,
                'schema_version' => $revision->schema_version,
                'published_at' => $revision->published_at?->toIso8601String(),
                'payload' => $revision->payload,
            ],
        ]);
    }

    public function publish(
        PublishMarketingContentRequest $request,
        MarketingContentService $marketingContent,
    ): JsonResponse {
        try {
            $revision = $marketingContent->publish(
                $request->validated('payload'),
                $request->user(),
                $request->validated('notes'),
            );
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'errors' => ['payload' => [$e->getMessage()]],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Marketing content published.',
            'data' => [
                'revision_id' => $revision->id,
                'published_at' => $revision->published_at?->toIso8601String(),
            ],
        ], 201);
    }
}
