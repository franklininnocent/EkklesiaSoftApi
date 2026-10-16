<?php

namespace Modules\MassIntentions\Services;

use Illuminate\Support\Facades\DB;
use Modules\MassIntentions\Models\MassIntentionOfferingReceipt;

class MassIntentionReceiptNumberService
{
    public function nextForTenant(int $tenantId, int $year): string
    {
        return DB::transaction(function () use ($tenantId, $year): string {
            $count = MassIntentionOfferingReceipt::query()
                ->where('tenant_id', $tenantId)
                ->whereYear('received_on', $year)
                ->lockForUpdate()
                ->count();

            return sprintf('MO-%d-%d-%06d', $tenantId, $year, $count + 1);
        });
    }
}
