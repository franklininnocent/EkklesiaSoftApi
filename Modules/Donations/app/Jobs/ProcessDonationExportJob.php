<?php

namespace Modules\Donations\Jobs;

use Modules\Donations\Models\DonationReportExport;
use Modules\Donations\Services\DonationReportService;
use Modules\Tenants\Jobs\TenantAwareJob;

class ProcessDonationExportJob extends TenantAwareJob
{
    public function __construct(
        int $tenantId,
        ?int $actorUserId,
        private readonly string $exportId,
    ) {
        parent::__construct($tenantId, $actorUserId);
        $this->onQueue((string) config('tenants.export.queue', 'tenant-exports'));
    }

    protected function handleWithTenantContext(): void
    {
        $export = DonationReportExport::query()->find($this->exportId);
        if (! $export) {
            return;
        }

        app(DonationReportService::class)->processExport($this->exportId);
    }
}
