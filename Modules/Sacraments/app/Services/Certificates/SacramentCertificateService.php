<?php

namespace Modules\Sacraments\Services\Certificates;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sacraments\Certificates\CertificateTemplateRegistry;
use Modules\Sacraments\Certificates\CertificateViewAssembler;
use Modules\Sacraments\Exceptions\SacramentBusinessRuleException;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentAuditLog;
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
        protected CertificateRenderer $renderer,
        protected CertificateHtmlRenderer $html,
        protected CertificateStorage $storage,
        protected CertificateQrEncoder $qr,
        protected CertificateViewAssembler $views,
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
     * @return array{latest_certificate: ?SacramentCertificate, live_projection: ?array<string, mixed>, download_history: list<SacramentAuditLog>}
     */
    public function getCertificateViewDetails(int $sacramentId, int $tenantId): array
    {
        $sacrament = $this->findSacrament($sacramentId, $tenantId);
        $latest = $this->findLatestIssued($sacramentId, $tenantId);
        $liveProjection = null;

        if (! $latest) {
            try {
                $liveProjection = $this->projections->build($sacrament);
            } catch (SacramentBusinessRuleException) {
                $liveProjection = null;
            }
        }

        return [
            'latest_certificate' => $latest,
            'live_projection' => $liveProjection,
            'download_history' => $this->listDownloadHistory($sacramentId, $tenantId),
        ];
    }

    public function findLatestIssued(int $sacramentId, int $tenantId): ?SacramentCertificate
    {
        $this->findSacrament($sacramentId, $tenantId);

        return SacramentCertificate::query()
            ->where('tenant_id', $tenantId)
            ->where('sacrament_id', $sacramentId)
            ->where('status', SacramentCertificateStatus::ISSUED)
            ->orderByDesc('version')
            ->first();
    }

    /**
     * @return list<SacramentAuditLog>
     */
    public function listDownloadHistory(int $sacramentId, int $tenantId): array
    {
        $this->findSacrament($sacramentId, $tenantId);

        return SacramentAuditLog::query()
            ->where('tenant_id', $tenantId)
            ->where('event', 'certificate_download')
            ->where('target_type', 'sacrament_certificate')
            ->where('metadata->sacrament_id', $sacramentId)
            ->with(['actor.role'])
            ->orderByDesc('created_at')
            ->get()
            ->all();
    }

    /**
     * @param  array{language?:string, locale?:string, paper?:string}  $options
     */
    public function preview(int $sacramentId, int $tenantId, ?int $actorId = null, array $options = []): SacramentCertificate
    {
        $sacrament = $this->assertIssuable($sacramentId, $tenantId);
        $language = $options['language'] ?? 'en';
        $locale = $options['locale'] ?? 'en_US';
        $projection = $this->projections->build($sacrament, $language, $locale, $options);
        $template = $this->templates->forTypeCode((string) $sacrament->sacramentType->code);
        $projection = $this->stampTemplate($projection, $template);

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
     * @param  array{language?:string, locale?:string, paper?:string}  $options
     */
    public function generate(int $sacramentId, int $tenantId, ?int $actorId = null, array $options = []): SacramentCertificate
    {
        $sacrament = $this->assertIssuable($sacramentId, $tenantId);
        $language = $options['language'] ?? 'en';
        $locale = $options['locale'] ?? 'en_US';
        $projection = $this->projections->build($sacrament, $language, $locale, $options);
        $template = $this->templates->forTypeCode((string) $sacrament->sacramentType->code);
        $projection = $this->stampTemplate($projection, $template);
        $version = $this->nextVersion($sacrament->id, $tenantId);
        $token = $this->qr->mintToken();
        $projection['issued_at'] = now()->toIso8601String();
        $projection = $this->attachVerification($projection, $token);

        $previousIssued = SacramentCertificate::query()
            ->where('tenant_id', $tenantId)
            ->where('sacrament_id', $sacrament->id)
            ->where('status', SacramentCertificateStatus::ISSUED)
            ->orderByDesc('version')
            ->first();

        $cert = DB::transaction(function () use (
            $tenantId, $sacrament, $template, $version, $language, $locale, $projection, $actorId, $previousIssued, $token
        ) {
            if ($previousIssued) {
                $previousIssued->update([
                    'status' => SacramentCertificateStatus::SUPERSEDED,
                    'verification_revoked_at' => now(),
                ]);
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
                'verification_token' => $token,
            ]);
        });

        try {
            $artifacts = $this->renderer->renderIssueArtifacts($projection);
            $stored = $this->storage->store($tenantId, $sacrament->id, $version, (string) $artifacts['pdf']);
            $htmlKey = $this->storage->storeHtml($tenantId, $sacrament->id, $version, $artifacts['html']);
            $projection['render']['pdf_engine'] = $artifacts['pdf_engine'];
            $cert->update(array_merge($stored, [
                'html_storage_key' => $htmlKey,
                'projection_json' => $projection,
            ]));
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
     * @param  array{language?:string, locale?:string, paper?:string}  $options
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

    public function void(int $certificateId, int $tenantId, ?int $actorId = null): SacramentCertificate
    {
        $cert = $this->findCertificate($certificateId, $tenantId);
        if ($cert->status === SacramentCertificateStatus::VOIDED) {
            return $cert;
        }

        $before = $this->certSnapshot($cert);
        $cert->update([
            'status' => SacramentCertificateStatus::VOIDED,
            'verification_revoked_at' => now(),
        ]);
        $this->audit->log(
            $tenantId,
            'certificate_void',
            (string) $cert->id,
            $before,
            $this->certSnapshot($cert->fresh()),
            ['actor_id' => $actorId],
            'sacrament_certificate'
        );

        return $cert->fresh();
    }

    /**
     * Frozen HTML for print. Issued rows never re-read Tenant/FamilyMember.
     */
    public function printHtml(int $certificateId, int $tenantId): string
    {
        $cert = $this->findCertificate($certificateId, $tenantId);
        if (is_string($cert->html_storage_key) && $cert->html_storage_key !== '') {
            try {
                return $this->storage->get($cert->html_storage_key);
            } catch (Throwable) {
                // Rebuild from frozen projection below.
            }
        }

        $projection = is_array($cert->projection_json) ? $cert->projection_json : [];
        if ($projection === []) {
            throw new SacramentBusinessRuleException(
                'certificate_file_missing',
                'Certificate file is not available.',
                [],
                404
            );
        }

        $this->audit->log(
            $tenantId,
            'certificate_print',
            (string) $cert->id,
            null,
            null,
            ['sacrament_id' => $cert->sacrament_id],
            'sacrament_certificate'
        );

        return $this->html->render($projection);
    }

    /**
     * @return array<string, mixed>
     */
    public function publicVerify(string $token): array
    {
        $cert = SacramentCertificate::query()
            ->where('verification_token', $token)
            ->first();

        if (! $cert) {
            throw new SacramentBusinessRuleException(
                'certificate_not_found',
                'This certificate could not be verified.',
                [],
                404
            );
        }

        $projection = is_array($cert->projection_json) ? $cert->projection_json : [];
        $view = is_array($projection['certificate_view'] ?? null) ? $projection['certificate_view'] : [];
        $church = is_array($view['church'] ?? null) ? $view['church'] : [];
        $voided = $cert->status === SacramentCertificateStatus::VOIDED
            || $cert->verification_revoked_at !== null
            || $cert->status === SacramentCertificateStatus::SUPERSEDED;

        $this->audit->log(
            (int) $cert->tenant_id,
            'certificate_verify',
            (string) $cert->id,
            null,
            null,
            ['voided' => $voided],
            'sacrament_certificate'
        );

        $recipient = $view['recipientName']
            ?? ((string) ($view['groomName'] ?? '').' & '.(string) ($view['brideName'] ?? ''));

        return [
            'sacrament_title' => $view['certificateTitle'] ?? null,
            'recipient_name' => is_string($recipient) ? trim($recipient, ' &') : null,
            'date_of_event' => $view['dateOfEvent'] ?? ($projection['sacrament']['date_administered'] ?? null),
            'parish_name' => $church['name'] ?? ($projection['church']['name'] ?? null),
            'certificate_number' => $view['registry']['certificateNumber'] ?? $cert->certificate_number,
            'status' => $voided ? 'voided' : 'issued',
        ];
    }

    /**
     * @return array{certificate:SacramentCertificate, bytes:string, filename:string}
     */
    public function download(
        int $certificateId,
        int $tenantId,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): array {
        $cert = $this->findCertificate($certificateId, $tenantId);

        if ($cert->status === SacramentCertificateStatus::DRAFT_PREVIEW) {
            throw new SacramentBusinessRuleException(
                'certificate_not_issued',
                'Preview certificates cannot be downloaded. Generate an official copy first.'
            );
        }

        if (! $cert->storage_key) {
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
                'template_version' => $cert->template_version,
                'checksum' => $cert->checksum,
                'ip_address' => $ipAddress,
                'device_snapshot' => $userAgent !== null ? Str::limit($userAgent, 120) : null,
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

    public function supersedeIssuedForSacrament(int $sacramentId, int $tenantId, ?string $reason = null): int
    {
        $issued = SacramentCertificate::query()
            ->where('tenant_id', $tenantId)
            ->where('sacrament_id', $sacramentId)
            ->where('status', SacramentCertificateStatus::ISSUED)
            ->get();

        foreach ($issued as $cert) {
            $before = $this->certSnapshot($cert);
            $cert->update([
                'status' => SacramentCertificateStatus::SUPERSEDED,
                'verification_revoked_at' => now(),
            ]);
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
     * @param  array<string, mixed>  $projection
     * @param  array{template_code:string, template_version:string, title:string}  $template
     * @return array<string, mixed>
     */
    private function stampTemplate(array $projection, array $template): array
    {
        $render = is_array($projection['render'] ?? null) ? $projection['render'] : [];
        $render['template_code'] = $template['template_code'];
        $render['template_version'] = $template['template_version'];
        $projection['render'] = $render;
        $projection['certificate_view'] = $this->views->assemble($projection);

        return $projection;
    }

    /**
     * @param  array<string, mixed>  $projection
     * @return array<string, mixed>
     */
    private function attachVerification(array $projection, string $token): array
    {
        $url = $this->qr->verifyUrl($token);
        $projection['verification'] = [
            'url' => $url,
            'qr_data_uri' => $this->qr->dataUri($url),
        ];
        $projection['certificate_view'] = $this->views->assemble($projection);

        return $projection;
    }

    /**
     * @return array<string, mixed>
     */
    private function certSnapshot(SacramentCertificate $cert): array
    {
        return $cert->only([
            'id', 'sacrament_id', 'status', 'version', 'template_code', 'template_version',
            'language', 'locale', 'checksum', 'storage_key', 'html_storage_key', 'issued_at',
            'supersedes_certificate_id',
        ]);
    }
}
