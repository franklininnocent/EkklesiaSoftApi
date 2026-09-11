<?php

namespace Modules\Tenants\Services\Media;

use RuntimeException;

final class ImageMediaException extends RuntimeException
{
    public const CODE_EMPTY = 'empty_file';

    public const CODE_TOO_LARGE = 'file_too_large';

    public const CODE_UNSUPPORTED = 'unsupported_format';

    public const CODE_MIME_MISMATCH = 'mime_mismatch';

    public const CODE_INVALID_IMAGE = 'invalid_image';

    public const CODE_DIMENSIONS = 'invalid_dimensions';

    public const CODE_PROCESSING = 'processing_failed';

    public const CODE_STORAGE = 'storage_failed';

    public const CODE_INVALID_PATH = 'invalid_path';

    public function __construct(
        private readonly string $errorCode,
        string $publicMessage,
    ) {
        parent::__construct($publicMessage);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function publicMessage(): string
    {
        return $this->getMessage();
    }
}
