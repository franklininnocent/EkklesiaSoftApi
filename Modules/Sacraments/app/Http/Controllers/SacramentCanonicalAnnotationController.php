<?php

namespace Modules\Sacraments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Sacraments\Exceptions\SacramentBusinessRuleException;
use Modules\Sacraments\Http\Requests\StoreSacramentCanonicalAnnotationRequest;
use Modules\Sacraments\Http\Resources\SacramentCanonicalAnnotationResource;
use Modules\Sacraments\Services\SacramentCanonicalAnnotationService;
use Modules\Tenants\Support\TenantContext;

class SacramentCanonicalAnnotationController extends Controller
{
    public function __construct(
        protected SacramentCanonicalAnnotationService $annotations
    ) {}

    public function index(int $sacramentId): JsonResponse
    {
        try {
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $rows = $this->annotations->listForSacrament($sacramentId, $tenantId);

            return response()->json([
                'success' => true,
                'data' => SacramentCanonicalAnnotationResource::collection(collect($rows)),
            ]);
        } catch (SacramentBusinessRuleException $e) {
            return response()->json($e->toResponse(), $e->httpStatus());
        }
    }

    public function store(StoreSacramentCanonicalAnnotationRequest $request, int $sacramentId): JsonResponse
    {
        try {
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $row = $this->annotations->create(
                $sacramentId,
                $tenantId,
                $request->user()?->id,
                $request->validated()
            );

            return response()->json([
                'success' => true,
                'data' => new SacramentCanonicalAnnotationResource($row),
                'message' => 'Annotation recorded',
            ], 201);
        } catch (SacramentBusinessRuleException $e) {
            return response()->json($e->toResponse(), $e->httpStatus());
        }
    }

    public function destroy(int $sacramentId, int $annotationId): JsonResponse
    {
        try {
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $this->annotations->delete($sacramentId, $annotationId, $tenantId);

            return response()->json([
                'success' => true,
                'message' => 'Annotation removed',
            ]);
        } catch (SacramentBusinessRuleException $e) {
            return response()->json($e->toResponse(), $e->httpStatus());
        }
    }
}
