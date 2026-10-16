<?php

namespace Modules\Sacraments\Services;

use Modules\Sacraments\Exceptions\SacramentBusinessRuleException;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentCanonicalAnnotation;
use Modules\Sacraments\Support\SacramentTypeCode;

class SacramentCanonicalAnnotationService
{
    /**
     * @return list<SacramentCanonicalAnnotation>
     */
    public function listForSacrament(int $sacramentId, int $tenantId): array
    {
        $sacrament = $this->requireSacrament($sacramentId, $tenantId);

        return $sacrament->canonicalAnnotations()->orderBy('id')->get()->all();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(int $sacramentId, int $tenantId, ?int $userId, array $data): SacramentCanonicalAnnotation
    {
        $sacrament = $this->requireSacrament($sacramentId, $tenantId);

        $annotation = SacramentCanonicalAnnotation::create([
            'tenant_id' => $tenantId,
            'sacrament_id' => $sacrament->id,
            'annotation_type' => $data['annotation_type'],
            'effective_date' => $data['effective_date'] ?? null,
            'granting_authority' => $data['granting_authority'] ?? null,
            'protocol_number' => $data['protocol_number'] ?? null,
            'notes' => $data['notes'] ?? null,
            'recorded_by_user_id' => $userId,
        ]);

        app(SacramentAuditService::class)->log(
            $tenantId,
            'canonical_annotation_create',
            (string) $sacrament->id,
            null,
            $annotation->toArray(),
            ['annotation_id' => $annotation->id],
            'sacrament_canonical_annotation'
        );

        return $annotation;
    }

    public function delete(int $sacramentId, int $annotationId, int $tenantId): void
    {
        $this->requireSacrament($sacramentId, $tenantId);
        $annotation = SacramentCanonicalAnnotation::query()
            ->where('tenant_id', $tenantId)
            ->where('sacrament_id', $sacramentId)
            ->whereKey($annotationId)
            ->first();

        if (! $annotation) {
            throw new SacramentBusinessRuleException(
                'annotation_not_found',
                'Canonical annotation not found.',
                [],
                404
            );
        }

        $before = $annotation->toArray();
        $annotation->delete();

        app(SacramentAuditService::class)->log(
            $tenantId,
            'canonical_annotation_delete',
            (string) $sacramentId,
            $before,
            null,
            ['annotation_id' => $annotationId],
            'sacrament_canonical_annotation'
        );
    }

    private function requireSacrament(int $sacramentId, int $tenantId): Sacrament
    {
        $sacrament = Sacrament::query()
            ->with('sacramentType')
            ->where('tenant_id', $tenantId)
            ->find($sacramentId);

        if (! $sacrament) {
            throw new SacramentBusinessRuleException(
                'sacrament_not_found',
                'Sacrament not found.',
                [],
                404
            );
        }

        if (! SacramentTypeCode::isMatrimony($sacrament->sacramentType?->code)) {
            throw new SacramentBusinessRuleException(
                'annotations_not_supported',
                'Canonical annotations are recorded on the marriage register only.'
            );
        }

        return $sacrament;
    }
}
