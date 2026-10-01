<?php

namespace Modules\MassIntentions\Http\Controllers;

use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\MassIntentions\Support\MassScheduleConstants;
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

    public function massList(Request $request): JsonResponse
    {
        $tenantId = $this->tenantId();
        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', '');
        if ($from === '' || $to === '') {
            throw new HttpException(422, 'from and to dates are required.');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            throw new HttpException(422, 'from and to must be YYYY-MM-DD.');
        }
        $days = Carbon::parse($from)->diffInDays(Carbon::parse($to));
        if ($days > MassScheduleConstants::MAX_LIST_RANGE_DAYS) {
            throw new HttpException(422, 'Date range cannot exceed '.MassScheduleConstants::MAX_LIST_RANGE_DAYS.' days.');
        }

        return response()->json([
            'success' => true,
            'data' => $this->register->massListReport($tenantId, $from, $to),
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
