<?php

namespace Modules\Family\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Family\app\Services\PersonMatchService;
use Modules\Family\app\Services\PersonService;
use Modules\Tenants\Support\TenantContext;

class PersonController extends Controller
{
    public function __construct(
        protected PersonService $personService,
        protected PersonMatchService $matchService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $perPage = (int) $request->input('per_page', 15);

        $people = $this->personService->search($tenantId, [
            'search' => $request->input('search'),
            'unaffiliated' => $request->boolean('unaffiliated'),
        ], $perPage);

        return response()->json([
            'success' => true,
            'data' => $people->items(),
            'total' => $people->total(),
            'current_page' => $people->currentPage(),
            'last_page' => $people->lastPage(),
            'per_page' => $people->perPage(),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $person = $this->personService->resolve($id, $tenantId);
        $person->load(['activeFamilyMember.family']);

        return response()->json([
            'success' => true,
            'data' => $person,
        ]);
    }

    public function matches(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'last_name' => 'required|string|max:100',
            'date_of_birth' => 'nullable|date',
            'gender' => 'nullable|in:male,female,other',
            'father_name' => 'nullable|string|max:255',
            'mother_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255',
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->matchService->findPossibleMatches($tenantId, $validated),
        ]);
    }
}
