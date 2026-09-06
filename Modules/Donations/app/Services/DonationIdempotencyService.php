<?php

namespace Modules\Donations\Services;

use Illuminate\Http\Request;
use Modules\Donations\Models\DonationIdempotencyRecord;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class DonationIdempotencyService
{
    /**
     * @return array{replay: bool, record: DonationIdempotencyRecord}|null
     */
    public function begin(int $tenantId, string $operation, Request $request): ?array
    {
        $key = $this->extractKey($request);
        if ($key === null) {
            return null;
        }

        $hash = $this->payloadHash($request);
        $existing = DonationIdempotencyRecord::query()
            ->where('tenant_id', $tenantId)
            ->where('operation', $operation)
            ->where('key', $key)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            if ($existing->payload_hash !== $hash) {
                throw new ConflictHttpException('Idempotency-Key was reused with a different request.');
            }

            return ['replay' => true, 'record' => $existing];
        }

        $record = DonationIdempotencyRecord::create([
            'tenant_id' => $tenantId,
            'operation' => $operation,
            'key' => $key,
            'payload_hash' => $hash,
            'http_status' => 0,
        ]);

        return ['replay' => false, 'record' => $record];
    }

    public function complete(
        DonationIdempotencyRecord $record,
        string $resourceType,
        string $resourceId,
        int $httpStatus,
        array $responseBody
    ): void {
        $record->resource_type = $resourceType;
        $record->resource_id = $resourceId;
        $record->http_status = $httpStatus;
        $record->response_body = $responseBody;
        $record->save();
    }

    public function extractKey(Request $request): ?string
    {
        $key = trim((string) $request->header('Idempotency-Key', ''));

        return $key === '' ? null : substr($key, 0, 80);
    }

    private function payloadHash(Request $request): string
    {
        $payload = $request->all();
        ksort($payload);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
