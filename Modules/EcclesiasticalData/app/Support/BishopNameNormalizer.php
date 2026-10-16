<?php

namespace Modules\EcclesiasticalData\Support;

class BishopNameNormalizer
{
    public function normalize(?string $name): ?string
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        $normalized = preg_replace('/[^a-z0-9]+/i', ' ', strtolower(trim($name)));
        $normalized = trim(preg_replace('/\s+/', ' ', (string) $normalized));

        $prefixes = [
            'most rev',
            'most reverend',
            'his excellency',
            'her excellency',
            'archbishop',
            'bishop',
            'rev',
            'reverend',
        ];

        foreach ($prefixes as $prefix) {
            if (str_starts_with($normalized, $prefix.' ')) {
                $normalized = trim(substr($normalized, strlen($prefix)));
            }
        }

        return $normalized !== '' ? $normalized : null;
    }
}
