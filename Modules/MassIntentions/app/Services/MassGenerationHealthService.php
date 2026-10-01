<?php

namespace Modules\MassIntentions\Services;

use Carbon\Carbon;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\MassIntentions\Models\MassGenerationCursor;
use Modules\MassIntentions\Models\MassSchedule;
use Modules\MassIntentions\Support\MassScheduleConstants;

final class MassGenerationHealthService
{
    /**
     * @return array{
     *   attention_required: bool,
     *   attention_reason: string|null,
     *   minimum_through_date: string,
     * }
     */
    public function assessForTenant(int $tenantId): array
    {
        $today = Carbon::parse(DonationBusinessDate::today($tenantId))->startOfDay();
        $minimumThrough = $today->copy()->addDays(MassScheduleConstants::GENERATION_ATTENTION_MIN_DAYS_AHEAD);
        $minimumThroughDate = $minimumThrough->toDateString();

        $hasActiveRegular = MassSchedule::query()
            ->where('tenant_id', $tenantId)
            ->where('kind', 'regular')
            ->where('status', 'active')
            ->exists();

        if (! $hasActiveRegular) {
            return [
                'attention_required' => false,
                'attention_reason' => null,
                'minimum_through_date' => $minimumThroughDate,
            ];
        }

        $cursor = MassGenerationCursor::query()->find($tenantId);

        if ($cursor?->last_error) {
            return [
                'attention_required' => true,
                'attention_reason' => 'last_error',
                'minimum_through_date' => $minimumThroughDate,
            ];
        }

        $through = $cursor?->last_generated_through;
        if ($through === null) {
            return [
                'attention_required' => true,
                'attention_reason' => 'never_generated',
                'minimum_through_date' => $minimumThroughDate,
            ];
        }

        if (Carbon::parse($through)->startOfDay()->lt($minimumThrough)) {
            return [
                'attention_required' => true,
                'attention_reason' => 'behind_horizon',
                'minimum_through_date' => $minimumThroughDate,
            ];
        }

        return [
            'attention_required' => false,
            'attention_reason' => null,
            'minimum_through_date' => $minimumThroughDate,
        ];
    }

    /**
     * @return list<array{tenant_id: int, reason: string, last_generated_through: string|null, last_error: string|null}>
     */
    public function tenantsRequiringAttention(): array
    {
        $rows = [];

        MassSchedule::query()
            ->where('kind', 'regular')
            ->where('status', 'active')
            ->select('tenant_id')
            ->distinct()
            ->orderBy('tenant_id')
            ->pluck('tenant_id')
            ->each(function ($tenantId) use (&$rows): void {
                $tenantId = (int) $tenantId;
                $assessment = $this->assessForTenant($tenantId);
                if (! $assessment['attention_required']) {
                    return;
                }

                $cursor = MassGenerationCursor::query()->find($tenantId);

                $rows[] = [
                    'tenant_id' => $tenantId,
                    'reason' => (string) $assessment['attention_reason'],
                    'last_generated_through' => $cursor?->last_generated_through?->format('Y-m-d'),
                    'last_error' => $cursor?->last_error,
                ];
            });

        return $rows;
    }
}
