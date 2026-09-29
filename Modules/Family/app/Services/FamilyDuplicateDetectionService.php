<?php

namespace Modules\Family\app\Services;

use Modules\Family\app\Repositories\FamilyRepository;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;

/**
 * Tenant-scoped duplicate family detection by surname + address + primary phone.
 */
class FamilyDuplicateDetectionService
{
    public function __construct(
        protected FamilyRepository $familyRepository,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return list<array<string, mixed>>
     */
    public function findDuplicates(int|string $tenantId, array $data, ?string $excludeFamilyId = null): array
    {
        $familyName = $this->normalize((string) ($data['family_name'] ?? ''));
        $address = $this->normalize((string) ($data['address_line_1'] ?? ''));
        $phone = $this->normalizePhone($this->resolvePhone($data));

        if ($familyName === '' || $address === '' || $phone === '') {
            return [];
        }

        $query = Family::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(TRIM(family_name)) = ?', [$familyName])
            ->whereRaw('LOWER(TRIM(address_line_1)) = ?', [$address]);

        if ($excludeFamilyId) {
            $query->where('id', '!=', $excludeFamilyId);
        }

        $candidates = $query->with(['members' => function ($q) {
            $q->whereNull('deleted_at');
        }])->get();

        return $candidates->filter(function (Family $family) use ($phone) {
            return $this->familyPrimaryPhone($family) === $phone;
        })->map(function (Family $family) {
            return [
                'id' => $family->id,
                'family_code' => $family->family_code,
                'family_name' => $family->family_name,
                'address_line_1' => $family->address_line_1,
                'head_of_family' => $family->head_of_family,
            ];
        })->values()->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolvePhone(array $data): string
    {
        if (! empty($data['primary_phone'])) {
            return (string) $data['primary_phone'];
        }

        if (! empty($data['members']) && is_array($data['members'])) {
            foreach ($data['members'] as $member) {
                if (! empty($member['is_primary_contact']) && ! empty($member['phone'])) {
                    return (string) $member['phone'];
                }
                if (! empty($member['phone']) && in_array(strtolower((string) ($member['relationship_to_head'] ?? '')), ['self', 'head'], true)) {
                    return (string) $member['phone'];
                }
            }
            foreach ($data['members'] as $member) {
                if (! empty($member['phone'])) {
                    return (string) $member['phone'];
                }
            }
        }

        return '';
    }

    private function familyPrimaryPhone(Family $family): string
    {
        $members = $family->members ?? collect();

        $primary = $members->firstWhere('is_primary_contact', true);
        if ($primary?->phone) {
            return $this->normalizePhone((string) $primary->phone);
        }

        $head = $members->first(function (FamilyMember $member) {
            return in_array(strtolower((string) $member->relationship_to_head), ['self', 'head'], true);
        });
        if ($head?->phone) {
            return $this->normalizePhone((string) $head->phone);
        }

        $any = $members->first(fn (FamilyMember $m) => ! empty($m->phone));

        return $any ? $this->normalizePhone((string) $any->phone) : '';
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? ''));
    }

    private function normalizePhone(string $value): string
    {
        return preg_replace('/\D+/', '', $value) ?? '';
    }
}
