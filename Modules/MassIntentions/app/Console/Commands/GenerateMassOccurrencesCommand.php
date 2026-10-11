<?php

namespace Modules\MassIntentions\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\MassIntentions\Models\MassGenerationCursor;
use Modules\MassIntentions\Models\MassSchedule;
use Modules\MassIntentions\Services\MassOccurrenceReconciler;
use Modules\MassIntentions\Support\MassScheduleConstants;
use Modules\Tenants\Models\Tenant;

class GenerateMassOccurrencesCommand extends Command
{
    protected $signature = 'mass-intentions:generate-occurrences {--tenant= : Limit to one tenant id}';

    protected $description = 'Materialize regular Mass occurrences through the generation horizon';

    public function handle(MassOccurrenceReconciler $reconciler): int
    {
        $tenantOption = $this->option('tenant');

        $query = Tenant::query();
        if ($tenantOption !== null && $tenantOption !== '') {
            $query->where('id', (int) $tenantOption);
        }

        $tenants = $query->get();
        $processed = 0;

        foreach ($tenants as $tenant) {
            if (! is_array($tenant->features) || ! in_array('mass_intentions', $tenant->features, true)) {
                continue;
            }

            $hasRegular = MassSchedule::query()
                ->where('tenant_id', $tenant->id)
                ->where('kind', 'regular')
                ->where('status', 'active')
                ->exists();

            if (! $hasRegular) {
                continue;
            }

            $today = Carbon::parse(DonationBusinessDate::today((int) $tenant->id))->startOfDay();
            $from = $today->copy();
            $to = $today->copy()->addDays(MassScheduleConstants::GENERATION_HORIZON_DAYS);

            try {
                $counts = $reconciler->materialize((int) $tenant->id, $from, $to);
                MassGenerationCursor::query()->updateOrCreate(
                    ['tenant_id' => $tenant->id],
                    [
                        'last_generated_through' => $to->toDateString(),
                        'last_success_at' => now(),
                        'last_error' => null,
                    ]
                );
                $processed++;
                $this->line("Tenant {$tenant->id}: create={$counts['create']} update={$counts['update']}");
            } catch (\Throwable $e) {
                MassGenerationCursor::query()->updateOrCreate(
                    ['tenant_id' => $tenant->id],
                    ['last_error' => $e->getMessage()]
                );
                $this->error("Tenant {$tenant->id}: {$e->getMessage()}");
            }
        }

        $this->info("Processed {$processed} tenant(s).");

        return self::SUCCESS;
    }
}
