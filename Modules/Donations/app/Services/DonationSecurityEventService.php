<?php

namespace Modules\Donations\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Models\DonationSecurityEvent;

class DonationSecurityEventService
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $event,
        ?int $tenantId,
        ?string $targetType = null,
        ?string $targetId = null,
        int $httpStatus = 403,
        array $metadata = []
    ): DonationSecurityEvent {
        $request = request();

        return DonationSecurityEvent::create([
            'tenant_id' => $tenantId,
            'actor_user_id' => Auth::id(),
            'event' => $event,
            'method' => $request instanceof Request ? $request->method() : null,
            'path' => $request instanceof Request ? substr($request->path(), 0, 255) : null,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'http_status' => $httpStatus,
            'request_id' => $this->requestId(),
            'metadata' => $metadata,
        ]);
    }

    public function requestId(): ?string
    {
        $request = request();
        if (! $request instanceof Request) {
            return null;
        }

        $header = trim((string) $request->header('X-Request-Id', ''));
        if ($header !== '') {
            return substr($header, 0, 64);
        }

        $idempotency = trim((string) $request->header('Idempotency-Key', ''));

        return $idempotency !== '' ? substr($idempotency, 0, 64) : null;
    }
}
