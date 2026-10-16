<?php

namespace Modules\Sacraments\Services;

use Illuminate\Support\Collection;
use Modules\BCC\Models\BCC;
use Modules\Family\Models\FamilyMember;
use Modules\Sacraments\Exceptions\SacramentBusinessRuleException;
use Modules\Sacraments\Models\MarriagePreparationCase;
use Modules\Sacraments\Support\MarriagePreparationStatus;

class MarriagePreparationCaseService
{
    /**
     * @return Collection<int, MarriagePreparationCase>
     */
    public function list(int $tenantId, ?string $bccId = null, ?string $status = null): Collection
    {
        $query = MarriagePreparationCase::query()
            ->forTenant($tenantId)
            ->with(['brideFamilyMember:id,first_name,last_name', 'groomFamilyMember:id,first_name,last_name'])
            ->orderByDesc('inquiry_started_at');

        if ($bccId !== null && $bccId !== '') {
            $query->where('bcc_id', $bccId);
        }

        if ($status !== null && $status !== '') {
            $normalized = MarriagePreparationStatus::normalize($status);
            if ($normalized !== null) {
                $query->where('status', $normalized);
            }
        }

        return $query->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(int $tenantId, ?int $userId, array $data): MarriagePreparationCase
    {
        $this->assertBccBelongsToTenant($tenantId, $data['bcc_id'] ?? null);
        $this->assertFamilyMembersBelongToTenant($tenantId, $data);

        return MarriagePreparationCase::query()->create([
            'tenant_id' => $tenantId,
            'bcc_id' => $data['bcc_id'] ?? null,
            'bride_family_member_id' => $data['bride_family_member_id'] ?? null,
            'groom_family_member_id' => $data['groom_family_member_id'] ?? null,
            'status' => MarriagePreparationStatus::ACTIVE,
            'inquiry_started_at' => $data['inquiry_started_at'] ?? now(),
            'intended_marriage_date' => $data['intended_marriage_date'] ?? null,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(int $tenantId, int $caseId, ?int $userId, array $data): MarriagePreparationCase
    {
        $case = $this->findForTenant($tenantId, $caseId);

        if (array_key_exists('bcc_id', $data)) {
            $this->assertBccBelongsToTenant($tenantId, $data['bcc_id']);
        }

        if (array_key_exists('bride_family_member_id', $data) || array_key_exists('groom_family_member_id', $data)) {
            $this->assertFamilyMembersBelongToTenant($tenantId, array_merge([
                'bride_family_member_id' => $case->bride_family_member_id,
                'groom_family_member_id' => $case->groom_family_member_id,
            ], $data));
        }

        $allowed = [
            'bcc_id',
            'bride_family_member_id',
            'groom_family_member_id',
            'sacrament_id',
            'pre_cana_completed_at',
            'banns_published_at',
            'canonical_docs_verified_at',
            'intended_marriage_date',
        ];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $case->{$field} = $data[$field];
            }
        }

        if (array_key_exists('status', $data)) {
            $normalized = MarriagePreparationStatus::normalize($data['status']);
            if ($normalized !== null) {
                $case->status = $normalized;
            }
        }

        $case->updated_by = $userId;
        $case->save();

        return $case->fresh([
            'brideFamilyMember:id,first_name,last_name',
            'groomFamilyMember:id,first_name,last_name',
        ]);
    }

    public function findForTenant(int $tenantId, int $caseId): MarriagePreparationCase
    {
        $case = MarriagePreparationCase::query()
            ->forTenant($tenantId)
            ->whereKey($caseId)
            ->first();

        if ($case === null) {
            throw new SacramentBusinessRuleException(
                'MARRIAGE_PREP_CASE_NOT_FOUND',
                'Marriage preparation case not found.',
                [],
                404
            );
        }

        return $case;
    }

    private function assertBccBelongsToTenant(int $tenantId, mixed $bccId): void
    {
        if ($bccId === null || $bccId === '') {
            return;
        }

        $exists = BCC::query()
            ->where('id', $bccId)
            ->where('tenant_id', $tenantId)
            ->exists();

        if (! $exists) {
            throw new SacramentBusinessRuleException(
                'MARRIAGE_PREP_INVALID_BCC',
                'BCC not found for this parish.'
            );
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function assertFamilyMembersBelongToTenant(int $tenantId, array $data): void
    {
        foreach (['bride_family_member_id', 'groom_family_member_id'] as $key) {
            if (empty($data[$key])) {
                continue;
            }

            $exists = FamilyMember::query()
                ->where('id', $data[$key])
                ->whereHas('family', fn ($query) => $query->where('tenant_id', $tenantId))
                ->exists();

            if (! $exists) {
                throw new SacramentBusinessRuleException(
                    'MARRIAGE_PREP_INVALID_MEMBER',
                    'Family member not found for this parish.'
                );
            }
        }
    }
}
