<?php

namespace Modules\MassIntentions\Services;

use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Models\MassIntentionRequest;
use Modules\MassIntentions\Models\MassIntentionSetting;

class MassIntentionCanonService
{
    public function __construct(
        private readonly MassIntentionSettingsService $settings,
    ) {
    }

    public function assertCollectiveAllowed(int $tenantId, bool $isCollective): void
    {
        if (! $isCollective) {
            return;
        }

        $settings = $this->settings->forTenant($tenantId);
        if (! $settings->provincial_collective_authorized) {
            throw ValidationException::withMessages([
                'is_collective' => 'Collective intentions need provincial authorization in Settings before you can accept one.',
            ]);
        }
    }

    /**
     * Canon 955 — apply the intention within one year of acceptance.
     */
    public function assertCelebrationWithinOneYear(MassIntentionRequest $request, MassCelebration $celebration): void
    {
        if ($request->accepted_at === null || $celebration->celebrated_on === null) {
            return;
        }

        $timezone = DonationBusinessDate::timezoneForTenant((int) $request->tenant_id);
        $accepted = $request->accepted_at->copy()->timezone($timezone)->startOfDay();
        $massDay = Carbon::parse($celebration->celebrated_on->toDateString(), $timezone)->startOfDay();
        $deadline = $accepted->copy()->addYear();

        if ($massDay->gt($deadline)) {
            throw ValidationException::withMessages([
                'celebration_id' => 'This Mass is more than one year after the intention was accepted. Pick an earlier Mass.',
            ]);
        }
    }

    /**
     * Canon 953 — donor blocked transfer to another parish.
     */
    public function assertTransferAllowed(MassIntentionRequest $request): void
    {
        if ($request->prohibit_transfer) {
            throw ValidationException::withMessages([
                'prohibit_transfer' => 'The donor asked that this intention not be transferred to another parish.',
            ]);
        }
    }

    /**
     * Canon 956 — collective intentions need explicit flag plus provincial authorization.
     */
    public function assertAcceptRules(int $tenantId, MassIntentionRequest $request): void
    {
        $this->assertCollectiveAllowed($tenantId, (bool) $request->is_collective);
    }
}
