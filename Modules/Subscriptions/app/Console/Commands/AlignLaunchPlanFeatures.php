<?php

namespace Modules\Subscriptions\Console\Commands;

use Illuminate\Console\Command;
use Modules\Subscriptions\Services\Catalog\LaunchPlanFeatureAlignmentService;
use Modules\Subscriptions\Support\SubscriptionConsoleActor;

class AlignLaunchPlanFeatures extends Command
{
    protected $signature = 'subscriptions:align-launch-plan-features
                            {--apply : Publish new ACTIVE versions for eligible plans (default is dry-run)}
                            {--force-code= : Override conflict detection for a single launch plan code}';

    protected $description = 'Align STARTER/STANDARD/PROFESSIONAL/ENTERPRISE ACTIVE versions to the verified launch matrix (dry-run by default)';

    public function handle(LaunchPlanFeatureAlignmentService $alignment): int
    {
        $apply = (bool) $this->option('apply');
        $force = $this->option('force-code');
        $forceCode = is_string($force) && $force !== '' ? strtoupper($force) : null;

        if (! $apply) {
            $this->info('Dry-run only — no database writes. Pass --apply to publish new plan versions.');
        }

        $actor = $apply ? SubscriptionConsoleActor::resolve() : null;
        $report = $alignment->run($apply, $forceCode, $actor);

        foreach ($report['plans'] as $row) {
            $this->line('');
            $this->info("{$row['code']}: {$row['classification']} — {$row['reason']}");
            if (! isset($row['current'])) {
                continue;
            }

            $this->line('  Current features: '.implode(', ', $row['current']['features']));
            $this->line('  Intended features: '.implode(', ', $row['intended']['features']));
            $this->line('  PEOPLE_LIMIT current/intended: '
                .($row['current']['limits']['PEOPLE_LIMIT'] ?? '∅').' → '
                .($row['intended']['limits']['PEOPLE_LIMIT'] ?? '∅'));
            $this->line('  STORAGE_MB current/intended: '
                .($row['current']['limits']['STORAGE_MB'] ?? '∅').' → '
                .($row['intended']['limits']['STORAGE_MB'] ?? '∅'));
            $this->line("  Pinned on ACTIVE: {$row['pinned_current_subscriptions']}; any version: {$row['pinned_any_version_subscriptions']}");
            $this->line("  Active entitlement overrides (churches on this plan): {$row['active_entitlement_overrides']}");
            $this->line('  Pricing (monthly/annual/trial): '
                .($row['pricing']['monthly_price'] ?? '∅').' / '
                .($row['pricing']['annual_price'] ?? '∅').' / '
                .($row['pricing']['trial_days'] ?? '∅'));
            if ($row['pricing_differs_from_install_seed'] ?? false) {
                $this->warn('  Pricing differs from install seed (report only — alignment will not change prices).');
            }

            if ($row['pinned_current_subscriptions'] === 0 && $row['pinned_any_version_subscriptions'] === 0) {
                $this->comment('  No churches are pinned. Publishing updates the catalog for future assignments only.');
            }
        }

        if ($apply) {
            if ($report['applied'] === []) {
                $this->warn('No plans were published.');
            } else {
                $this->info('Published new versions for: '.implode(', ', $report['applied']));
            }
        }

        return self::SUCCESS;
    }
}
