<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Services\FinancialAiQueryService;
use Modules\Donations\Services\FinancialLlmAdapterService;
use Modules\Donations\Services\WhatsAppBusinessApiService;

class FinancialAiController extends Controller
{
    public function __construct(
        private readonly FinancialAiQueryService $aiQueryService,
        private readonly FinancialLlmAdapterService $llmAdapter,
        private readonly WhatsAppBusinessApiService $whatsAppBusinessApiService
    ) {
    }

    public function ask(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'prompt' => ['required', 'string', 'max:500'],
        ]);

        $user = Auth::user();
        $result = $this->aiQueryService->process(
            (int) $user->tenant_id,
            $validated['prompt'],
            $user->role?->name ?? $user->role_name ?? null
        );

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
    }

    public function status(): JsonResponse
    {
        $tenantId = (int) Auth::user()->tenant_id;
        $globalEnabled = (bool) config('financial_ai.llm.enabled', false);
        $tenantEnabled = $this->llmAdapter->isEnabledForTenant($tenantId);

        return response()->json([
            'success' => true,
            'data' => [
                'global_llm_enabled' => $globalEnabled,
                'tenant_llm_enabled' => $tenantEnabled,
                'llm_active' => $globalEnabled && $tenantEnabled,
                'default_engine' => $globalEnabled && $tenantEnabled ? 'semantic_v2+llm_v1' : 'semantic_v2',
                'whatsapp_business_enabled' => $this->whatsAppBusinessApiService->isEnabled(),
            ],
        ]);
    }
}
