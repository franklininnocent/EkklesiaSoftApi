<?php

namespace Modules\Tenants\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Authentication\Models\User;
use Modules\BCC\Services\BccDashboardService;
use Modules\Donations\Services\DonationDashboardService;
use Modules\Family\app\Repositories\FamilyRepository;
use Modules\Family\app\Services\MemberAgeDemographicsService;
use Modules\Family\app\Services\MemberCelebrationsService;
use Modules\MassIntentions\Services\MassIntentionsDashboardService;
use Modules\MassIntentions\Services\MassNextUpcomingCelebrationService;
use Modules\MinistriesAssociations\Services\MinistriesDashboardService;
use Modules\PastoralCare\Services\PastoralCareService;
use Modules\Sacraments\Services\SacramentDashboardService;
use Modules\Sacraments\Support\SacramentPrivacyAccess;
use Modules\Tenants\Contracts\TenantEntitlementGate;
use App\Support\MoneyMath;
use Modules\Tenants\Models\Tenant;

class TenantExecutiveDashboardComposer
{
    private ?int $donationSummaryTenantId = null;

    /** @var array<string, mixed>|null */
    private ?array $donationSummaryCache = null;

    private ?int $massOperationalTenantId = null;

    /** @var array<string, mixed>|null */
    private ?array $massOperationalCache = null;

    public function __construct(
        private readonly FamilyRepository $familyRepository,
        private readonly BccDashboardService $bccDashboardService,
        private readonly DonationDashboardService $donationDashboardService,
        private readonly MinistriesDashboardService $ministriesDashboardService,
        private readonly SacramentDashboardService $sacramentDashboardService,
        private readonly SacramentPrivacyAccess $sacramentPrivacyAccess,
        private readonly MassIntentionsDashboardService $massIntentionsDashboardService,
        private readonly MassNextUpcomingCelebrationService $massNextUpcomingCelebrationService,
        private readonly MemberCelebrationsService $memberCelebrationsService,
        private readonly MemberAgeDemographicsService $memberAgeDemographicsService,
        private readonly PastoralCareService $pastoralCareService,
        private readonly TenantEntitlementGate $entitlementGate,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(User $user, int $tenantId): array
    {
        $this->donationSummaryTenantId = null;
        $this->donationSummaryCache = null;
        $this->massOperationalTenantId = null;
        $this->massOperationalCache = null;

        $tenant = Tenant::query()->find($tenantId);

        return [
            'snapshot' => $this->buildSnapshot($user, $tenantId, $tenant),
            'attention' => $this->buildAttention($user, $tenantId, $tenant),
            'stewardship' => $this->buildStewardship($user, $tenantId, $tenant),
            'mass_intentions' => $this->buildMassIntentions($user, $tenantId, $tenant),
            'worship' => $this->buildWorship($user, $tenantId, $tenant),
            'pastoral' => $this->buildPastoral($user, $tenantId),
            'celebrations' => $this->buildCelebrations($user, $tenantId),
            'quick_actions' => $this->buildQuickActions($user, $tenant),
        ];
    }

    /**
     * @return array{state: string, data?: array<string, mixed>}
     */
    private function buildSnapshot(User $user, int $tenantId, ?Tenant $tenant): array
    {
        $cards = [];

        if ($user->hasPermission('families.view')) {
            try {
                $familyStats = $this->familyRepository->getStatistics((string) $tenantId);
                $cards['families'] = [
                    'active_families' => (int) ($familyStats['active_families'] ?? 0),
                    'total_families' => (int) ($familyStats['total_families'] ?? 0),
                    'drilldown' => 'families.directory',
                ];
                $demographics = $this->memberAgeDemographicsService->forTenant($tenantId);
                $cards['members'] = [
                    'active_members' => (int) ($familyStats['active_members'] ?? 0),
                    'total_members' => (int) ($familyStats['total_members'] ?? 0),
                    'members_added_this_month' => (int) ($familyStats['members_created_this_month'] ?? 0),
                    'age_groups' => $demographics['age_groups'],
                    'drilldown' => 'members.list',
                ];
            } catch (\Throwable $e) {
                Log::error('Executive dashboard snapshot families failed', ['tenant_id' => $tenantId, 'error' => $e->getMessage()]);
                $cards['families'] = ['error' => true];
                $cards['members'] = ['error' => true];
            }
        }

        if ($user->hasPermission('bcc.view')) {
            try {
                $summary = $this->bccDashboardService->summary($tenantId);
                $snapshot = $summary['snapshot'] ?? null;
                if (! is_array($snapshot) || ! array_key_exists('bccs_active', $snapshot)) {
                    $cards['life_groups'] = ['error' => true];
                } else {
                    $cards['life_groups'] = [
                        'active_bccs' => $snapshot['bccs_active'],
                        'families_connected' => $snapshot['families_connected'] ?? null,
                        'families_without_bcc' => $snapshot['families_without_bcc'] ?? null,
                        'household_coverage_percent' => $snapshot['coverage_percent'] ?? null,
                        'drilldown' => 'bcc.home',
                    ];
                }
            } catch (\Throwable $e) {
                Log::error('Executive dashboard snapshot bcc failed', ['tenant_id' => $tenantId, 'error' => $e->getMessage()]);
                $cards['life_groups'] = ['error' => true];
            }
        }

        if ($tenant && $user->hasPermission('ministries.view') && $tenant->supportsMinistriesAssociations()) {
            try {
                $m = $this->ministriesDashboardService->summary($tenantId);
                $byCat = $m['organizations']['active_by_category'] ?? ['ministry' => 0, 'association' => 0, 'other' => 0];
                $leadership = is_array($m['leadership'] ?? null) ? $m['leadership'] : [];
                $cards['ministries'] = [
                    'active_ministries' => (int) ($byCat['ministry'] ?? 0),
                    'active_associations' => (int) ($byCat['association'] ?? 0),
                    'active_other' => (int) ($byCat['other'] ?? 0),
                    'active_groups_total' => (int) ($m['organizations']['active'] ?? 0),
                    'groups_by_type' => $m['organizations']['active_by_type'] ?? [],
                    'groups_by_category' => $m['organizations']['category_breakdown'] ?? [],
                    'active_memberships' => (int) ($m['memberships']['active'] ?? 0),
                    'vacancies' => (int) ($leadership['vacancies'] ?? 0),
                    'expiring_soon_count' => (int) ($leadership['expiring_soon_count'] ?? 0),
                    'drilldown' => 'ministries.home',
                ];
            } catch (\Throwable $e) {
                Log::error('Executive dashboard snapshot ministries failed', ['tenant_id' => $tenantId, 'error' => $e->getMessage()]);
                $cards['ministries'] = ['error' => true];
            }
        }

        if ($user->hasPermission('sacraments.view')) {
            try {
                $restricted = $this->sacramentPrivacyAccess->canViewRestricted($user)
                    ? []
                    : $this->sacramentPrivacyAccess->restrictedTypeCodes();
                $summary = $this->sacramentDashboardService->getSummary(
                    $tenantId,
                    ['minimal' => true],
                    $restricted
                );
                $types = $summary['breakdowns']['by_type_totals'] ?? [];
                usort($types, static function (array $a, array $b): int {
                    $cmp = ((int) ($b['count'] ?? 0)) <=> ((int) ($a['count'] ?? 0));
                    if ($cmp !== 0) {
                        return $cmp;
                    }

                    return strcmp((string) ($a['code'] ?? ''), (string) ($b['code'] ?? ''));
                });
                $top = array_slice($types, 0, 3);
                $remaining = max(0, count($types) - count($top));
                $cards['sacraments'] = [
                    'total_period' => $summary['kpis']['total_period'] ?? null,
                    'this_month' => $summary['kpis']['this_month'] ?? null,
                    'period_label' => (string) ($summary['period']['label'] ?? ''),
                    'top_types' => $top,
                    'more_types_count' => $remaining,
                    'drilldown' => 'sacraments.home',
                ];
                if (! is_numeric($cards['sacraments']['total_period']) || ! is_numeric($cards['sacraments']['this_month'])) {
                    $cards['sacraments'] = ['error' => true];
                }
            } catch (\Throwable $e) {
                Log::error('Executive dashboard snapshot sacraments failed', ['tenant_id' => $tenantId, 'error' => $e->getMessage()]);
                $cards['sacraments'] = ['error' => true];
            }
        }

        // Financial amounts stay on the executive financial snapshot (stewardship),
        // which reuses the Donations Dashboard snapshot. Parish cards stay non-financial.

        $hasReady = false;
        $hasError = false;
        foreach ($cards as $card) {
            if (! empty($card['error'])) {
                $hasError = true;
            } else {
                $hasReady = true;
            }
        }

        if (! $hasReady && $hasError) {
            return ['state' => 'error', 'data' => ['cards' => $cards]];
        }

        if ($cards === []) {
            return ['state' => 'forbidden'];
        }

        return ['state' => 'ready', 'data' => ['cards' => $cards]];
    }

    /**
     * @return array{state: string, data?: array<string, mixed>}
     */
    private function buildAttention(User $user, int $tenantId, ?Tenant $tenant): array
    {
        $items = [];
        $stats = null;

        if ($tenant && $user->hasPermission('donations.view') && $tenant->supportsDonations()) {
            try {
                $donation = $this->donationSummary($tenantId);
                $currency = $donation['tenant_context']['currency_code'] ?? null;
                if (is_string($currency) && $currency !== '') {
                    $count = (int) ($donation['attention_summary']['count'] ?? 0);
                    if ($count > 0) {
                        $items[] = [
                            'key' => 'overdue_families',
                            'count' => $count,
                            'drilldown' => 'donations.dues.overdue',
                        ];
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Executive dashboard attention financial failed', ['tenant_id' => $tenantId]);
            }
        }

        if ($tenant && $user->hasPermission('mass.intentions.view')
            && $tenant->supportsMassIntentions()
            && $this->entitlementGate->allows($tenant, 'MASS_INTENTIONS')) {
            try {
                $mass = $this->massIntentionsDashboardService->executiveOperationalCounts($tenantId);
                if (($mass['needs_a_mass'] ?? 0) > 0) {
                    $items[] = [
                        'key' => 'needs_a_mass',
                        'count' => (int) $mass['needs_a_mass'],
                        'drilldown' => 'mass.needs_a_mass',
                    ];
                }
                if (($mass['needs_a_tick'] ?? 0) > 0) {
                    $items[] = [
                        'key' => 'needs_a_tick',
                        'count' => (int) $mass['needs_a_tick'],
                        'drilldown' => 'mass.needs_a_tick',
                    ];
                }
                if (($mass['schedule_attention'] ?? 0) > 0) {
                    $items[] = [
                        'key' => 'schedule_attention',
                        'count' => (int) $mass['schedule_attention'],
                        'drilldown' => 'mass.schedule',
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning('Executive dashboard attention mass failed', ['tenant_id' => $tenantId]);
            }
        }

        if ($tenant && $user->hasPermission('ministries.view') && $tenant->supportsMinistriesAssociations()) {
            try {
                $m = $this->ministriesDashboardService->summary($tenantId);
                $vacancies = (int) ($m['leadership']['vacancies'] ?? 0);
                if ($vacancies > 0) {
                    $items[] = [
                        'key' => 'ministry_vacancies',
                        'count' => $vacancies,
                        'drilldown' => 'ministries.home',
                    ];
                }
                $expiring = (int) ($m['leadership']['expiring_soon_count'] ?? 0);
                if ($expiring > 0) {
                    $items[] = [
                        'key' => 'leadership_terms_expiring',
                        'count' => $expiring,
                        'drilldown' => 'ministries.home',
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning('Executive dashboard attention ministries failed', ['tenant_id' => $tenantId]);
            }
        }

        if ($user->hasPermission('families.view')) {
            try {
                $stats = $this->familyRepository->getStatistics((string) $tenantId);
                $unlinked = (int) ($stats['families_without_bcc'] ?? 0);
                if ($unlinked > 0 && $user->hasPermission('bcc.view')) {
                    $items[] = [
                        'key' => 'families_without_life_group',
                        'count' => $unlinked,
                        'drilldown' => 'families.directory',
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning('Executive dashboard attention families failed', ['tenant_id' => $tenantId]);
            }
        }

        if ($user->hasPermission('pastoral.care.view')) {
            try {
                $pastoral = $this->pastoralCareService->dashboard($tenantId);
                $open = (int) ($pastoral['open_count'] ?? 0);
                if ($open > 0) {
                    $items[] = [
                        'key' => 'pastoral_open',
                        'count' => $open,
                        'drilldown' => 'dashboard.operations',
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning('Executive dashboard attention pastoral failed', ['tenant_id' => $tenantId]);
            }
        }

        $hasAnyQueuePermission = ($tenant && $user->hasPermission('donations.view') && $tenant->supportsDonations())
            || ($tenant && $user->hasPermission('mass.intentions.view') && $tenant->supportsMassIntentions())
            || ($tenant && $user->hasPermission('ministries.view') && $tenant->supportsMinistriesAssociations())
            || $user->hasPermission('families.view')
            || $user->hasPermission('pastoral.care.view');

        if (! $hasAnyQueuePermission) {
            return ['state' => 'forbidden'];
        }

        return [
            'state' => 'ready',
            'data' => [
                'items' => $items,
                'all_clear' => $items === [],
            ],
        ];
    }

    /**
     * @return array{state: string, data?: array<string, mixed>}
     */
    private function buildStewardship(User $user, int $tenantId, ?Tenant $tenant): array
    {
        if (! $tenant || ! $user->hasPermission('donations.view') || ! $tenant->supportsDonations()) {
            return ['state' => 'forbidden'];
        }

        try {
            $donation = $this->donationSummary($tenantId);
            $currency = $donation['tenant_context']['currency_code'] ?? null;
            if (! is_string($currency) || $currency === '') {
                return ['state' => 'unavailable'];
            }

            $snapshot = $this->financialSnapshotFromSummary($donation);
            if ($snapshot === null) {
                return ['state' => 'error'];
            }

            return [
                'state' => 'ready',
                'data' => array_merge($snapshot, [
                    'currency_code' => $currency,
                    'giving_health_label' => (string) ($donation['financial_health']['label'] ?? ''),
                ]),
            ];
        } catch (\Throwable $e) {
            Log::error('Executive dashboard stewardship failed', ['tenant_id' => $tenantId, 'error' => $e->getMessage()]);

            return ['state' => 'error'];
        }
    }

    /**
     * @return array{state: string, data?: array<string, mixed>}
     */
    private function buildMassIntentions(User $user, int $tenantId, ?Tenant $tenant): array
    {
        if (! $tenant || ! $user->hasPermission('mass.intentions.view')
            || ! $tenant->supportsMassIntentions()
            || ! $this->entitlementGate->allows($tenant, 'MASS_INTENTIONS')) {
            return ['state' => 'forbidden'];
        }

        try {
            $counts = $this->massOperationalCounts($tenantId);

            return [
                'state' => 'ready',
                'data' => [
                    'open' => $counts['open'],
                    'registered_this_month' => $counts['intentions_registered_this_month'],
                    'registered_from' => $counts['registered_from'],
                    'registered_to' => $counts['registered_to'],
                    'needs_a_mass' => $counts['needs_a_mass'],
                    'needs_a_tick' => $counts['needs_a_tick'],
                    'schedule_attention' => $counts['schedule_attention'],
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('Executive dashboard mass intentions failed', ['tenant_id' => $tenantId, 'error' => $e->getMessage()]);

            return ['state' => 'error'];
        }
    }

    /**
     * @return array{state: string, data?: array<string, mixed>}
     */
    private function buildPastoral(User $user, int $tenantId): array
    {
        if (! $user->hasPermission('pastoral.care.view')) {
            return ['state' => 'forbidden'];
        }

        try {
            $dashboard = $this->pastoralCareService->dashboard($tenantId);
            if (! isset($dashboard['open_count'], $dashboard['assigned_count'])
                || ! is_numeric($dashboard['open_count'])
                || ! is_numeric($dashboard['assigned_count'])
            ) {
                return ['state' => 'error'];
            }

            return [
                'state' => 'ready',
                'data' => [
                    'open_count' => $dashboard['open_count'],
                    'assigned_count' => $dashboard['assigned_count'],
                    'drilldown' => 'dashboard.operations',
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('Executive dashboard pastoral failed', ['tenant_id' => $tenantId, 'error' => $e->getMessage()]);

            return ['state' => 'error'];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function massOperationalCounts(int $tenantId): array
    {
        if ($this->massOperationalTenantId !== $tenantId || $this->massOperationalCache === null) {
            $this->massOperationalTenantId = $tenantId;
            $this->massOperationalCache = $this->massIntentionsDashboardService->executiveOperationalCounts($tenantId);
        }

        return $this->massOperationalCache;
    }

    /**
     * @return array{state: string, data?: array<string, mixed>}
     */
    private function buildWorship(User $user, int $tenantId, ?Tenant $tenant): array
    {
        if (! $tenant || ! $user->hasPermission('mass.intentions.view')
            || ! $tenant->supportsMassIntentions()
            || ! $this->entitlementGate->allows($tenant, 'MASS_INTENTIONS')) {
            return ['state' => 'forbidden'];
        }

        try {
            $next = $this->massNextUpcomingCelebrationService->resolve($tenantId);
            $operational = $this->massOperationalCounts($tenantId);
            $rows = $this->massNextUpcomingCelebrationService->listUpcomingCelebrations($tenantId, 4);
            $upcoming = [];
            $timezone = \Modules\Donations\Support\DonationBusinessDate::timezoneForTenant($tenantId);
            foreach ($rows as $celebration) {
                if ($next !== null && (string) $celebration->id === (string) ($next['id'] ?? '')) {
                    continue;
                }
                $date = $celebration->celebrated_on?->format('Y-m-d') ?? '';
                $time = $celebration->celebrated_at
                    ? substr((string) $celebration->celebrated_at, 0, 5)
                    : null;
                $start = Carbon::parse(
                    $date.' '.($time ? $time.':00' : '00:00:00'),
                    $timezone
                );
                $upcoming[] = [
                    'starts_at' => $start->toIso8601String(),
                    'celebrated_on' => $date,
                    'celebrated_at' => $time,
                ];
                if (count($upcoming) >= 3) {
                    break;
                }
            }

            return [
                'state' => 'ready',
                'data' => [
                    'next_mass' => $next,
                    'upcoming' => $upcoming,
                    'this_week_masses' => (int) ($operational['this_week_masses'] ?? 0),
                    'drilldown' => 'mass.home',
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('Executive dashboard worship failed', ['tenant_id' => $tenantId, 'error' => $e->getMessage()]);

            return ['state' => 'error'];
        }
    }

    /**
     * @return array{state: string, data?: array<string, mixed>}
     */
    private function buildCelebrations(User $user, int $tenantId): array
    {
        if (! $user->hasPermission('families.view')) {
            return ['state' => 'forbidden'];
        }

        try {
            $payload = $this->memberCelebrationsService->weekCelebrations($tenantId);

            return [
                'state' => 'ready',
                'data' => [
                    'week_label' => (string) ($payload['week']['label'] ?? ''),
                    'week_start' => (string) ($payload['week']['start'] ?? ''),
                    'week_end' => (string) ($payload['week']['end'] ?? ''),
                    'birthdays_count' => count($payload['birthdays'] ?? []),
                    'anniversaries_count' => count($payload['anniversaries'] ?? []),
                    'drilldown' => 'members.directory',
                ],
            ];
        } catch (\Throwable $e) {
            Log::error('Executive dashboard celebrations failed', ['tenant_id' => $tenantId, 'error' => $e->getMessage()]);

            return ['state' => 'error'];
        }
    }

    /**
     * @return array{state: string, data?: array<string, mixed>}
     */
    private function buildQuickActions(User $user, ?Tenant $tenant): array
    {
        $actions = [];
        if ($user->hasPermission('families.create')) {
            $actions[] = ['key' => 'families.create', 'drilldown' => 'families.create'];
        }
        if ($tenant && $user->hasPermission('donations.collect') && $tenant->supportsDonations()) {
            $actions[] = ['key' => 'donations.collect', 'drilldown' => 'donations.collect'];
        }
        if ($tenant && $user->hasPermission('mass.intentions.view') && $tenant->supportsMassIntentions()) {
            $actions[] = ['key' => 'mass.home', 'drilldown' => 'mass.home'];
        }
        if ($user->hasPermission('sacraments.create')) {
            $actions[] = ['key' => 'sacraments.create', 'drilldown' => 'sacraments.register'];
        }

        if ($actions === []) {
            return ['state' => 'empty', 'data' => ['actions' => []]];
        }

        return ['state' => 'ready', 'data' => ['actions' => $actions]];
    }

    /**
     * Pass through the Donations Dashboard snapshot. Missing amounts stay missing
     * so the caller can show an error instead of a zero.
     *
     * @param  array<string, mixed>  $donation
     * @return array<string, mixed>|null
     */
    private function financialSnapshotFromSummary(array $donation): ?array
    {
        $snapshot = $donation['snapshot'] ?? null;
        if (! is_array($snapshot)) {
            return null;
        }

        $month = $snapshot['month'] ?? null;
        $participation = $snapshot['participation'] ?? null;
        if (! is_array($month) || ! is_array($participation)) {
            return null;
        }

        $collected = $month['collected'] ?? null;
        $comparisonCollected = $month['comparison_collected'] ?? null;
        $outstanding = $snapshot['outstanding_contributions'] ?? null;
        $overdueAmount = $snapshot['overdue_amount'] ?? null;
        $dueNext14 = $snapshot['due_next_14_days_amount'] ?? null;
        $dueLater = $snapshot['due_later_amount'] ?? null;
        $overdueFamilies = $snapshot['overdue_families'] ?? null;
        $rate = $participation['rate'] ?? null;
        $participating = $participation['participating'] ?? null;
        $active = $participation['active'] ?? null;
        $asOf = $snapshot['as_of'] ?? null;

        if (! is_numeric($collected)
            || ! is_numeric($comparisonCollected)
            || ! is_numeric($outstanding)
            || ! is_numeric($overdueAmount)
            || ! is_numeric($dueNext14)
            || ! is_numeric($dueLater)
            || ! is_numeric($overdueFamilies)
            || ! is_numeric($rate)
            || ! is_numeric($participating)
            || ! is_numeric($active)
            || ! is_string($asOf)
            || $asOf === ''
        ) {
            return null;
        }

        $growth = $month['growth_pct'] ?? null;
        if ($growth !== null && ! is_numeric($growth)) {
            return null;
        }

        $netChange = $participation['net_change_vs_prior_window'] ?? null;
        if ($netChange !== null && ! is_numeric($netChange)) {
            return null;
        }

        $installments = $snapshot['project_installments'] ?? null;
        $installmentsOpen = is_array($installments) ? ($installments['open'] ?? null) : null;
        if ($installmentsOpen !== null && ! is_numeric($installmentsOpen)) {
            return null;
        }

        return [
            'as_of' => $asOf,
            'financial_year' => (string) ($snapshot['financial_year'] ?? ''),
            'collected' => $collected,
            'comparison_start' => (string) ($month['comparison_start'] ?? ''),
            'comparison_end' => (string) ($month['comparison_end'] ?? ''),
            'comparison_collected' => $comparisonCollected,
            'growth_pct' => $growth,
            'outstanding_contributions' => $outstanding,
            'project_installments_open' => $installmentsOpen,
            'overdue_amount' => $overdueAmount,
            'due_next_14_days_amount' => $dueNext14,
            'due_later_amount' => $dueLater,
            'due_schedule' => $this->dueScheduleChartSlices($overdueAmount, $dueNext14, $dueLater),
            'overdue_families' => $overdueFamilies,
            'participation_rate' => $rate,
            'participation_participating' => $participating,
            'participation_active' => $active,
            'participation_net_change' => $netChange,
            'participation_window_start' => (string) ($participation['window_start'] ?? ''),
            'participation_window_end' => (string) ($participation['window_end'] ?? ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function donationSummary(int $tenantId): array
    {
        if ($this->donationSummaryTenantId !== $tenantId || $this->donationSummaryCache === null) {
            $this->donationSummaryTenantId = $tenantId;
            $this->donationSummaryCache = $this->donationDashboardService->getSummary($tenantId);
        }

        return $this->donationSummaryCache;
    }

    /**
     * Disjoint due-schedule shares for the executive donut. Amounts are snapshot pass-through;
     * percents are of the three-slice total (same currency, no conversion).
     *
     * @return list<array{key: string, label: string, amount: float, percent: float}>
     */
    private function dueScheduleChartSlices(mixed $overdue, mixed $next14, mixed $later): array
    {
        $rows = [
            ['key' => 'overdue', 'label' => 'Overdue', 'amount' => $overdue],
            ['key' => 'next_14_days', 'label' => 'Next 14 days', 'amount' => $next14],
            ['key' => 'later', 'label' => 'Later', 'amount' => $later],
        ];
        $total = '0';
        foreach ($rows as $row) {
            $total = MoneyMath::add($total, $row['amount']);
        }

        $slices = [];
        foreach ($rows as $row) {
            if (! MoneyMath::isPositive($row['amount'])) {
                continue;
            }

            $percent = MoneyMath::equals($total, '0')
                ? 0.0
                : (float) bcmul(bcdiv(MoneyMath::normalize($row['amount'], 6), MoneyMath::normalize($total, 6), 6), '100', 1);

            $slices[] = [
                'key' => $row['key'],
                'label' => $row['label'],
                'amount' => MoneyMath::toApiNumber($row['amount']),
                'percent' => $percent,
            ];
        }

        return $slices;
    }
}
