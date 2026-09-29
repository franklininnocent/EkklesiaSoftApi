<?php

namespace Modules\ApplicationAccess\Support;

use Modules\Tenants\Support\AuditPiiRedactor;

/**
 * Allowlisted metadata + PII redaction for Application Access events.
 */
final class ApplicationMetadataSanitizer
{
    /** @var list<string> */
    private const ALLOWED_KEYS = [
        'edge_provider',
        'edge_request_id',
        'edge_ray_id',
        'trace_id',
        'support_mode',
        'reason_code',
        'target_tenant_id',
        'source_tenant_id',
        'module',
        'feature',
        'status_bucket',
        'filter_count',
        'export_format',
        'masked_identifier',
        'auth_context',
        'target_user_id',
    ];

    public function __construct(
        private readonly AuditPiiRedactor $redactor,
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    public function sanitize(array $metadata): array
    {
        $allowlisted = [];

        foreach ($metadata as $key => $value) {
            if (! in_array((string) $key, self::ALLOWED_KEYS, true)) {
                continue;
            }

            $allowlisted[(string) $key] = $value;
        }

        return $this->redactor->redact($allowlisted);
    }
}
