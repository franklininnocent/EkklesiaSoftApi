<?php

namespace Modules\EcclesiasticalData\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\EcclesiasticalData\Models\BishopManagement;

class BishopFileUploadService
{
    private const MAX_FILE_SIZE = 3 * 1024 * 1024;

    private const MAX_DIMENSION = 800;

    private const MIN_DIMENSION = 64;

    /** @var list<string> */
    private const ALLOWED_MIME_TYPES = [
        'image/jpeg',
        'image/jpg',
        'image/png',
        'image/webp',
    ];

    /** @var array<string, string> */
    private const MIME_TO_EXTENSION = [
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public function uploadPhoto(BishopManagement $bishop, UploadedFile $file): string
    {
        $this->assertValidImage($file);

        $this->deleteStoredFile($bishop->photo_path);

        $path = $this->storeFile($bishop, $file, 'photo');
        $this->optimizeImage($path);

        $bishop->update([
            'photo_path' => $path,
            'photo_url' => $this->publicUrl($path),
        ]);

        return $path;
    }

    public function uploadCoatOfArms(BishopManagement $bishop, UploadedFile $file): string
    {
        $this->assertValidImage($file);

        $this->deleteStoredFile($bishop->coat_of_arms_path);

        $path = $this->storeFile($bishop, $file, 'coat-of-arms');
        $this->optimizeImage($path);

        $bishop->update(['coat_of_arms_path' => $path]);

        return $path;
    }

    public function deletePhoto(BishopManagement $bishop): void
    {
        $this->deleteStoredFile($bishop->photo_path);
        $bishop->update(['photo_path' => null, 'photo_url' => null]);
    }

    public function deleteCoatOfArms(BishopManagement $bishop): void
    {
        $this->deleteStoredFile($bishop->coat_of_arms_path);
        $bishop->update(['coat_of_arms_path' => null]);
    }

    public function publicUrl(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

    public function resolvePhotoUrl(?string $photoPath, ?string $photoUrl = null): ?string
    {
        return $this->publicUrl($photoPath) ?? $photoUrl;
    }

    public function hasPhoto(?string $photoPath, ?string $photoUrl = null): bool
    {
        return $photoPath !== null || ($photoUrl !== null && $photoUrl !== '');
    }

    public function storePendingSuggestionPhoto(string $requestId, UploadedFile $file): string
    {
        $this->assertValidImage($file);

        $extension = $this->resolveExtension($file);
        $filename = sprintf('photo-%s.%s', Str::uuid()->toString(), $extension);
        $directory = 'ecclesiastical/bishop-update-requests/'.$requestId;

        $path = Storage::disk('public')->putFileAs(
            $directory,
            $file,
            $filename,
            ['visibility' => 'public']
        );

        $this->optimizeImage($path);

        return $path;
    }

    public function applyPendingPhotoToBishop(BishopManagement $bishop, string $pendingPath): string
    {
        if (! Storage::disk('public')->exists($pendingPath)) {
            throw new InvalidArgumentException('Suggested bishop photo is no longer available.');
        }

        $this->deleteStoredFile($bishop->photo_path);

        $extension = strtolower((string) pathinfo($pendingPath, PATHINFO_EXTENSION)) ?: 'jpg';
        $filename = sprintf('photo-%s.%s', Str::uuid()->toString(), $extension);
        $directory = "ecclesiastical/bishops/{$bishop->id}";
        $destination = $directory.'/'.$filename;

        Storage::disk('public')->copy($pendingPath, $destination);

        $bishop->update([
            'photo_path' => $destination,
            'photo_url' => $this->publicUrl($destination),
        ]);

        return $destination;
    }

    public function deleteStoredPath(?string $path): void
    {
        $this->deleteStoredFile($path);
    }

    /**
     * @return array{valid: bool, error: string|null}
     */
    public function validateFile(UploadedFile $file): array
    {
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            return [
                'valid' => false,
                'error' => 'File size must not exceed 3MB',
            ];
        }

        $mimeType = strtolower((string) $file->getMimeType());
        if (! in_array($mimeType, self::ALLOWED_MIME_TYPES, true)) {
            return [
                'valid' => false,
                'error' => 'File must be a JPEG, PNG, or WebP image',
            ];
        }

        $imageInfo = @getimagesize($file->getRealPath());
        if ($imageInfo === false) {
            return [
                'valid' => false,
                'error' => 'File is not a valid image',
            ];
        }

        [$width, $height] = $imageInfo;
        if ($width < self::MIN_DIMENSION || $height < self::MIN_DIMENSION) {
            return [
                'valid' => false,
                'error' => 'Image must be at least '.self::MIN_DIMENSION.'x'.self::MIN_DIMENSION.' pixels',
            ];
        }

        return ['valid' => true, 'error' => null];
    }

    private function assertValidImage(UploadedFile $file): void
    {
        $validation = $this->validateFile($file);
        if (! $validation['valid']) {
            throw new InvalidArgumentException($validation['error'] ?? 'Invalid image file');
        }
    }

    private function storeFile(BishopManagement $bishop, UploadedFile $file, string $type): string
    {
        $extension = $this->resolveExtension($file);
        $filename = sprintf('%s-%s.%s', $type, Str::uuid()->toString(), $extension);
        $directory = "ecclesiastical/bishops/{$bishop->id}";

        return Storage::disk('public')->putFileAs(
            $directory,
            $file,
            $filename,
            ['visibility' => 'public']
        );
    }

    private function resolveExtension(UploadedFile $file): string
    {
        $mimeType = strtolower((string) $file->getMimeType());

        return self::MIME_TO_EXTENSION[$mimeType] ?? 'jpg';
    }

    private function deleteStoredFile(?string $path): void
    {
        if ($path && Storage::disk('public')->exists($path)) {
            Storage::disk('public')->delete($path);
        }
    }

    private function optimizeImage(string $path): void
    {
        try {
            $fullPath = Storage::disk('public')->path($path);
            if (! is_file($fullPath)) {
                return;
            }

            $contents = file_get_contents($fullPath);
            if ($contents === false) {
                return;
            }

            $image = @imagecreatefromstring($contents);
            if ($image === false) {
                throw new InvalidArgumentException('File is not a valid image');
            }

            $image = $this->applyExifOrientation($image, $fullPath);

            $width = imagesx($image);
            $height = imagesy($image);

            if ($width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION) {
                $ratio = min(self::MAX_DIMENSION / $width, self::MAX_DIMENSION / $height);
                $newWidth = max(1, (int) round($width * $ratio));
                $newHeight = max(1, (int) round($height * $ratio));

                $resized = imagecreatetruecolor($newWidth, $newHeight);
                imagealphablending($resized, false);
                imagesavealpha($resized, true);
                imagecopyresampled(
                    $resized,
                    $image,
                    0,
                    0,
                    0,
                    0,
                    $newWidth,
                    $newHeight,
                    $width,
                    $height
                );
                imagedestroy($image);
                $image = $resized;
            }

            $this->saveImageResource($image, $fullPath);
            imagedestroy($image);
        } catch (\Throwable $e) {
            Log::warning('Bishop image optimization failed', [
                'path' => basename($path),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param \GdImage|resource $image
     * @return \GdImage|resource
     */
    private function applyExifOrientation($image, string $fullPath)
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        if (! in_array($extension, ['jpg', 'jpeg'], true)) {
            return $image;
        }

        $exif = @exif_read_data($fullPath);
        if (! is_array($exif) || ! isset($exif['Orientation'])) {
            return $image;
        }

        return match ((int) $exif['Orientation']) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };
    }

    /**
     * @param \GdImage|resource $image
     */
    private function saveImageResource($image, string $fullPath): void
    {
        $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));

        switch ($extension) {
            case 'png':
                imagepng($image, $fullPath, 8);
                break;
            case 'webp':
                imagewebp($image, $fullPath, 80);
                break;
            default:
                imagejpeg($image, $fullPath, 85);
        }
    }
}
