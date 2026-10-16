<?php

namespace Modules\Sacraments\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Sacraments\Services\Context\SacramentContextResolver;
use Modules\Tenants\Support\TenantContext;

class SacramentContextController extends Controller
{
    public function __construct(
        private readonly SacramentContextResolver $resolver,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'family_member_id' => 'nullable|uuid|required_without:person_id',
            'person_id' => 'nullable|uuid|required_without:family_member_id',
            'workflow' => 'required|string|max:40',
            'participant_role' => 'nullable|string|max:40',
            'sacrament_id' => 'nullable|integer',
        ]);

        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();

        $context = $this->resolver->resolve($tenantId, $validated);

        return response()->json([
            'success' => true,
            'data' => $context,
        ]);
    }
}
