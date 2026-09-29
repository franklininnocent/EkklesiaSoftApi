<?php

namespace Modules\MassIntentions\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\MassIntentions\Services\MassIntentionDonationsLedgerBridgeService;
use Modules\MassIntentions\Services\MassIntentionRegisterService;
use Modules\Tenants\Support\TenantContext;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MassIntentionsReportsController extends Controller
{
    public function __construct(
        private readonly MassIntentionRegisterService $register,
        private readonly MassIntentionDonationsLedgerBridgeService $ledgerBridge,
    ) {
    }

    public function canonicalRegister(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->register->canonicalRegister($this->tenantId()),
        ]);
    }

    public function massList(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->register->massListReport($this->tenantId()),
        ]);
    }

    public function stillToSay(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->register->stillToSayReport($this->tenantId()),
        ]);
    }

    public function offerings(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->register->offeringsReport($this->tenantId()),
        ]);
    }

    public function massesSaid(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->register->massesSaidReport($this->tenantId()),
        ]);
    }

    public function donationsLedgerBridge(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->ledgerBridge->exportRows($this->tenantId()),
            'meta' => [
                'format' => 'mass_intentions_ledger_bridge_v1',
                'note' => 'For future Donations ledger import. Amounts remain authoritative in mass_intention_offering_receipts until port completes.',
            ],
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
}
