<?php

namespace Modules\MassIntentions\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Http\Requests\UndoMassFulfilmentRequest;
use Modules\MassIntentions\Services\MassIntentionFulfilmentService;
use Modules\Tenants\Support\TenantContext;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MassIntentionFulfilmentController extends Controller
{
    public function __construct(
        private readonly MassIntentionFulfilmentService $fulfilment,
    ) {
    }

    public function undo(UndoMassFulfilmentRequest $request, string $id): JsonResponse
    {
        $this->fulfilment->undo($this->tenantId(), $this->actor(), $id, $request->validated('reason'));

        return response()->json([
            'success' => true,
            'message' => 'Said mark removed.',
        ]);
    }

    private function tenantId(): int
    {
        $tenantId = app(TenantContext::class)->effectiveTenantId();
        if ($tenantId === null) {
            throw new HttpException(403, 'Tenant context is required.');
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
