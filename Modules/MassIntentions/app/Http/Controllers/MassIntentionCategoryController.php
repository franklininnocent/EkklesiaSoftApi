<?php

namespace Modules\MassIntentions\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Http\Requests\StoreMassIntentionCategoryRequest;
use Modules\MassIntentions\Services\MassIntentionCategoryService;
use Modules\Tenants\Support\TenantContext;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MassIntentionCategoryController extends Controller
{
    public function __construct(
        private readonly MassIntentionCategoryService $categories,
    ) {
    }

    public function index(): JsonResponse
    {
        $tenantId = $this->tenantId();
        $actor = Auth::user();

        return response()->json([
            'success' => true,
            'data' => $this->categories->listActive($tenantId, $actor instanceof User ? $actor->id : null),
        ]);
    }

    public function store(StoreMassIntentionCategoryRequest $request): JsonResponse
    {
        $created = $this->categories->create(
            $this->tenantId(),
            $this->actor(),
            (string) $request->validated('name'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Intention type saved.',
            'data' => $this->categories->toArray($created),
        ], 201);
    }

    private function tenantId(): int
    {
        $tenantId = app(TenantContext::class)->effectiveTenantId();
        if ($tenantId === null) {
            throw new HttpException(403, 'Tenant context required.');
        }

        return (int) $tenantId;
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
