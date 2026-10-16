<?php

namespace Modules\Tenants\Export\Contributors;

use Modules\MinistriesAssociations\Models\GuestMember;
use Modules\MinistriesAssociations\Models\LeadershipTerm;
use Modules\MinistriesAssociations\Models\Organization;
use Modules\MinistriesAssociations\Models\OrganizationCategory;
use Modules\MinistriesAssociations\Models\OrganizationMembership;
use Modules\MinistriesAssociations\Models\OrganizationType;
use Modules\MinistriesAssociations\Models\Position;
use Modules\Tenants\Contracts\ExportWriterFactory;
use Modules\Tenants\Contracts\TenantDataExportContributor;

class MinistriesDataExportContributor implements TenantDataExportContributor
{
    use WritesChunkedExportCsv;

    public function key(): string
    {
        return 'ministries';
    }

    public function label(): string
    {
        return 'Ministries & associations';
    }

    public function defaultSelected(): bool
    {
        return true;
    }

    public function estimateCount(int $tenantId): int
    {
        return OrganizationType::forTenant($tenantId)->count()
            + OrganizationCategory::forTenant($tenantId)->count()
            + Organization::forTenant($tenantId)->count()
            + OrganizationMembership::forTenant($tenantId)->count()
            + Position::forTenant($tenantId)->count()
            + LeadershipTerm::forTenant($tenantId)->count()
            + GuestMember::forTenant($tenantId)->count();
    }

    public function export(
        int $tenantId,
        ExportWriterFactory $writers,
        int $chunkSize,
        callable $onProgress
    ): array {
        return [
            'files' => [
                'data/ministry_types.csv' => $this->writeChunkedQuery(
                    $writers,
                    'data/ministry_types.csv',
                    ['type_id', 'code', 'name', 'description', 'is_system', 'status', 'display_order', 'created_at'],
                    OrganizationType::forTenant($tenantId),
                    $chunkSize,
                    $onProgress,
                    fn ($row) => [
                        $row->id, $row->code, $row->name, $row->description,
                        $this->boolLabel($row->is_system), $this->boolLabel($row->is_active),
                        $row->display_order, $row->created_at,
                    ]
                ),
                'data/ministry_categories.csv' => $this->writeChunkedQuery(
                    $writers,
                    'data/ministry_categories.csv',
                    ['category_id', 'code', 'name', 'description', 'is_system', 'status', 'display_order', 'created_at'],
                    OrganizationCategory::forTenant($tenantId),
                    $chunkSize,
                    $onProgress,
                    fn ($row) => [
                        $row->id, $row->code, $row->name, $row->description,
                        $this->boolLabel($row->is_system), $this->boolLabel($row->is_active),
                        $row->display_order, $row->created_at,
                    ]
                ),
                'data/ministry_organizations.csv' => $this->writeChunkedQuery(
                    $writers,
                    'data/ministry_organizations.csv',
                    [
                        'organization_id', 'code', 'name', 'short_name', 'category_id', 'category_name',
                        'type_id', 'type_name', 'status', 'email', 'phone', 'website', 'established_date',
                        'patron_saint', 'created_at',
                    ],
                    Organization::forTenant($tenantId)->with(['category:id,name', 'type:id,name']),
                    $chunkSize,
                    $onProgress,
                    fn ($row) => [
                        $row->id, $row->code, $row->name, $row->short_name,
                        $row->category_id, $row->category?->name,
                        $row->type_id, $row->type?->name,
                        $row->status, $row->email, $row->phone, $row->website,
                        $row->established_date, $row->patron_saint, $row->created_at,
                    ]
                ),
                'data/ministry_positions.csv' => $this->writeChunkedQuery(
                    $writers,
                    'data/ministry_positions.csv',
                    ['position_id', 'code', 'name', 'single_occupancy', 'is_system', 'status', 'display_order', 'created_at'],
                    Position::forTenant($tenantId),
                    $chunkSize,
                    $onProgress,
                    fn ($row) => [
                        $row->id, $row->code, $row->name,
                        $this->boolLabel($row->single_occupancy),
                        $this->boolLabel($row->is_system),
                        $this->boolLabel($row->is_active),
                        $row->display_order, $row->created_at,
                    ]
                ),
                'data/ministry_guests.csv' => $this->writeChunkedQuery(
                    $writers,
                    'data/ministry_guests.csv',
                    [
                        'guest_id', 'first_name', 'last_name', 'gender', 'phone', 'email',
                        'guest_type', 'external_organization', 'linked_family_member_id', 'created_at',
                    ],
                    GuestMember::forTenant($tenantId),
                    $chunkSize,
                    $onProgress,
                    fn ($row) => [
                        $row->id, $row->first_name, $row->last_name, $row->gender,
                        $row->phone, $row->email, $row->guest_type, $row->external_organization,
                        $row->linked_family_member_id, $row->created_at,
                    ]
                ),
                'data/ministry_memberships.csv' => $this->writeChunkedQuery(
                    $writers,
                    'data/ministry_memberships.csv',
                    [
                        'membership_id', 'organization_id', 'organization_name', 'member_source',
                        'family_member_id', 'guest_member_id', 'member_name', 'member_type',
                        'status', 'joined_date', 'exit_date', 'is_current', 'created_at',
                    ],
                    OrganizationMembership::forTenant($tenantId)->with([
                        'organization:id,name',
                        'familyMember:id,first_name,last_name',
                        'guestMember:id,first_name,last_name',
                    ]),
                    $chunkSize,
                    $onProgress,
                    function ($row) {
                        $name = null;
                        if ($row->familyMember) {
                            $name = trim($row->familyMember->first_name.' '.$row->familyMember->last_name);
                        } elseif ($row->guestMember) {
                            $name = trim($row->guestMember->first_name.' '.$row->guestMember->last_name);
                        }

                        return [
                            $row->id, $row->organization_id, $row->organization?->name,
                            $row->member_source, $row->family_member_id, $row->guest_member_id,
                            $name, $row->member_type, $row->status, $row->joined_date,
                            $row->exit_date, $this->boolLabel($row->is_current), $row->created_at,
                        ];
                    }
                ),
                'data/ministry_leadership_terms.csv' => $this->writeChunkedQuery(
                    $writers,
                    'data/ministry_leadership_terms.csv',
                    [
                        'term_id', 'organization_id', 'organization_name', 'membership_id',
                        'position_id', 'position_name', 'appointment_date', 'effective_from',
                        'effective_to', 'term_label', 'is_interim', 'status', 'created_at',
                    ],
                    LeadershipTerm::forTenant($tenantId)->with([
                        'organization:id,name',
                        'position:id,name',
                    ]),
                    $chunkSize,
                    $onProgress,
                    fn ($row) => [
                        $row->id, $row->organization_id, $row->organization?->name,
                        $row->membership_id, $row->position_id, $row->position?->name,
                        $row->appointment_date, $row->effective_from, $row->effective_to,
                        $row->term_label, $this->boolLabel($row->is_interim),
                        $row->status, $row->created_at,
                    ]
                ),
            ],
        ];
    }
}
