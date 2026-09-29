<?php

namespace Modules\Tenants\Services\Media;

use Illuminate\Support\Facades\Crypt;
use JsonException;

final class ImageMediaToken
{
    public const VARIANT_DISPLAY = 'display';

    public const VARIANT_THUMB = 'thumb';

    /** @var list<string> */
    public const VARIANTS = [
        self::VARIANT_DISPLAY,
        self::VARIANT_THUMB,
    ];

    public static function encode(int $tenantId, string $storageKey, string $variant, int $expiresAt): string
    {
        if (! in_array($variant, self::VARIANTS, true)) {
            throw new ImageMediaException(
                ImageMediaException::CODE_INVALID_PATH,
                'The image could not be served.'
            );
        }

        $payload = json_encode([
            't' => $tenantId,
            'k' => $storageKey,
            'v' => $variant,
            'e' => $expiresAt,
        ], JSON_THROW_ON_ERROR);

        return Crypt::encryptString($payload);
    }

    /**
     * @return array{t: int, k: string, v: string, e: int}
     */
    public static function decode(string $token): array
    {
        try {
            $decoded = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException|\Throwable) {
            throw new ImageMediaException(
                ImageMediaException::CODE_INVALID_PATH,
                'The image could not be served.'
            );
        }

        if (! is_array($decoded)
            || ! isset($decoded['t'], $decoded['k'], $decoded['v'], $decoded['e'])
            || ! is_int($decoded['t'])
            || ! is_string($decoded['k'])
            || ! is_string($decoded['v'])
            || ! is_int($decoded['e'])) {
            throw new ImageMediaException(
                ImageMediaException::CODE_INVALID_PATH,
                'The image could not be served.'
            );
        }

        if (! in_array($decoded['v'], self::VARIANTS, true)) {
            throw new ImageMediaException(
                ImageMediaException::CODE_INVALID_PATH,
                'The image could not be served.'
            );
        }

        return [
            't' => $decoded['t'],
            'k' => $decoded['k'],
            'v' => $decoded['v'],
            'e' => $decoded['e'],
        ];
    }
}
