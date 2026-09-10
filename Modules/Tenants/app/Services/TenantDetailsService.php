<?php

namespace Modules\Tenants\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Modules\EcclesiasticalData\Services\DioceseLeadershipQueryService;
use Modules\Family\app\Services\FamilyService;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Models\TenantStatusAudit;
use Modules\Tenants\Support\TenantCacheVersion;

/**
 * Assembles the platform-admin tenant 360° snapshot for an explicitly requested tenant.
 */
class TenantDetailsService
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly TenantUsageService $usageService,
        private readonly FileUploadService $fileUploadService,
        private readonly FamilyService $familyService,
        private readonly DioceseLeadershipQueryService $leadershipQuery,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(int $tenantId): array
    {
        $tenant = Tenant::query()
            ->with([
                'creator:id,name,email',
                'updater:id,name,email',
                'primaryContact:id,name,email,contact_number,user_type,active',
                'secondaryContact:id,name,email,contact_number,user_type,active',
                'addresses',
                'parentTenant:id,name,slug,tenant_tier',
                'churchProfile.denomination:id,name',
                'churchProfile.archdiocese:id,name,code,country',
                'primaryPastor',
            ])
            ->findOrFail($tenantId);

        $subscription = $this->subscriptionService->buildSummary($tenant);
        $storage = $this->usageService->measureStorage($tenant);
        $userActivity = $this->usageService->userActivity($tenant);
        $familyStats = $this->safeFamilyStatistics($tenantId);
        $modules = $this->buildModules($tenant, $subscription);
        $church = $this->buildChurchSnapshot($tenant);
        $historyPreview = $this->buildHistoryPreview($tenantId);
        $warnings = $this->buildWarnings($tenant, $subscription, $storage, $userActivity);

        return [
            'identity' => $this->buildIdentity($tenant),
            'operational' => $this->buildOperational($tenant),
            'subscription' => $subscription,
            'modules' => $modules,
            'contact' => $this->buildContact($tenant),
            'administration' => $this->buildAdministration($tenant, $userActivity),
            'church' => $church,
            'kpis' => $this->buildKpis($tenant, $subscription, $storage, $userActivity, $familyStats, $modules),
            'usage' => [
                'storage' => $storage,
                'users' => $userActivity,
            ],
            'users_preview' => $userActivity['preview'] ?? [],
            'history_preview' => $historyPreview,
            'warnings' => $warnings,
            'meta' => $this->buildMeta($tenant, $subscription),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildIdentity(Tenant $tenant): array
    {
        $logoFullUrl = $tenant->logo_url
            ? $this->fileUploadService->getTenantLogoUrl($tenant->logo_url)
            : null;

        $archdiocese = $tenant->churchProfile?->archdiocese;

        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slogan' => $tenant->slogan,
            'slug' => $tenant->slug,
            'domain' => $tenant->domain,
            'tenant_tier' => $tenant->tenant_tier,
            'parent_tenant_id' => $tenant->parent_tenant_id,
            'parent_tenant_name' => $tenant->parentTenant?->name,
            'hierarchy_path' => $tenant->hierarchy_path,
            'diocese_name' => $archdiocese?->name,
            'diocese_id' => $archdiocese?->id,
            'logo_url' => $tenant->logo_url,
            'logo_full_url' => $logoFullUrl,
            'primary_color' => $tenant->primary_color,
            'secondary_color' => $tenant->secondary_color,
            'created_at' => $tenant->created_at?->toIso8601String(),
            'updated_at' => $tenant->updated_at?->toIso8601String(),
            'created_by' => $tenant->creator ? [
                'id' => $tenant->creator->id,
                'name' => $tenant->creator->name,
            ] : null,
            'updated_by' => $tenant->updater ? [
                'id' => $tenant->updater->id,
                'name' => $tenant->updater->name,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOperational(Tenant $tenant): array
    {
        return [
            'active' => (int) $tenant->active === 1,
            'active_flag' => (int) $tenant->active,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContact(Tenant $tenant): array
    {
        $profile = $tenant->churchProfile;
        $primary = $tenant->primaryContact;
        $officialAddress = $tenant->addresses
            ->first(fn ($addr) => $addr->address_type === 'official' && (int) $addr->active === 1)
            ?? $tenant->addresses->first(fn ($addr) => (int) $addr->active === 1);

        return [
            'primary_contact' => $primary ? [
                'id' => $primary->id,
                'name' => $primary->name,
                'email' => $primary->email,
                'phone' => $primary->contact_number,
            ] : null,
            'secondary_contact' => $tenant->secondaryContact ? [
                'id' => $tenant->secondaryContact->id,
                'name' => $tenant->secondaryContact->name,
                'email' => $tenant->secondaryContact->email,
                'phone' => $tenant->secondaryContact->contact_number,
            ] : null,
            'email' => $profile?->email,
            'phone' => $profile?->phone,
            'website' => $profile?->website,
            'address' => $officialAddress ? [
                'line1' => $officialAddress->line1,
                'line2' => $officialAddress->line2,
                'city' => $officialAddress->city,
                'district' => $officialAddress->district,
                'state_province' => $officialAddress->state_province,
                'country' => $officialAddress->country,
                'postal_code' => $officialAddress->pin_zip_code,
            ] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $userActivity
     * @return array<string, mixed>
     */
    private function buildAdministration(Tenant $tenant, array $userActivity): array
    {
        $primaryAdmin = \Modules\Authentication\Models\User::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_primary_admin', true)
            ->where('active', 1)
            ->first(['id', 'name', 'email']);

        return [
            'primary_admin' => $primaryAdmin ? [
                'id' => $primaryAdmin->id,
                'name' => $primaryAdmin->name,
                'email' => $primaryAdmin->email,
            ] : null,
            'total_users' => $userActivity['total_users'] ?? 0,
            'active_users' => $userActivity['active_users'] ?? 0,
            'inactive_users' => $userActivity['inactive_users'] ?? 0,
            'remaining_user_slots' => $tenant->getRemainingUserSlots(),
            'max_users' => $tenant->max_users,
            'role_distribution' => $userActivity['role_distribution'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildChurchSnapshot(Tenant $tenant): ?array
    {
        $profile = $tenant->churchProfile;
        $pastor = $tenant->primaryPastor;

        if (! $profile && ! $pastor) {
            return null;
        }

        $bishop = null;
        if ($profile?->archdiocese_id) {
            try {
                $leadership = $this->leadershipQuery->getCurrentLeadership((int) $profile?->archdiocese_id);
                $bishop = $leadership['ordinary'] ?? null;
            } catch (\Throwable $e) {
                Log::warning('Failed to resolve current bishop for tenant details', [
                    'tenant_id' => $tenant->id,
                    'archdiocese_id' => $profile->archdiocese_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'profile_configured' => $profile !== null,
            'denomination' => $profile?->denomination?->name,
            'archdiocese' => $profile?->archdiocese ? [
                'id' => $profile->archdiocese->id,
                'name' => $profile->archdiocese->name,
                'code' => $profile->archdiocese->code,
                'country' => $profile->archdiocese->country,
            ] : null,
            'bishop' => $bishop ? [
                'id' => $bishop['bishop_id'] ?? null,
                'name' => $bishop['bishop_name'] ?? null,
                'title' => $bishop['title'] ?? null,
                'canonical_role' => $bishop['canonical_role'] ?? null,
                'photo_url' => $bishop['photo_url'] ?? $bishop['photo_public_url'] ?? null,
                'effective_date' => $bishop['effective_date'] ?? null,
                'is_current' => $bishop['is_current'] ?? true,
            ] : null,
            'primary_pastor' => $pastor ? [
                'id' => $pastor->id,
                'name' => $pastor->full_name,
                'role' => $pastor->role,
                'title' => $pastor->title,
                'email' => $pastor->email,
                'phone' => $pastor->phone,
                'appointed_date' => $pastor->appointed_date?->toDateString(),
                'photo_url' => $pastor->photo_url,
            ] : null,
            'founded_year' => $profile?->founded_year,
            'patron_name' => $profile?->patron_name,
            'about' => $profile?->about,
            'vision' => $profile?->vision,
            'mission' => $profile?->mission,
            'service_times' => $profile?->service_times,
            'country' => $profile?->country,
            'phone' => $profile?->phone,
            'email' => $profile?->email,
            'website' => $profile?->website,
        ];
    }

    /**
     * @param  array<string, mixed>  $subscription
     * @return list<array<string, mixed>>
     */
    private function buildModules(Tenant $tenant, array $subscription): array
    {
        $available = (array) config('tenants.available_features', []);
        $gated = $this->subscriptionService->gatedModules();
        $alwaysOn = (array) config('tenants.subscription.always_on', []);
        $accessMode = $subscription['access_mode'] ?? 'full';

        $moduleKeys = array_values(array_unique(array_merge(
            array_keys($available),
            $gated,
            $alwaysOn,
            is_array($tenant->features) ? $tenant->features : []
        )));

        $modules = [];
        foreach ($moduleKeys as $key) {
            $entitled = $this->isModuleEntitled($tenant, $key);
            $evaluation = in_array($key, $gated, true)
                ? $this->subscriptionService->evaluateModuleAccess($tenant, $key)
                : ['allowed' => true, 'reason' => null, 'status' => $subscription['status'] ?? null];

            $modules[] = [
                'key' => $key,
                'label' => $available[$key] ?? ucfirst(str_replace('_', ' ', $key)),
                'entitled' => $entitled,
                'gated' => in_array($key, $gated, true),
                'always_on' => in_array($key, $alwaysOn, true),
                'accessible' => (bool) ($evaluation['allowed'] ?? false),
                'access_reason' => $evaluation['reason'] ?? null,
                'subscription_status' => $evaluation['status'] ?? null,
                'access_mode' => $accessMode,
            ];
        }

        usort($modules, fn ($a, $b) => strcmp((string) $a['label'], (string) $b['label']));

        return $modules;
    }

    private function isModuleEntitled(Tenant $tenant, string $key): bool
    {
        if ($key === 'donations') {
            return $tenant->supportsDonations();
        }
        if ($key === 'ministries_associations') {
            return $tenant->supportsMinistriesAssociations();
        }

        return $tenant->hasFeature($key);
    }

    /**
     * @param  array<string, mixed>|null  $familyStats
     * @param  list<array<string, mixed>>  $modules
     * @return array<string, mixed>
     */
    private function buildKpis(
        Tenant $tenant,
        array $subscription,
        array $storage,
        array $userActivity,
        ?array $familyStats,
        array $modules
    ): array {
        $kpis = [
            'users' => $userActivity['total_users'] ?? 0,
            'active_users' => $userActivity['active_users'] ?? 0,
            'frequent_users_30d' => $userActivity['frequent_users_30d'],
            'seen_last_7d' => $userActivity['seen_last_7d'],
            'enabled_modules' => count(array_filter($modules, fn ($m) => $m['entitled'])),
            'days_until_end' => $subscription['days_until_end'] ?? null,
            'subscription_status' => $subscription['status'] ?? null,
            'access_mode' => $subscription['access_mode'] ?? null,
            'last_activity_at' => $userActivity['last_seen_at'] ?? $tenant->updated_at?->toIso8601String(),
        ];

        if ($familyStats !== null) {
            $kpis['families'] = $familyStats['total_families'] ?? null;
            $kpis['active_families'] = $familyStats['active_families'] ?? null;
            $kpis['members'] = $familyStats['total_members'] ?? null;
            $kpis['active_members'] = $familyStats['active_members'] ?? null;
        }

        if ($storage['available'] ?? false) {
            $kpis['storage_used_mb'] = $storage['used_mb'];
            $kpis['storage_max_mb'] = $storage['max_storage_mb'];
            $kpis['storage_percent_used'] = $storage['percent_used'];
        }

        return $kpis;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function safeFamilyStatistics(int $tenantId): ?array
    {
        try {
            return $this->familyService->getStatistics((string) $tenantId);
        } catch (\Throwable $e) {
            Log::warning('Family statistics unavailable for tenant details', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildHistoryPreview(int $tenantId): array
    {
        $items = [];

        $subscriptionAudits = $this->subscriptionService->listAuditsForTenant($tenantId, [
            'per_page' => 5,
            'page' => 1,
        ]);

        foreach ($subscriptionAudits['data'] as $audit) {
            $items[] = [
                'id' => 'sub_'.$audit['id'],
                'category' => 'subscription',
                'action' => $audit['operation'],
                'action_label' => $audit['operation_label'],
                'summary' => $audit['summary'],
                'actor_name' => $audit['actor_name'],
                'actor_role' => $audit['actor_role'],
                'created_at' => $audit['created_at'],
            ];
        }

        if (Schema::hasTable('tenant_status_audit')) {
            $statusAudits = TenantStatusAudit::query()
                ->with('user:id,name')
                ->where('tenant_id', $tenantId)
                ->orderByDesc('created_at')
                ->limit(5)
                ->get();

            foreach ($statusAudits as $audit) {
                $items[] = [
                    'id' => 'status_'.$audit->id,
                    'category' => 'operational',
                    'action' => $audit->action,
                    'action_label' => ucfirst((string) $audit->action),
                    'summary' => sprintf(
                        'Tenant %s',
                        $audit->action === 'activated' ? 'activated' : 'deactivated'
                    ),
                    'actor_name' => $audit->user?->name,
                    'actor_role' => null,
                    'created_at' => $audit->created_at?->toIso8601String(),
                ];
            }
        }

        usort($items, fn ($a, $b) => strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? '')));

        return array_slice($items, 0, 10);
    }

    /**
     * @param  array<string, mixed>  $subscription
     * @param  array<string, mixed>  $storage
     * @param  array<string, mixed>  $userActivity
     * @return list<array<string, mixed>>
     */
    private function buildWarnings(
        Tenant $tenant,
        array $subscription,
        array $storage,
        array $userActivity
    ): array {
        $warnings = [];

        if ((int) $tenant->active !== 1) {
            $warnings[] = [
                'code' => 'tenant_inactive',
                'severity' => 'critical',
                'message' => 'Tenant inactive — operational access restricted.',
            ];
        }

        $status = $subscription['status'] ?? null;
        $accessMode = $subscription['access_mode'] ?? null;

        if ($status === SubscriptionService::STATUS_EXPIRED) {
            $warnings[] = [
                'code' => 'subscription_expired',
                'severity' => 'warning',
                'message' => 'Subscription expired — tenant is currently read-only.',
            ];
        }

        if ($status === SubscriptionService::STATUS_SUSPENDED) {
            $warnings[] = [
                'code' => 'subscription_suspended',
                'severity' => 'critical',
                'message' => 'Subscription suspended — gated access restricted.',
            ];
        }

        if ($accessMode === SubscriptionService::ACCESS_MODE_READ_ONLY && $status !== SubscriptionService::STATUS_EXPIRED) {
            $warnings[] = [
                'code' => 'read_only_access',
                'severity' => 'warning',
                'message' => 'Tenant is currently in read-only access mode.',
            ];
        }

        if (! \Modules\Authentication\Models\User::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_primary_admin', true)
            ->where('active', 1)
            ->exists()) {
            $warnings[] = [
                'code' => 'no_active_admin',
                'severity' => 'warning',
                'message' => 'No active administrator found.',
            ];
        }

        if (($storage['available'] ?? false) && ($storage['percent_used'] ?? 0) >= 90) {
            $warnings[] = [
                'code' => 'storage_near_quota',
                'severity' => 'warning',
                'message' => 'Storage usage is near the allocated quota.',
            ];
        }

        if (($userActivity['total_users'] ?? 0) > 0 && ($userActivity['seen_last_30d'] ?? 0) === 0) {
            $warnings[] = [
                'code' => 'no_recent_user_activity',
                'severity' => 'info',
                'message' => 'No user sign-in activity in the last 30 days.',
            ];
        }

        return $warnings;
    }

    /**
     * @param  array<string, mixed>  $subscription
     * @return array<string, mixed>
     */
    private function buildMeta(Tenant $tenant, array $subscription): array
    {
        return [
            'tenant_id' => $tenant->id,
            'tenant_code' => $tenant->slug,
            'cache_version' => TenantCacheVersion::current((int) $tenant->id),
            'features' => $tenant->features ?? [],
            'plan_key' => $subscription['plan_key'] ?? $tenant->plan,
            'write_policy' => $subscription['write_policy'] ?? null,
            'generated_at' => now()->toIso8601String(),
        ];
    }
}
