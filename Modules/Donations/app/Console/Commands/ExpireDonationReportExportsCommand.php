<?php

namespace Modules\Donations\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Modules\Donations\Models\DonationReportExport;

class ExpireDonationReportExportsCommand extends Command
{
    protected $signature = 'donations:expire-report-exports';

    protected $description = 'Mark expired donation report exports and remove files from disk';

    public function handle(): int
    {
        $expired = 0;
        DonationReportExport::query()
            ->where('status', 'completed')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->orderBy('id')
            ->chunkById(100, function ($exports) use (&$expired): void {
                foreach ($exports as $export) {
                    $path = (string) $export->file_path;
                    if ($path !== '' && ! str_contains($path, '..')) {
                        Storage::disk('local')->delete($path);
                    }
                    $export->status = 'expired';
                    $export->file_path = null;
                    $export->save();
                    $expired++;
                }
            });

        $this->info("Expired {$expired} report export(s).");

        return self::SUCCESS;
    }
}
