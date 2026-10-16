<?php

namespace Modules\Tenants\LoadTesting;

final class LoadTestFixtureResult
{
    /**
     * @param  list<array{tenant_id: int, user_id: int, token: ?string, families: int, members: int}>  $tenants
     */
    public function __construct(
        public readonly string $tag,
        public readonly int $tenantCount,
        public readonly int $memberCount,
        public readonly int $familyCount,
        public readonly array $tenants,
        public readonly float $elapsedSeconds,
    ) {}
}
