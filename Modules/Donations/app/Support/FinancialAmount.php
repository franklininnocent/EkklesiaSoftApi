<?php

namespace Modules\Donations\Support;

class FinancialAmount
{
    /**
     * @return array<int, string>
     */
    public static function required(): array
    {
        return ['required', 'numeric', 'min:0.01', 'max:999999999999.99', 'decimal:0,2'];
    }

    /**
     * @return array<int, string>
     */
    public static function optional(): array
    {
        return ['nullable', 'numeric', 'min:0', 'max:999999999999.99', 'decimal:0,2'];
    }
}
