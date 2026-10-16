<?php

namespace Modules\Sacraments\Support;

/**
 * Phase 2+ rollout flags for Sacraments.
 * Phase 9 cutover: participants_v1 defaults on (set SACRAMENTS_PARTICIPANTS_V1=false to roll back).
 */
final class SacramentFeatureFlags
{
    public static function participantsV1Enabled(): bool
    {
        return (bool) config('sacraments.participants_v1', true);
    }
}
