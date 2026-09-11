<?php

namespace Modules\Tenants\Services\Media;

use Illuminate\Http\UploadedFile;

final class ImageMediaPolicy
{
    public const CATEGORY_USERS = 'users';

    public const CATEGORY_LOGOS = 'logos';

    public const CATEGORY_FAMILIES = 'families';

    public const CATEGORY_BISHOPS = 'bishops';

    public const CATEGORY_PATRON = 'patron';

    public const CATEGORY_LEADERSHIP = 'leadership';

    public const CATEGORY_POPE = 'pope';

    /** @var list<string> */
    public const PLATFORM_CATEGORIES = [
        self::CATEGORY_POPE,
        self::CATEGORY_BISHOPS,
    ];

    public function maxBytesForCategory(string $category): int
    {
        $categories = config('tenants.media.categories', []);

        return (int) ($categories[$category]['max_bytes'] ?? 3 * 1024 * 1024);
    }

    public function minEdgePx(): int
    {
        return (int) config('tenants.media.min_edge_px', 64);
    }

    public function maxEdgePx(): int
    {
        return (int) config('tenants.media.max_edge_px', 4096);
    }

    public function maxPixels(): int
    {
        return (int) config('tenants.media.max_pixels', 8_000_000);
    }

    public function displayMaxEdgePx(): int
    {
        return (int) config('tenants.media.display_max_edge_px', 800);
    }

    public function thumbMaxEdgePx(): int
    {
        return (int) config('tenants.media.thumb_max_edge_px', 128);
    }

    public function webpQuality(): int
    {
        return (int) config('tenants.media.webp_quality', 80);
    }

    /** @return list<string> */
    public function allowedMimes(): array
    {
        /** @var list<string> $mimes */
        $mimes = config('tenants.media.allowed_mimes', []);

        return $mimes;
    }

    /** @return list<string> */
    public function allowedExtensions(): array
    {
        /** @var list<string> $extensions */
        $extensions = config('tenants.media.allowed_extensions', []);

        return $extensions;
    }

    public function assertCategory(string $category): void
    {
        $categories = config('tenants.media.categories', []);

        if (! array_key_exists($category, $categories)) {
            throw new ImageMediaException(
                ImageMediaException::CODE_INVALID_PATH,
                'The selected image category is not supported.'
            );
        }
    }

    public function assertPreUpload(UploadedFile $file, string $category): void
    {
        $this->assertCategory($category);

        if ($file->getSize() <= 0) {
            throw new ImageMediaException(
                ImageMediaException::CODE_EMPTY,
                'The selected file is empty.'
            );
        }

        if ($file->getSize() > $this->maxBytesForCategory($category)) {
            throw new ImageMediaException(
                ImageMediaException::CODE_TOO_LARGE,
                'The selected file exceeds the maximum allowed size.'
            );
        }

        $extension = strtolower((string) $file->getClientOriginalExtension());
        if (! in_array($extension, $this->allowedExtensions(), true)) {
            throw new ImageMediaException(
                ImageMediaException::CODE_UNSUPPORTED,
                'The selected image format is not supported.'
            );
        }
    }

    public function assertDimensions(int $width, int $height): void
    {
        if ($width < $this->minEdgePx() || $height < $this->minEdgePx()) {
            throw new ImageMediaException(
                ImageMediaException::CODE_DIMENSIONS,
                'The image is too small.'
            );
        }

        if ($width > $this->maxEdgePx() || $height > $this->maxEdgePx()) {
            throw new ImageMediaException(
                ImageMediaException::CODE_DIMENSIONS,
                'The image dimensions are too large.'
            );
        }

        if (($width * $height) > $this->maxPixels()) {
            throw new ImageMediaException(
                ImageMediaException::CODE_DIMENSIONS,
                'The image dimensions are too large.'
            );
        }
    }
}
