<?php

namespace Modules\MassIntentions\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Http\Requests\MassDayOverrideApplyRequest;
use Modules\MassIntentions\Http\Requests\StoreMassDayOverrideRequest;
use Modules\MassIntentions\Services\MassDayOverrideService;
use Modules\Tenants\Support\TenantContext;

class MassDayOverrideController extends Controller
{
    public function __construct(
        private readonly MassDayOverrideService $overrides,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', '');
        if ($from === '' || $to === '') {
            return response()->json([
                'success' => false,
                'message' => 'from and to query parameters are required.',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $this->overrides->listForRange($this->tenantId(), $from, $to),
        ]);
    }

    public function show(string $id): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->overrides->show($this->tenantId(), $id),
        ]);
    }

    public function store(StoreMassDayOverrideRequest $request): JsonResponse
    {
        $data = $this->overrides->save(
            $this->tenantId(),
            $this->actor(),
            $request->validated()
        );

        return response()->json([
            'success' => true,
            'message' => 'Special schedule saved.',
            'data' => $data,
        ], 201);
    }

    public function preview(string $id): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->overrides->preview($this->tenantId(), $id),
        ]);
    }

    public function apply(MassDayOverrideApplyRequest $request, string $id): JsonResponse
    {
        $data = $this->overrides->apply(
            $this->tenantId(),
            $this->actor(),
            $id,
            (string) $request->validated('fingerprint')
        );

        return response()->json([
            'success' => true,
            'message' => 'Special schedule applied.',
            'data' => $data,
        ]);
    }

    public function inactivate(string $id): JsonResponse
    {
        $data = $this->overrides->inactivate($this->tenantId(), $this->actor(), $id);

        return response()->json([
            'success' => true,
            'message' => 'Special schedule removed.',
            'data' => $data,
        ]);
    }

    private function tenantId(): int
    {
        return (int) app(TenantContext::class)->effectiveTenantId();
    }

    private function actor(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
