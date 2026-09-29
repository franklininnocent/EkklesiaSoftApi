<?php

namespace Modules\Notifications\Services;

class NotificationDataSanitizer
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, scalar|null>
     */
    public function sanitize(array $data): array
    {
        $forbidden = array_map('strtolower', config('notifications.forbidden_data_keys', []));
        $clean = [];

        foreach ($data as $key => $value) {
            $lower = strtolower((string) $key);
            if (in_array($lower, $forbidden, true)) {
                continue;
            }
            if (is_scalar($value) || $value === null) {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }
}
