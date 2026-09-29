<?php

namespace Modules\MassIntentions\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Http\Requests\VoidMassOfferingReceiptRequest;
use Modules\MassIntentions\Services\MassIntentionOfferingService;
use Modules\Tenants\Support\TenantContext;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MassIntentionOfferingReceiptController extends Controller
{
    public function __construct(
        private readonly MassIntentionOfferingService $offerings,
    ) {
    }

    public function void(VoidMassOfferingReceiptRequest $request, string $id): JsonResponse
    {
        $this->offerings->voidReceipt(
            $this->tenantId(),
            $this->actor(),
            $id,
            $request->validated('reason')
        );

        return response()->json([
            'success' => true,
            'message' => 'Receipt voided.',
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
