<?php

namespace Modules\Sacraments\Repositories;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Support\MarriageCanonicalClassification;
use Modules\Sacraments\Support\MarriageRegisterFilter;
use Modules\Sacraments\Support\SacramentAnnotationType;
use Modules\Sacraments\Support\SacramentTypeCode;

class SacramentRepository
{
    public function __construct(protected Sacrament $model) {}

    public function getPaginated(array $params = []): LengthAwarePaginator
    {
        $query = $this->model->newQuery()->with(['sacramentType', 'participants']);

        if (! empty($params['tenant_id'])) {
            $query->forTenant((int) $params['tenant_id']);
        }

        if (! empty($params['sacrament_type_id'])) {
            $query->bySacramentType((int) $params['sacrament_type_id']);
        }

        if (! empty($params['status'])) {
            $query->byStatus($params['status']);
        }

        if (! empty($params['search'])) {
            $query->searchRecipient($params['search']);
        }

        if (! empty($params['date_from']) && ! empty($params['date_to'])) {
            $query->dateRange($params['date_from'], $params['date_to']);
        } elseif (! empty($params['date_from'])) {
            $query->where('date_administered', '>=', $params['date_from']);
        } elseif (! empty($params['date_to'])) {
            $query->where('date_administered', '<=', $params['date_to']);
        }

        if (! empty($params['minister_name'])) {
            $query->byMinisterName($params['minister_name']);
        }

        if (! empty($params['certificate_number'])) {
            $query->byCertificateNumber($params['certificate_number']);
        }

        if (! empty($params['book_number'])) {
            $query->byBookNumber($params['book_number']);
        }

        if (! empty($params['family_id'])) {
            $query->byFamily($params['family_id']);
        }

        if (! empty($params['family_member_id'])) {
            $personId = $params['linked_person_id'] ?? null;
            $query->linkedToFamilyMember((string) $params['family_member_id'], $personId ? (string) $personId : null);
        }

        if (! empty($params['bcc_id'])) {
            $query->byBCC($params['bcc_id']);
        }

        if (! empty($params['event_subtype'])) {
            $query->where('event_subtype', $params['event_subtype']);
        }

        if (! empty($params['marriage_register_filter'])) {
            $this->applyMarriageRegisterFilter($query, (string) $params['marriage_register_filter']);
        }

        // ADR-22 / ADR-23: hide restricted types unless caller opted in.
        if (! empty($params['exclude_restricted']) && ! empty($params['restricted_type_codes'])) {
            $codes = [];
            foreach ((array) $params['restricted_type_codes'] as $code) {
                $normalized = SacramentTypeCode::normalize((string) $code) ?? strtoupper((string) $code);
                $codes[] = $normalized;
                if ($normalized === SacramentTypeCode::RECONCILIATION) {
                    $codes = array_merge($codes, ['RECONCILIATION', 'CONFESSION', 'PENANCE']);
                }
            }
            $codes = array_values(array_unique(array_map('strtoupper', $codes)));
            if ($codes !== []) {
                $query->whereHas('sacramentType', function ($q) use ($codes) {
                    $q->whereRaw('UPPER(code) NOT IN ('.implode(',', array_fill(0, count($codes), '?')).')', $codes);
                });
            }
        }

        $perPage = $params['per_page'] ?? 20;
        $sortBy = $params['sort_by'] ?? 'date_administered';
        $sortDir = $params['sort_dir'] ?? 'desc';

        $query->whereNull('deleted_at');

        return $query->orderBy($sortBy, $sortDir)->paginate($perPage);
    }

    private function applyMarriageRegisterFilter($query, string $filter): void
    {
        $normalized = MarriageRegisterFilter::normalize($filter);
        if ($normalized === null) {
            return;
        }

        match ($normalized) {
            MarriageRegisterFilter::CATHOLIC_BOTH => $query->where(
                'marriage_canonical_classification',
                MarriageCanonicalClassification::BOTH_CATHOLIC
            ),
            MarriageRegisterFilter::MIXED_DISPARITY => $query->whereIn('marriage_canonical_classification', [
                MarriageCanonicalClassification::MIXED_MARRIAGE,
                MarriageCanonicalClassification::DISPARITY_OF_CULT,
            ]),
            MarriageRegisterFilter::CONVALIDATIONS => $query->whereHas(
                'canonicalAnnotations',
                fn ($annotationQuery) => $annotationQuery
                    ->where('annotation_type', SacramentAnnotationType::CONVALIDATION)
                    ->whereNull('deleted_at')
            ),
            MarriageRegisterFilter::PROFILE_LINKED => $query
                ->whereHas(
                    'participants',
                    fn ($participantQuery) => $participantQuery
                        ->where('role', 'bride')
                        ->whereNotNull('family_member_id')
                        ->whereNull('deleted_at')
                )
                ->whereHas(
                    'participants',
                    fn ($participantQuery) => $participantQuery
                        ->where('role', 'groom')
                        ->whereNotNull('family_member_id')
                        ->whereNull('deleted_at')
                ),
            MarriageRegisterFilter::SAME_PARISH => $this->applyMarriageParishOriginFilter($query, sameParish: true),
            MarriageRegisterFilter::INTER_PARISH => $this->applyMarriageParishOriginFilter($query, sameParish: false),
            default => null,
        };
    }

    private function applyMarriageParishOriginFilter($query, bool $sameParish): void
    {
        $brideParish = $this->resolvedMarriageParishSql('bride', 'marriage_bride_church_name');
        $groomParish = $this->resolvedMarriageParishSql('groom', 'marriage_groom_church_name');

        $query->whereRaw("{$brideParish} IS NOT NULL")
            ->whereRaw("{$groomParish} IS NOT NULL");

        if ($sameParish) {
            $query->whereRaw("LOWER({$brideParish}) = LOWER({$groomParish})");
        } else {
            $query->whereRaw("LOWER({$brideParish}) <> LOWER({$groomParish})");
        }
    }

    private function resolvedMarriageParishSql(string $role, string $fallbackColumn): string
    {
        return "COALESCE(
            (SELECT NULLIF(TRIM(sp.affiliation_parish_name), '')
             FROM sacrament_participants sp
             WHERE sp.sacrament_id = sacraments.id
               AND sp.role = '{$role}'
               AND sp.deleted_at IS NULL
             ORDER BY sp.id
             LIMIT 1),
            NULLIF(TRIM(sacraments.{$fallbackColumn}), '')
        )";
    }

    public function create(array $data): Sacrament
    {
        return $this->model->create($data);
    }

    public function update(Sacrament $sacrament, array $data): Sacrament
    {
        $sacrament->update($data);

        return $sacrament->fresh(['sacramentType', 'participants', 'creator', 'updater']);
    }

    public function delete(Sacrament $sacrament): bool
    {
        return (bool) $sacrament->delete();
    }

    public function findById(int $id): ?Sacrament
    {
        return $this->model->with([
            'sacramentType',
            'tenant',
            'creator',
            'updater',
            'participants',
            'person',
            'dispensations',
            'canonicalAnnotations',
        ])
            ->find($id);
    }

    public function findByIdForTenant(int $id, int $tenantId): ?Sacrament
    {
        return $this->model->with([
            'sacramentType',
            'tenant',
            'creator',
            'updater',
            'participants',
            'person',
            'dispensations',
            'canonicalAnnotations',
        ])
            ->forTenant($tenantId)
            ->find($id);
    }

    public function findByIds(array $ids, ?int $tenantId = null)
    {
        $query = $this->model->whereIn('id', $ids);

        if ($tenantId !== null) {
            $query->forTenant($tenantId);
        }

        return $query->get();
    }
}
