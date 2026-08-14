<?php

namespace Modules\Sacraments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Modules\Sacraments\Exceptions\SacramentBusinessRuleException;
use Modules\Sacraments\Http\Requests\PreviewSacramentCertificateRequest;
use Modules\Sacraments\Http\Resources\SacramentCertificateResource;
use Modules\Sacraments\Services\Certificates\SacramentCertificateService;
use Modules\Tenants\Support\TenantContext;

class SacramentCertificateController extends Controller
{
    public function __construct(
        protected SacramentCertificateService $certificates
    ) {}

    public function index(Request $request, int $sacramentId): JsonResponse
    {
        try {
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $rows = $this->certificates->listForSacrament($sacramentId, $tenantId);

            return response()->json([
                'success' => true,
                'data' => SacramentCertificateResource::collection(collect($rows)),
            ]);
        } catch (SacramentBusinessRuleException $e) {
            return response()->json($e->toResponse(), $e->httpStatus());
        }
    }

    public function preview(PreviewSacramentCertificateRequest $request, int $sacramentId): JsonResponse
    {
        try {
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $cert = $this->certificates->preview(
                $sacramentId,
                $tenantId,
                $request->user()?->id,
                $request->validated()
            );

            return response()->json([
                'success' => true,
                'data' => new SacramentCertificateResource($cert),
                'message' => 'Certificate preview created',
            ], 201);
        } catch (SacramentBusinessRuleException $e) {
            return response()->json($e->toResponse(), $e->httpStatus());
        } catch (\Throwable $e) {
            Log::error('Certificate preview failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to preview certificate',
            ], 500);
        }
    }

    public function generate(PreviewSacramentCertificateRequest $request, int $sacramentId): JsonResponse
    {
        try {
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $cert = $this->certificates->generate(
                $sacramentId,
                $tenantId,
                $request->user()?->id,
                $request->validated()
            );

            return response()->json([
                'success' => true,
                'data' => new SacramentCertificateResource($cert),
                'message' => 'Certificate generated',
            ], 201);
        } catch (SacramentBusinessRuleException $e) {
            return response()->json($e->toResponse(), $e->httpStatus());
        } catch (\Throwable $e) {
            Log::error('Certificate generate failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to generate certificate',
            ], 500);
        }
    }

    public function reissue(PreviewSacramentCertificateRequest $request, int $certificateId): JsonResponse
    {
        try {
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $cert = $this->certificates->reissue(
                $certificateId,
                $tenantId,
                $request->user()?->id,
                $request->validated()
            );

            return response()->json([
                'success' => true,
                'data' => new SacramentCertificateResource($cert),
                'message' => 'Certificate reissued',
            ], 201);
        } catch (SacramentBusinessRuleException $e) {
            return response()->json($e->toResponse(), $e->httpStatus());
        } catch (\Throwable $e) {
            Log::error('Certificate reissue failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to reissue certificate',
            ], 500);
        }
    }

    public function download(Request $request, int $certificateId): Response|JsonResponse
    {
        try {
            $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
            $result = $this->certificates->download($certificateId, $tenantId);

            return response($result['bytes'], 200, [
                'Content-Type' => $result['certificate']->mime_type ?: 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$result['filename'].'"',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        } catch (SacramentBusinessRuleException $e) {
            return response()->json($e->toResponse(), $e->httpStatus());
        } catch (\Throwable $e) {
            Log::error('Certificate download failed', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to download certificate',
            ], 500);
        }
    }
}
