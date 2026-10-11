<?php

namespace Modules\Subscriptions\Console\Commands;

use Illuminate\Console\Command;
use Modules\Subscriptions\Services\Catalog\LaunchPlanCatalogRebuildService;
use Modules\Subscriptions\Support\SubscriptionConsoleActor;

class RebuildLaunchPlanCatalog extends Command
{
    protected $signature = 'subscriptions:rebuild-launch-plan-catalog
                            {--apply : Publish fresh ACTIVE versions and optionally prune (default is dry-run)}
                            {--prune-unreferenced : Delete plan versions with no tenant subscription rows (keeps pinned history)}
                            {--migrate-tenants : Move CURRENT subscriptions to the new ACTIVE version (requires explicit approval)}';

    protected $description = 'Publish clean launch-plan versions from the approved matrix; preserve pinned tenants unless --migrate-tenants';

    public function handle(LaunchPlanCatalogRebuildService $rebuild): int
    {
        $apply = (bool) $this->option('apply');
        $prune = (bool) $this->option('prune-unreferenced');
        $migrate = (bool) $this->option('migrate-tenants');

        if (! $apply) {
            $this->info('Dry-run only — no database writes. Pass --apply to publish fresh catalog versions.');
        } elseif ($migrate) {
            $this->warn('Tenant migration is enabled: CURRENT subscriptions on rebuilt plans will move to the new ACTIVE version when impact is confirmed.');
        }

        if ($apply && $prune) {
            $this->comment('Unreferenced RETIRED/DRAFT/SCHEDULED versions will be removed after each plan is rebuilt.');
        }

        $actor = $apply ? SubscriptionConsoleActor::resolve() : null;
        $report = $rebuild->run($apply, $prune, $migrate, $actor);

        foreach ($report['plans'] as $row) {
            $this->line('');
            if ($row['classification'] === 'skip') {
                $this->warn("{$row['code']}: skipped — {$row['reason']}");

                continue;
            }

            $this->info("{$row['code']}: {$row['reason']}");
            $this->line('  ACTIVE v'.$row['active_version_number'].' (id '.$row['active_version_id'].')');
            $this->line('  Matrix aligned: '.($row['aligned_with_matrix'] ? 'yes' : 'no'));
            $this->line('  Churches pinned to ACTIVE: '.$row['pinned_current_subscriptions']);
            $this->line('  Versions referenced by subscriptions: '.$row['referenced_version_count']);
            if ($prune || ! $apply) {
                $this->line('  Versions eligible for prune (no subscription rows): '.$row['prunable_version_count']);
            }
            if ($row['pinned_current_subscriptions'] > 0 && ! $migrate) {
                $this->comment('  Pinned churches stay on their assigned version until a separate migrate is approved.');
            }
        }

        if ($apply) {
            if ($report['published'] === []) {
                $this->warn('No plans were rebuilt.');
            } else {
                $this->info('Published fresh versions for: '.implode(', ', $report['published']));
            }

            foreach ($report['pruned_version_ids'] as $code => $ids) {
                if ($ids !== []) {
                    $this->line("  {$code}: removed unreferenced version ids ".implode(', ', $ids));
                }
            }

            if ($report['migration'] !== null) {
                $this->info('Tenant migration results recorded for: '.implode(', ', array_keys($report['migration'])));
            }
        }

        return self::SUCCESS;
    }
}
