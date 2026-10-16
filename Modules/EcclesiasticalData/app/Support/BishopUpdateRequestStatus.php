<?php

namespace Modules\EcclesiasticalData\Support;

enum BishopUpdateRequestStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
    case Applied = 'applied';
    case Rejected = 'rejected';

    /**
     * @return list<string>
     */
    public static function inFlightStatuses(): array
    {
        return [
            self::Draft->value,
            self::Submitted->value,
            self::UnderReview->value,
            self::ChangesRequested->value,
        ];
    }

    /**
     * @return list<string>
     */
    public static function terminalStatuses(): array
    {
        return [
            self::Applied->value,
            self::Rejected->value,
        ];
    }

    public function isTerminal(): bool
    {
        return in_array($this->value, self::terminalStatuses(), true);
    }
}
