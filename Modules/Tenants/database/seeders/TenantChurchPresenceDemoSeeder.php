<?php

namespace Modules\Tenants\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Tenants\Database\Seeders\Support\TenantDemoMarkers;
use Modules\Tenants\Database\Seeders\Support\TenantDemoResolver;
use Modules\Tenants\Models\Archdiocese;
use Modules\Tenants\Models\ChurchProfile;
use Modules\Tenants\Models\ChurchSocialMedia;
use Modules\Tenants\Models\ChurchStatistic;
use Modules\Tenants\Models\Denomination;
use Modules\Tenants\Models\Tenant;

/**
 * Church profile, social links, and time-series statistics for tenant dashboards.
 */
class TenantChurchPresenceDemoSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = TenantDemoResolver::resolveTenant();
        if (! $tenant) {
            $this->command?->error('Tenant not found for church presence demo.');

            return;
        }

        $tenantId = (int) $tenant->id;
        $this->seedProfile($tenant);
        $social = $this->seedSocialMedia($tenantId);
        $stats = $this->seedStatistics($tenantId);

        $this->command?->info(sprintf(
            'Church presence demo: profile ensured, %d social rows added, %d statistic months added (tenant #%d).',
            $social,
            $stats,
            $tenantId
        ));
    }

    private function seedProfile(Tenant $tenant): void
    {
        $denominationId = Denomination::query()->where('code', 'CATHOLIC')->value('id');
        $archdioceseId = Archdiocese::query()->orderBy('id')->value('id');

        $defaults = [
            'denomination_id' => $denominationId,
            'archdiocese_id' => $archdioceseId,
            'founded_year' => 1952,
            'country' => 'India',
            'phone' => '+914712345678',
            'email' => 'office@'.str_replace(' ', '-', strtolower($tenant->slug)).'.parish.demo',
            'website' => 'https://parish.demo/'.$tenant->slug,
            'about' => TenantDemoMarkers::MARKER.' — welcoming parish community with active family and stewardship ministries.',
            'vision' => 'A faithful, family-centered parish.',
            'mission' => 'Celebrate sacraments, serve families, and steward resources with transparency.',
            'core_values' => 'Faith, family, service, stewardship',
            'service_times' => "Sunday Mass 7:00, 9:00, 11:00\nWeekday Mass 6:30 (Mon–Sat)",
            'patron_name' => 'Sacred Heart of Jesus',
        ];

        $profile = ChurchProfile::query()->firstOrNew(['tenant_id' => $tenant->id]);
        $isNew = ! $profile->exists;

        foreach ($defaults as $key => $value) {
            if ($isNew || blank($profile->{$key})) {
                $profile->{$key} = $value;
            }
        }

        if ($isNew || ! str_contains((string) $profile->about, TenantDemoMarkers::MARKER)) {
            $profile->about = trim((string) $profile->about.' '.TenantDemoMarkers::MARKER);
        }

        $profile->save();
    }

    private function seedSocialMedia(int $tenantId): int
    {
        $created = 0;
        $rows = [
            [
                'platform' => ChurchSocialMedia::PLATFORM_FACEBOOK,
                'url' => 'https://facebook.com/parish.demo',
                'username' => 'parish.demo',
                'is_primary' => 1,
                'display_order' => 1,
            ],
            [
                'platform' => ChurchSocialMedia::PLATFORM_YOUTUBE,
                'url' => 'https://youtube.com/@parishdemo',
                'username' => 'parishdemo',
                'is_primary' => 0,
                'display_order' => 2,
            ],
            [
                'platform' => ChurchSocialMedia::PLATFORM_INSTAGRAM,
                'url' => 'https://instagram.com/parish.demo',
                'username' => 'parish.demo',
                'is_primary' => 0,
                'display_order' => 3,
            ],
        ];

        foreach ($rows as $row) {
            $exists = ChurchSocialMedia::query()
                ->where('tenant_id', $tenantId)
                ->where('platform', $row['platform'])
                ->exists();

            if ($exists) {
                continue;
            }

            ChurchSocialMedia::query()->create(array_merge($row, [
                'tenant_id' => $tenantId,
                'follower_count' => 0,
                'active' => 1,
            ]));
            $created++;
        }

        return $created;
    }

    private function seedStatistics(int $tenantId): int
    {
        $year = (int) now()->year;
        $created = 0;

        for ($month = 1; $month <= 12; $month++) {
            $exists = ChurchStatistic::query()
                ->where('tenant_id', $tenantId)
                ->where('year', $year)
                ->where('month', $month)
                ->exists();

            if ($exists) {
                continue;
            }

            $seasonal = match ($month) {
                12, 1 => 420,
                4, 5 => 380,
                default => 340,
            };

            ChurchStatistic::query()->create([
                'tenant_id' => $tenantId,
                'year' => $year,
                'month' => $month,
                'membership_count' => 850 + ($month * 2),
                'weekly_attendance' => $seasonal + ($month % 3) * 10,
                'baptisms' => $month % 4,
                'confirmations' => $month === 5 ? 12 : 0,
                'marriages' => $month % 5 === 0 ? 2 : 0,
                'funerals' => $month % 7 === 0 ? 1 : 0,
                'tithes_offerings' => 125000 + ($month * 3500),
                'notes' => TenantDemoMarkers::MARKER,
            ]);
            $created++;
        }

        // Prior-year December for year-over-year charts when current month is early in the year.
        if (! ChurchStatistic::query()->where('tenant_id', $tenantId)->where('year', $year - 1)->exists()) {
            DB::transaction(function () use ($tenantId, $year): void {
                for ($month = 6; $month <= 12; $month++) {
                    ChurchStatistic::query()->firstOrCreate(
                        [
                            'tenant_id' => $tenantId,
                            'year' => $year - 1,
                            'month' => $month,
                        ],
                        [
                            'membership_count' => 820,
                            'weekly_attendance' => 300 + $month,
                            'baptisms' => 1,
                            'confirmations' => 0,
                            'marriages' => 0,
                            'funerals' => 0,
                            'tithes_offerings' => 110000 + ($month * 2000),
                            'notes' => TenantDemoMarkers::MARKER,
                        ]
                    );
                }
            });
        }

        return $created;
    }
}
