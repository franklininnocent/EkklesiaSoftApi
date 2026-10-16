<?php

namespace Modules\MassIntentions\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Http\Requests\MassScheduleApplyRequest;
use Modules\MassIntentions\Http\Requests\MassSchedulePreviewRequest;
use Modules\MassIntentions\Http\Requests\PutMassScheduleDraftRequest;
use Modules\MassIntentions\Http\Requests\StoreMassTemporaryScheduleRequest;
use Modules\MassIntentions\Models\MassGenerationCursor;
use Modules\MassIntentions\Services\MassGenerationHealthService;
use Modules\MassIntentions\Services\MassScheduleService;
use Modules\MassIntentions\Support\MassScheduleConstants;
use Modules\Tenants\Support\TenantContext;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MassScheduleController extends Controller
{
    public function __construct(
        private readonly MassScheduleService $schedules,
        private readonly MassGenerationHealthService $generationHealth,
    ) {
    }

    public function showRegular(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->schedules->regularBundle($this->tenantId(), $this->actor()),
        ]);
    }

    public function indexTemporaries(Request $request): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->schedules->listTemporaries(
                $this->tenantId(),
                $request->boolean('include_inactive')
            ),
        ]);
    }

    public function storeTemporary(StoreMassTemporaryScheduleRequest $request): JsonResponse
    {
        $data = $this->schedules->createTemporary(
            $this->tenantId(),
            $this->actor(),
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'message' => 'Temporary schedule created.',
            'data' => $data,
        ], 201);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->schedules->scheduleBundle($this->tenantId(), $id),
        ]);
    }

    public function putDraft(PutMassScheduleDraftRequest $request, string $id): JsonResponse
    {
        $draft = $this->schedules->saveDraft(
            $this->tenantId(),
            $this->actor(),
            $id,
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'message' => 'Draft saved.',
            'data' => $draft,
        ]);
    }

    public function preview(MassSchedulePreviewRequest $request, string $id): JsonResponse
    {
        $validated = $request->validated();

        try {
            $data = $this->schedules->preview(
                $this->tenantId(),
                $id,
                $validated['apply_from'],
                $validated['until'] ?? null,
                $validated['effective_to'] ?? null
            );
        } catch (HttpException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function apply(MassScheduleApplyRequest $request, string $id): JsonResponse
    {
        $validated = $request->validated();

        try {
            $data = $this->schedules->apply(
                $this->tenantId(),
                $this->actor(),
                $id,
                $validated['apply_from'],
                $validated['fingerprint'],
                $validated['until'] ?? null,
                $validated['change_reason'] ?? null,
                $validated['effective_to'] ?? null
            );
        } catch (HttpException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }

        return response()->json([
            'success' => true,
            'message' => 'Schedule applied.',
            'data' => $data,
        ]);
    }

    public function indexRevisions(string $id): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->schedules->listRevisions($this->tenantId(), $id),
        ]);
    }

    public function inactivate(string $id): JsonResponse
    {
        $data = $this->schedules->inactivate($this->tenantId(), $this->actor(), $id);

        return response()->json([
            'success' => true,
            'message' => 'Schedule inactivated.',
            'data' => $data,
        ]);
    }

    public function archive(string $id): JsonResponse
    {
        $data = $this->schedules->archive($this->tenantId(), $this->actor(), $id);

        return response()->json([
            'success' => true,
            'message' => 'Schedule archived.',
            'data' => $data,
        ]);
    }

    public function generationStatus(): JsonResponse
    {
        $tenantId = $this->tenantId();
        $cursor = MassGenerationCursor::query()->find($tenantId);
        $health = $this->generationHealth->assessForTenant($tenantId);

        return response()->json([
            'success' => true,
            'data' => [
                'last_generated_through' => $cursor?->last_generated_through?->format('Y-m-d'),
                'last_success_at' => $cursor?->last_success_at?->toIso8601String(),
                'last_error' => $cursor?->last_error,
                'horizon_days' => MassScheduleConstants::GENERATION_HORIZON_DAYS,
                'attention_required' => $health['attention_required'],
                'attention_reason' => $health['attention_reason'],
                'minimum_through_date' => $health['minimum_through_date'],
            ],
        ]);
    }

    private function tenantId(): int
    {
        $id = app(TenantContext::class)->effectiveTenantId();
        if ($id === null) {
            throw new HttpException(403, 'Tenant context required.');
        }

        return (int) $id;
    }

    private function actor(): User
    {
        $user = Auth::user();
        if (! $user instanceof User) {
            throw new HttpException(401, 'Unauthenticated.');
        }

        return $user;
    }
}
