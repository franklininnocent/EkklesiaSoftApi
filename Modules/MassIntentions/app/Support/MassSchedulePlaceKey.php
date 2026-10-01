<?php

namespace Modules\MassIntentions\Support;

final class MassSchedulePlaceKey
{
    public static function fromPlace(?string $place): string
    {
        return strtolower(trim((string) $place));
    }
}
