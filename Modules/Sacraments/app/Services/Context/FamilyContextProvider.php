<?php

namespace Modules\Sacraments\Services\Context;

use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Tenants\Models\Tenant;

final class FamilyContextProvider
{
    public function __construct(
        private readonly ProvenanceBuilder $provenance,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(?FamilyMember $member, int|string $tenantId): array
    {
        if ($member === null || $member->family === null) {
            return [
                'record_status' => 'NOT_FOUND',
                'data' => null,
            ];
        }

        /** @var Family $family */
        $family = $member->family;
        $address = $this->formatAddress($family);

        return [
            'record_status' => 'FOUND',
            'data' => [
                'family_id' => $family->id,
                'family_name' => $family->family_name,
                'family_code' => $family->family_code,
                'address' => $this->provenance->field(
                    $address,
                    'READ_ONLY_VERIFIED',
                    'FAMILY_RECORD',
                    $family->id,
                    'Family Record',
                ),
                'member_id' => $member->id,
                'relationship_to_head' => $member->relationship_to_head,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function resolveParish(int|string $tenantId): array
    {
        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null) {
            return ['record_status' => 'NOT_FOUND', 'data' => null];
        }

        return [
            'record_status' => 'FOUND',
            'data' => [
                'tenant_id' => $tenant->id,
                'name' => $tenant->name,
            ],
        ];
    }

    private function formatAddress(Family $family): ?string
    {
        $parts = array_filter([
            $family->address_line_1,
            $family->address_line_2,
            $family->city,
            $family->postal_code,
        ]);

        return $parts !== [] ? implode(', ', $parts) : null;
    }
}
