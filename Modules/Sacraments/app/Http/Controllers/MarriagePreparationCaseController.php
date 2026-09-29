<?php

namespace Modules\Sacraments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Sacraments\Exceptions\SacramentBusinessRuleException;
use Modules\Sacraments\Http\Requests\StoreMarriagePreparationCaseRequest;
use Modules\Sacraments\Http\Requests\UpdateMarriagePreparationCaseRequest;
use Modules\Sacraments\Http\Resources\MarriagePreparationCaseResource;
use Modules\Sacraments\Services\MarriagePreparationCaseService;
use Modules\Tenants\Support\TenantContext;

class MarriagePreparationCaseController extends Controller
{
    public function __construct(
        protected MarriagePreparationCaseService $cases
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bcc_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'string', 'max:20'],
        ]);

        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $rows = $this->cases->list(
            $tenantId,
            $validated['bcc_id'] ?? null,
            $validated['status'] ?? null
        );

        return response()->json([
            'success' => true,
            'data' => MarriagePreparationCaseResource::collection($rows),
        ]);
    }

    public function store(StoreMarriagePreparationCaseRequest $request): JsonResponse
    {
        try {
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $case = $this->cases->create(
                $tenantId,
                $request->user()?->id,
                $request->validated()
            );

            return response()->json([
                'success' => true,
                'data' => new MarriagePreparationCaseResource(
                    $case->load(['brideFamilyMember:id,first_name,last_name', 'groomFamilyMember:id,first_name,last_name'])
                ),
                'message' => 'Marriage preparation case opened',
            ], 201);
        } catch (SacramentBusinessRuleException $e) {
            return response()->json($e->toResponse(), $e->httpStatus());
        }
    }

    public function update(UpdateMarriagePreparationCaseRequest $request, int $id): JsonResponse
    {
        try {
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $case = $this->cases->update(
                $tenantId,
                $id,
                $request->user()?->id,
                $request->validated()
            );

            return response()->json([
                'success' => true,
                'data' => new MarriagePreparationCaseResource($case),
                'message' => 'Marriage preparation case updated',
            ]);
        } catch (SacramentBusinessRuleException $e) {
            return response()->json($e->toResponse(), $e->httpStatus());
        }
    }
}
