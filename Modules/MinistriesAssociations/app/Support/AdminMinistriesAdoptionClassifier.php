<?php

namespace Modules\MinistriesAssociations\Support;

/**
 * Pure classifier for locked Ministries Insights adoption states.
 *
 * @phpstan-type TenantMetricRow array{
 *   tenant_id: int,
 *   module_enabled: bool,
 *   organizations_count: int,
 *   current_events: int,
 *   prior_events: int,
 *   feature_categories_used: int
 * }
 */
final class AdminMinistriesAdoptionClassifier
{
    /**
     * @param  array{
     *   module_enabled: bool,
     *   organizations_count: int,
     *   current_events: int,
     *   prior_events: int,
     *   feature_categories_used: int
     * }  $row
     * @return array{
     *   module_status: string,
     *   not_started: bool,
     *   activated: bool,
     *   active: bool,
     *   highly_engaged: bool,
     *   inactive: bool,
     *   declining: bool,
     *   primary_adoption_status: string
     * }
     */
    public static function classify(array $row): array
    {
        $enabled = (bool) $row['module_enabled'];
        $orgCount = (int) $row['organizations_count'];
        $current = (int) $row['current_events'];
        $prior = (int) $row['prior_events'];
        $categories = (int) $row['feature_categories_used'];

        if (! $enabled) {
            return [
                'module_status' => 'disabled',
                'not_started' => false,
                'activated' => false,
                'active' => false,
                'highly_engaged' => false,
                'inactive' => false,
                'declining' => false,
                'primary_adoption_status' => 'disabled',
            ];
        }

        $activated = $orgCount >= 1;
        $notStarted = ! $activated;
        $active = $activated && $current >= 1;
        $highlyEngaged = $activated
            && $current >= AdminMinistriesDefinitions::HIGHLY_ENGAGED_MIN_EVENTS
            && $categories >= AdminMinistriesDefinitions::HIGHLY_ENGAGED_MIN_FEATURE_CATEGORIES;
        $inactive = $activated && $current === 0;
        $declining = $activated
            && $prior >= AdminMinistriesDefinitions::DECLINING_PRIOR_MIN_EVENTS
            && ($prior === 0
                ? false
                : ($current / $prior) <= AdminMinistriesDefinitions::DECLINING_RATIO_MAX);

        $primary = 'not_started';
        if ($activated) {
            if ($highlyEngaged) {
                $primary = 'highly_engaged';
            } elseif ($declining && $inactive) {
                $primary = 'declining';
            } elseif ($declining && $active) {
                $primary = 'declining';
            } elseif ($active) {
                $primary = 'active';
            } elseif ($inactive) {
                $primary = 'inactive';
            } else {
                $primary = 'activated';
            }
        }

        return [
            'module_status' => 'enabled',
            'not_started' => $notStarted,
            'activated' => $activated,
            'active' => $active,
            'highly_engaged' => $highlyEngaged,
            'inactive' => $inactive,
            'declining' => $declining,
            'primary_adoption_status' => $primary,
        ];
    }

    /**
     * Map an audit event to a feature category key, or null if uncategorized.
     */
    public static function featureCategoryForEvent(string $event): ?string
    {
        foreach (AdminMinistriesDefinitions::FEATURE_EVENT_MAP as $category => $events) {
            if (in_array($event, $events, true)) {
                return $category;
            }
        }

        return null;
    }
}
