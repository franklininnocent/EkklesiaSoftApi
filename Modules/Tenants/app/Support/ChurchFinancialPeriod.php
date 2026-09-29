<?php

namespace Modules\Tenants\Support;

/**
 * Resolved fiscal/financial year for a parish tenant at a reference business date.
 */
final class ChurchFinancialPeriod
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $start,
        public readonly string $end,
        public readonly int $startMonth,
        public readonly int $startDay,
    ) {}

    /**
     * @return array{key: string, label: string, start: string, end: string}
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'start' => $this->start,
            'end' => $this->end,
        ];
    }
}
