<?php

namespace Modules\Tenants\Export\Contributors;

use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Tenants\Contracts\ExportWriterFactory;
use Modules\Tenants\Contracts\TenantDataExportContributor;

class FamiliesDataExportContributor implements TenantDataExportContributor
{
    use WritesChunkedExportCsv;

    public function key(): string
    {
        return 'families';
    }

    public function label(): string
    {
        return 'Families & members';
    }

    public function defaultSelected(): bool
    {
        return true;
    }

    public function estimateCount(int $tenantId): int
    {
        $families = Family::query()->where('tenant_id', $tenantId)->count();
        $members = FamilyMember::query()
            ->whereHas('family', fn ($q) => $q->where('tenant_id', $tenantId))
            ->count();

        return $families + $members;
    }

    public function export(
        int $tenantId,
        ExportWriterFactory $writers,
        int $chunkSize,
        callable $onProgress
    ): array {
        return [
            'files' => [
                'data/families.csv' => $this->exportFamilies($tenantId, $writers, $chunkSize, $onProgress),
                'data/members.csv' => $this->exportMembers($tenantId, $writers, $chunkSize, $onProgress),
            ],
        ];
    }

    private function exportFamilies(int $tenantId, ExportWriterFactory $writers, int $chunkSize, callable $onProgress): int
    {
        return $this->writeChunkedQuery(
            $writers,
            'data/families.csv',
            [
                'family_id', 'family_code', 'family_name', 'head_of_family', 'status',
                'bcc_id', 'bcc_name', 'address_line_1', 'address_line_2', 'city',
                'state_id', 'country_id', 'postal_code', 'notes', 'created_at',
            ],
            Family::query()
                ->where('tenant_id', $tenantId)
                ->with(['bcc:id,name']),
            $chunkSize,
            $onProgress,
            fn ($family) => [
                $family->id,
                $family->family_code,
                $family->family_name,
                $family->head_of_family,
                $family->status,
                $family->bcc_id,
                $family->bcc?->name,
                $family->address_line_1,
                $family->address_line_2,
                $family->city,
                $family->state_id,
                $family->country_id,
                $family->postal_code,
                $family->notes,
                $family->created_at,
            ]
        );
    }

    private function exportMembers(int $tenantId, ExportWriterFactory $writers, int $chunkSize, callable $onProgress): int
    {
        return $this->writeChunkedQuery(
            $writers,
            'data/members.csv',
            [
                'member_id', 'family_id', 'family_name', 'first_name', 'middle_name', 'last_name',
                'date_of_birth', 'gender', 'relationship_to_head', 'marital_status', 'phone', 'email',
                'is_primary_contact', 'occupation', 'education', 'status', 'deceased_date', 'created_at',
            ],
            FamilyMember::query()
                ->whereHas('family', fn ($q) => $q->where('tenant_id', $tenantId))
                ->with(['family:id,family_name']),
            $chunkSize,
            $onProgress,
            fn ($member) => [
                $member->id,
                $member->family_id,
                $member->family?->family_name,
                $member->first_name,
                $member->middle_name,
                $member->last_name,
                $member->date_of_birth,
                $member->gender,
                $member->relationship_to_head,
                $member->marital_status,
                $member->phone,
                $member->email,
                $this->boolLabel($member->is_primary_contact),
                $member->occupation,
                $member->education,
                $member->status,
                $member->deceased_date,
                $member->created_at,
            ]
        );
    }
}
