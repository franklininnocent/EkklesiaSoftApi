<?php

namespace Modules\Sacraments\Services;

use Modules\Sacraments\Exceptions\SacramentBusinessRuleException;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentIdempotencyKey;

class SacramentIdempotencyService
{
    /**
     * @return array{replay: bool, sacrament: ?Sacrament}
     *
     * @throws SacramentBusinessRuleException
     */
    public function begin(int $tenantId, ?string $idempotencyKey, string $requestHash): array
    {
        if ($idempotencyKey === null || trim($idempotencyKey) === '') {
            return ['replay' => false, 'sacrament' => null];
        }

        $key = trim($idempotencyKey);

        $existing = SacramentIdempotencyKey::query()
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $key)
            ->first();

        if ($existing) {
            if ($existing->request_hash && $existing->request_hash !== $requestHash) {
                throw new SacramentBusinessRuleException(
                    'idempotency_key_conflict',
                    'Idempotency-Key was reused with a different request body.',
                    ['idempotency_key' => $key],
                    409
                );
            }

            $sacrament = $existing->sacrament_id
                ? Sacrament::with(['sacramentType', 'participants', 'creator', 'updater'])->find($existing->sacrament_id)
                : null;

            return ['replay' => true, 'sacrament' => $sacrament];
        }

        return ['replay' => false, 'sacrament' => null];
    }

    public function store(int $tenantId, string $idempotencyKey, string $requestHash, int $sacramentId): void
    {
        SacramentIdempotencyKey::create([
            'tenant_id' => $tenantId,
            'idempotency_key' => trim($idempotencyKey),
            'request_hash' => $requestHash,
            'sacrament_id' => $sacramentId,
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function hashPayload(array $payload): string
    {
        // Stable hash — ignore client-only ack / header noise.
        unset($payload['acknowledge_duplicate_warning'], $payload['created_by'], $payload['updated_by']);
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
