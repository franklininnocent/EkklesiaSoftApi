<?php

namespace Modules\Tenants\Services\Media;

final class ImageMediaStoreResult
{
    public function __construct(
        public readonly string $storageKey,
        public readonly string $thumbStorageKey,
        public readonly string $checksum,
        public readonly int $width,
        public readonly int $height,
        public readonly int $byteSize,
        public readonly bool $noOp = false,
    ) {}
}
