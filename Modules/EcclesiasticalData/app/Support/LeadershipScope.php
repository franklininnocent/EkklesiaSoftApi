<?php

namespace Modules\EcclesiasticalData\Support;

readonly class LeadershipScope
{
    public function __construct(
        public string $type,
        public ?int $id = null,
        public ?int $tenantId = null,
    ) {}

    public static function global(): self
    {
        return new self('global');
    }

    public static function diocese(int $dioceseId): self
    {
        return new self('diocese', $dioceseId);
    }

    public static function parish(int $churchProfileId, ?int $tenantId = null): self
    {
        return new self('parish', $churchProfileId, $tenantId);
    }

    /**
     * @return array{type: string, id: int|null, tenant_id: int|null}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
        ];
    }
}
