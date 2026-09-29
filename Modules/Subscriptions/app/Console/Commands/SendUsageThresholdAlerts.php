<?php

namespace Modules\Subscriptions\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Notifications\Contracts\NotificationPublisherContract;
use Modules\Notifications\Support\InboxScope;
use Modules\Notifications\Support\NotificationIntent;
use Modules\Subscriptions\Services\UsageService;
use Throwable;

/**
 * Tells church administrators when usage crosses the policy thresholds, from the latest nightly
 * snapshot. Each (church, limit, level) is announced at most once per 30 days.
 */
class SendUsageThresholdAlerts extends Command
{
    public const DEFINITION = 'subscriptions.usage.threshold';

    protected $signature = 'subscriptions:usage-alerts';

    protected $description = 'Notify church administrators when plan usage crosses warning thresholds';

    private const ALERT_LEVELS = [
        UsageService::LEVEL_WARNING => 'getting close to',
        UsageService::LEVEL_CRITICAL => 'almost at',
        UsageService::LEVEL_AT_LIMIT => 'at',
        UsageService::LEVEL_OVER_LIMIT => 'over',
    ];

    public function handle(UsageService $usage): int
    {
        $date = DB::table('tenant_usage_snapshots')->max('snapshot_date');
        if (! $date || ! app()->bound(NotificationPublisherContract::class)) {
            $this->info('No usage snapshots to evaluate.');

            return self::SUCCESS;
        }

        $sent = 0;
        DB::table('tenant_usage_snapshots as s')
            ->join('features as f', 'f.id', '=', 's.feature_id')
            ->join('tenants as t', 't.id', '=', 's.tenant_id')
            ->whereNull('t.deleted_at')
            ->where('s.snapshot_date', $date)
            ->whereNotNull('s.limit_value')
            ->orderBy('s.tenant_id')
            ->select(['s.tenant_id', 'f.code', 'f.name', 's.usage_value', 's.limit_value'])
            ->chunk(500, function ($rows) use ($usage, &$sent): void {
                foreach ($rows as $row) {
                    $level = $usage->level((int) $row->usage_value, (int) $row->limit_value);
                    if (! isset(self::ALERT_LEVELS[$level])) {
                        continue;
                    }
                    $key = "subscriptions:usage_alert:{$row->tenant_id}:{$row->code}:{$level}";
                    if (! Cache::add($key, true, now()->addDays(30))) {
                        continue;
                    }
                    if ($this->publish($row, $level)) {
                        $sent++;
                    }
                }
            });

        $this->info("Sent {$sent} usage alerts.");

        return self::SUCCESS;
    }

    private function publish(object $row, string $level): bool
    {
        try {
            app(NotificationPublisherContract::class)->publish(new NotificationIntent(
                definitionCode: self::DEFINITION,
                actor: null,
                subjectType: 'subscription_usage',
                subjectId: $row->tenant_id.':'.$row->code,
                tenantId: (int) $row->tenant_id,
                scope: InboxScope::Tenant,
                occurrenceId: 'usage-'.$row->tenant_id.'-'.$row->code.'-'.$level.'-'.now()->format('Ymd'),
                data: [
                    'limit_name' => (string) $row->name,
                    'level_phrase' => self::ALERT_LEVELS[$level],
                    'usage' => (int) $row->usage_value,
                    'limit' => (int) $row->limit_value,
                    'deep_link_route' => '/settings/my-subscription',
                ],
                collapseKey: 'subscriptions.usage.'.$row->tenant_id.'.'.$row->code,
            ));

            return true;
        } catch (Throwable $e) {
            Log::warning('Usage threshold alert failed', ['tenant_id' => $row->tenant_id, 'code' => $row->code, 'error' => $e->getMessage()]);

            return false;
        }
    }
}
