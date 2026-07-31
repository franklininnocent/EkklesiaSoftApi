<?php

namespace Modules\Donations\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Donations\Models\DonationReportExport;
use Modules\Donations\Services\DonationReportService;

class ProcessDonationExportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(private readonly string $exportId)
    {
    }

    public function handle(DonationReportService $reportService): void
    {
        $reportService->processExport($this->exportId);
    }
}
