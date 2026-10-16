<?php

namespace Modules\Tenants\Export\Contributors;

use Modules\Tenants\Contracts\ExportWriterFactory;
use Modules\Tenants\Contracts\TenantDataExportContributor;
use Modules\Tenants\Models\ChurchLeadership;
use Modules\Tenants\Models\ChurchProfile;
use Modules\Tenants\Models\ChurchSocialMedia;
use Modules\Tenants\Models\ChurchStatistic;
use Modules\Tenants\Models\LeadershipAssignment;

class ChurchProfileDataExportContributor implements TenantDataExportContributor
{
    use WritesChunkedExportCsv;

    public function key(): string
    {
        return 'church_profile';
    }

    public function label(): string
    {
        return 'Church profile & leadership';
    }

    public function defaultSelected(): bool
    {
        return true;
    }

    public function estimateCount(int $tenantId): int
    {
        return ChurchProfile::query()->where('tenant_id', $tenantId)->count()
            + ChurchLeadership::query()->where('tenant_id', $tenantId)->count()
            + LeadershipAssignment::query()->where('tenant_id', $tenantId)->count()
            + ChurchSocialMedia::query()->where('tenant_id', $tenantId)->count()
            + ChurchStatistic::query()->where('tenant_id', $tenantId)->count();
    }

    public function export(
        int $tenantId,
        ExportWriterFactory $writers,
        int $chunkSize,
        callable $onProgress
    ): array {
        return [
            'files' => [
                'data/church_profile.csv' => $this->writeChunkedQuery(
                    $writers,
                    'data/church_profile.csv',
                    [
                        'profile_id', 'denomination_id', 'denomination_name', 'archdiocese_id',
                        'archdiocese_name', 'bishop_id', 'bishop_name', 'founded_year', 'country',
                        'phone', 'email', 'website', 'patron_name', 'about', 'vision', 'mission',
                        'created_at',
                    ],
                    ChurchProfile::query()
                        ->where('tenant_id', $tenantId)
                        ->with(['denomination:id,name', 'archdiocese:id,name']),
                    $chunkSize,
                    $onProgress,
                    fn ($row) => [
                        $row->id,
                        $row->denomination_id,
                        $row->denomination?->name,
                        $row->archdiocese_id,
                        $row->archdiocese?->name,
                        ($bishop = $row->resolvePresidingBishop())?->id,
                        $bishop?->full_name,
                        $row->founded_year,
                        $row->country,
                        $row->phone,
                        $row->email,
                        $row->website,
                        $row->patron_name,
                        $row->about,
                        $row->vision,
                        $row->mission,
                        $row->created_at,
                    ]
                ),
                'data/church_leadership.csv' => $this->writeChunkedQuery(
                    $writers,
                    'data/church_leadership.csv',
                    [
                        'leadership_id', 'full_name', 'role', 'title', 'email', 'phone',
                        'appointed_date', 'relieved_date', 'is_primary', 'status', 'display_order',
                        'created_at',
                    ],
                    ChurchLeadership::query()->where('tenant_id', $tenantId),
                    $chunkSize,
                    $onProgress,
                    fn ($row) => [
                        $row->id, $row->full_name, $row->role, $row->title, $row->email, $row->phone,
                        $row->appointed_date, $row->relieved_date, $this->boolLabel($row->is_primary),
                        $this->activeLabel($row->active), $row->display_order, $row->created_at,
                    ]
                ),
                'data/leadership_assignments.csv' => $this->writeChunkedQuery(
                    $writers,
                    'data/leadership_assignments.csv',
                    [
                        'assignment_id', 'person_id', 'role_id', 'role_title', 'start_date', 'end_date',
                        'status', 'appointment_date', 'appointment_letter_ref', 'exit_reason_code',
                        'legacy_church_leadership_id', 'created_at',
                    ],
                    LeadershipAssignment::query()
                        ->where('tenant_id', $tenantId)
                        ->with(['role:id,title', 'person:id,first_name,last_name']),
                    $chunkSize,
                    $onProgress,
                    fn ($row) => [
                        $row->id,
                        $row->person_id,
                        $row->role_id,
                        $row->role?->title,
                        $row->start_date,
                        $row->end_date,
                        $row->status,
                        $row->appointment_date,
                        $row->appointment_letter_ref,
                        $row->exit_reason_code,
                        $row->legacy_church_leadership_id,
                        $row->created_at,
                    ]
                ),
                'data/church_social_media.csv' => $this->writeChunkedQuery(
                    $writers,
                    'data/church_social_media.csv',
                    [
                        'social_id', 'platform', 'url', 'username', 'follower_count',
                        'is_primary', 'status', 'display_order', 'created_at',
                    ],
                    ChurchSocialMedia::query()->where('tenant_id', $tenantId),
                    $chunkSize,
                    $onProgress,
                    fn ($row) => [
                        $row->id, $row->platform, $row->url, $row->username, $row->follower_count,
                        $this->boolLabel($row->is_primary), $this->activeLabel($row->active),
                        $row->display_order, $row->created_at,
                    ]
                ),
                'data/church_statistics.csv' => $this->writeChunkedQuery(
                    $writers,
                    'data/church_statistics.csv',
                    [
                        'statistic_id', 'year', 'month', 'membership_count', 'weekly_attendance',
                        'baptisms', 'confirmations', 'marriages', 'funerals', 'tithes_offerings',
                        'notes', 'created_at',
                    ],
                    ChurchStatistic::query()->where('tenant_id', $tenantId),
                    $chunkSize,
                    $onProgress,
                    fn ($row) => [
                        $row->id, $row->year, $row->month, $row->membership_count, $row->weekly_attendance,
                        $row->baptisms, $row->confirmations, $row->marriages, $row->funerals,
                        $row->tithes_offerings, $row->notes, $row->created_at,
                    ]
                ),
            ],
        ];
    }
}
