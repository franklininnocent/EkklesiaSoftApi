<?php

namespace Modules\Sacraments\Services\Certificates;

use Illuminate\Support\Facades\DB;
use Modules\Sacraments\Certificates\CertificateTemplateRegistry;
use Modules\Sacraments\Exceptions\SacramentBusinessRuleException;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentCertificate;
use Modules\Sacraments\Services\SacramentAuditService;
use Modules\Sacraments\Support\SacramentCertificateStatus;
use Modules\Sacraments\Support\SacramentStatus;
use Throwable;

/**
 * Preview / Generate / Reissue / Download orchestration (ADR-09 / ADR-10).
 * PDF generation runs after sacrament is already committed; PDF failure never rolls back the sacrament.
 */
class SacramentCertificateService
{
    public function __construct(
        protected CertificateProjectionBuilder $projections,
        protected CertificateTemplateRegistry $templates,
        protected CertificatePdfRenderer $pdf,
        protected CertificateStorage $storage,
        protected SacramentAuditService $audit
    ) {}

    /**
     * @return list<SacramentCertificate>
     */
    public function listForSacrament(int $sacramentId, int $tenantId): array
    {
        $sacrament = $this->findSacrament($sacramentId, $tenantId);

        return SacramentCertificate::query()
            ->where('tenant_id', $tenantId)
            ->where('sacrament_id', $sacrament->id)
            ->orderByDesc('version')
            ->get()
            ->all();
    }

    /**
     * Non-issued projection only (no PDF storage).
     *
     * @param  array{language?:string, locale?:string}  $options
     */
    public function preview(int $sacramentId, int $tenantId, ?int $actorId = null, array $options = []): SacramentCertificate
    {
        $sacrament = $this->assertIssuable($sacramentId, $tenantId);
        $language = $options['language'] ?? 'en';
        $locale = $options['locale'] ?? 'en_US';
        $projection = $this->projections->build($sacrament, $language, $locale);
        $template = $this->templates->forTypeCode((string) $sacrament->sacramentType->code);

        $cert = SacramentCertificate::create([
            'tenant_id' => $tenantId,
            'sacrament_id' => $sacrament->id,
            'certificate_number' => $sacrament->certificate_number,
            'certificate_type' => $template['template_code'],
            'status' => SacramentCertificateStatus::DRAFT_PREVIEW,
            'version' => $this->nextVersion($sacrament->id, $tenantId),
            'language' => $language,
            'locale' => $locale,
            'template_code' => $template['template_code'],
            'template_version' => $template['template_version'],
            'projection_json' => $projection,
            'issued_by' => $actorId,
        ]);

        $this->audit->log(
            $tenantId,
            'certificate_preview',
            (string) $cert->id,
            null,
            $this->certSnapshot($cert),
            ['sacrament_id' => $sacrament->id],
            'sacrament_certificate'
        );

        return $cert;
    }

    /**
     * First (or next) issued certificate. Sacrament must already be committed.
     *
     * @param  array{language?:string, locale?:string}  $options
     */
    public function generate(int $sacramentId, int $tenantId, ?int $actorId = null, array $options = []): SacramentCertificate
    {
        $sacrament = $this->assertIssuable($sacramentId, $tenantId);
        $language = $options['language'] ?? 'en';
        $locale = $options['locale'] ?? 'en_US';
        $projection = $this->projections->build($sacrament, $language, $locale);
        $template = $this->templates->forTypeCode((string) $sacrament->sacramentType->code);
        $version = $this->nextVersion($sacrament->id, $tenantId);

        $previousIssued = SacramentCertificate::query()
            ->where('tenant_id', $tenantId)
            ->where('sacrament_id', $sacrament->id)
            ->where('status', SacramentCertificateStatus::ISSUED)
            ->orderByDesc('version')
            ->first();

        $cert = DB::transaction(function () use (
            $tenantId, $sacrament, $template, $version, $language, $locale, $projection, $actorId, $previousIssued
        ) {
            if ($previousIssued) {
                $previousIssued->update(['status' => SacramentCertificateStatus::SUPERSEDED]);
            }

            return SacramentCertificate::create([
                'tenant_id' => $tenantId,
                'sacrament_id' => $sacrament->id,
                'certificate_number' => $sacrament->certificate_number,
                'certificate_type' => $template['template_code'],
                'status' => SacramentCertificateStatus::ISSUED,
                'version' => $version,
                'language' => $language,
                'locale' => $locale,
                'template_code' => $template['template_code'],
                'template_version' => $template['template_version'],
                'projection_json' => $projection,
                'issued_at' => now(),
                'issued_by' => $actorId,
                'supersedes_certificate_id' => $previousIssued?->id,
            ]);
        });

        // PDF after commit — failure must not roll back sacrament or issued row metadata.
        try {
            $pdfBytes = $this->pdf->render($projection);
            $stored = $this->storage->store($tenantId, $sacrament->id, $version, $pdfBytes);
            $cert->update($stored);
        } catch (Throwable $e) {
            $this->audit->log(
                $tenantId,
                'certificate_pdf_failed',
                (string) $cert->id,
                null,
                ['error' => $e->getMessage()],
                ['sacrament_id' => $sacrament->id],
                'sacrament_certificate'
            );
        }

        $cert = $cert->fresh();
        $this->audit->log(
            $tenantId,
            'certificate_generate',
            (string) $cert->id,
            $previousIssued ? $this->certSnapshot($previousIssued) : null,
            $this->certSnapshot($cert),
            ['sacrament_id' => $sacrament->id, 'version' => $cert->version],
            'sacrament_certificate'
        );

        return $cert;
    }

    /**
     * Reissue: new version from current sacrament snapshots; prior issued → superseded.
     *
     * @param  array{language?:string, locale?:string}  $options
     */
    public function reissue(int $certificateId, int $tenantId, ?int $actorId = null, array $options = []): SacramentCertificate
    {
        $existing = $this->findCertificate($certificateId, $tenantId);
        if ($existing->status === SacramentCertificateStatus::VOIDED) {
            throw new SacramentBusinessRuleException(
                'certificate_voided',
                'Cannot reissue a voided certificate.'
            );
        }

        return $this->generate((int) $existing->sacrament_id, $tenantId, $actorId, $options);
    }

    /**
     * @return array{certificate:SacramentCertificate, bytes:string, filename:string}
     */
    public function download(int $certificateId, int $tenantId): array
    {
        $cert = $this->findCertificate($certificateId, $tenantId);

        if ($cert->status === SacramentCertificateStatus::DRAFT_PREVIEW) {
            throw new SacramentBusinessRuleException(
                'certificate_not_issued',
                'Preview certificates cannot be downloaded. Generate an official copy first.'
            );
        }

        if (! $cert->storage_key) {
            // Best-effort: rebuild PDF from frozen projection (still no live member reads).
            if (! is_array($cert->projection_json)) {
                throw new SacramentBusinessRuleException(
                    'certificate_file_missing',
                    'Certificate file is not available.',
                    [],
                    404
                );
            }
            $pdfBytes = $this->pdf->render($cert->projection_json);
            $stored = $this->storage->store(
                $tenantId,
                (int) $cert->sacrament_id,
                (int) $cert->version,
                $pdfBytes
            );
            $cert->update($stored);
            $cert = $cert->fresh();
        }

        $bytes = $this->storage->get((string) $cert->storage_key);

        $this->audit->log(
            $tenantId,
            'certificate_download',
            (string) $cert->id,
            null,
            null,
            [
                'sacrament_id' => $cert->sacrament_id,
                'version' => $cert->version,
                'checksum' => $cert->checksum,
            ],
            'sacrament_certificate'
        );

        $filename = sprintf(
            'sacrament-certificate-%d-v%d.pdf',
            $cert->sacrament_id,
            $cert->version
        );

        return [
            'certificate' => $cert,
            'bytes' => $bytes,
            'filename' => $filename,
        ];
    }

    /**
     * After sacrament Correct: mark issued certificates superseded (ADR-10).
     * Does not auto-generate a new PDF — staff must Generate / Reissue explicitly.
     */
    public function supersedeIssuedForSacrament(int $sacramentId, int $tenantId, ?string $reason = null): int
    {
        $issued = SacramentCertificate::query()
            ->where('tenant_id', $tenantId)
            ->where('sacrament_id', $sacramentId)
            ->where('status', SacramentCertificateStatus::ISSUED)
            ->get();

        foreach ($issued as $cert) {
            $before = $this->certSnapshot($cert);
            $cert->update(['status' => SacramentCertificateStatus::SUPERSEDED]);
            $this->audit->log(
                $tenantId,
                'certificate_superseded',
                (string) $cert->id,
                $before,
                $this->certSnapshot($cert->fresh()),
                ['reason' => $reason, 'sacrament_id' => $sacramentId],
                'sacrament_certificate'
            );
        }

        return $issued->count();
    }

    private function assertIssuable(int $sacramentId, int $tenantId): Sacrament
    {
        $sacrament = $this->findSacrament($sacramentId, $tenantId);
        $sacrament->loadMissing(['sacramentType', 'participants']);

        if ($sacrament->status === SacramentStatus::VOIDED) {
            throw new SacramentBusinessRuleException(
                'sacrament_voided',
                'Cannot issue a certificate for a voided sacrament.'
            );
        }

        if ($sacrament->trashed()) {
            throw new SacramentBusinessRuleException(
                'sacrament_deleted',
                'Cannot issue a certificate for a deleted sacrament.'
            );
        }

        return $sacrament;
    }

    private function findSacrament(int $sacramentId, int $tenantId): Sacrament
    {
        $sacrament = Sacrament::withTrashed()->find($sacramentId);
        if (! $sacrament || (int) $sacrament->tenant_id !== $tenantId) {
            throw new SacramentBusinessRuleException('sacrament_not_found', 'Sacrament not found.', [], 404);
        }

        return $sacrament;
    }

    private function findCertificate(int $certificateId, int $tenantId): SacramentCertificate
    {
        $cert = SacramentCertificate::query()->find($certificateId);
        if (! $cert || (int) $cert->tenant_id !== $tenantId) {
            throw new SacramentBusinessRuleException(
                'certificate_not_found',
                'Certificate not found.',
                [],
                404
            );
        }

        return $cert;
    }

    private function nextVersion(int $sacramentId, int $tenantId): int
    {
        $max = (int) SacramentCertificate::query()
            ->where('tenant_id', $tenantId)
            ->where('sacrament_id', $sacramentId)
            ->max('version');

        return $max + 1;
    }

    /**
     * @return array<string, mixed>
     */
    private function certSnapshot(SacramentCertificate $cert): array
    {
        return $cert->only([
            'id', 'sacrament_id', 'status', 'version', 'template_code', 'template_version',
            'language', 'locale', 'checksum', 'storage_key', 'issued_at', 'supersedes_certificate_id',
        ]);
    }
}
