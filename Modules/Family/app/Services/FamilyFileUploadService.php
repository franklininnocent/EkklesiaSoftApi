<?php

namespace Modules\Family\app\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class FamilyFileUploadService
{
    /**
     * Upload family head profile image with optimization and security checks.
     * 
     * SECURITY: Stores in tenant-specific directory for data isolation
     *
     * @param UploadedFile $file
     * @param int $tenantId
     * @param string|null $existingPath
     * @return string|null Path to the uploaded file
     */
    public function uploadProfileImage(UploadedFile $file, int $tenantId, ?string $existingPath = null): ?string
    {
        try {
            // SECURITY: Validate tenant ID
            if ($tenantId <= 0) {
                Log::error('Invalid tenant ID for profile image upload', ['tenant_id' => $tenantId]);
                return null;
            }

            // Validate file
            $validation = $this->validateFile($file);
            if (!$validation['valid']) {
                Log::error('Profile image validation failed', [
                    'tenant_id' => $tenantId,
                    'error' => $validation['error']
                ]);
                return null;
            }

            // Delete existing image if it exists
            if ($existingPath) {
                $this->deleteProfileImage($existingPath, $tenantId);
            }

            // Generate unique filename with tenant prefix
            $filename = $this->generateSecureFilename($file, $tenantId, 'profile');
            
            // SECURITY: Store in tenant-specific directory
            $directory = "families/{$tenantId}/profiles";
            
            // Store the original file with proper permissions
            $storedPath = Storage::disk('public')->putFileAs(
                $directory,
                $file,
                $filename,
                ['visibility' => 'public']
            );

            // Optimize image if it's too large
            $this->optimizeImage($storedPath);

            // Log successful upload for audit
            Log::info('Family profile image uploaded successfully', [
                'tenant_id' => $tenantId,
                'filename' => $filename,
                'path' => $storedPath,
                'uploaded_by' => auth()->id() ?? 'system',
            ]);

            return $storedPath;
        } catch (\Exception $e) {
            Log::error('Profile image upload failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Delete family head profile image with ownership verification.
     * 
     * SECURITY: Verifies tenant ownership before deletion
     *
     * @param string $path
     * @param int $tenantId
     * @return bool
     */
    public function deleteProfileImage(string $path, int $tenantId): bool
    {
        try {
            // SECURITY: Verify path belongs to tenant
            if (!$this->verifyTenantOwnership($path, $tenantId)) {
                Log::warning('Attempted to delete file from different tenant', [
                    'path' => $path,
                    'tenant_id' => $tenantId,
                    'user_id' => auth()->id(),
                ]);
                return false;
            }

            if (Storage::disk('public')->exists($path)) {
                $deleted = Storage::disk('public')->delete($path);
                
                if ($deleted) {
                    Log::info('Family profile image deleted', [
                        'tenant_id' => $tenantId,
                        'path' => $path,
                        'deleted_by' => auth()->id() ?? 'system',
                    ]);
                }
                
                return $deleted;
            }
            return true;
        } catch (\Exception $e) {
            Log::error('Profile image deletion failed', [
                'tenant_id' => $tenantId,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get full URL for family profile image.
     *
     * @param string|null $path
     * @return string|null
     */
    public function getProfileImageUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

    /**
     * Generate secure filename with tenant isolation.
     * 
     * SECURITY: Includes tenant ID and sanitizes extension
     *
     * @param UploadedFile $file
     * @param int $tenantId
     * @param string $prefix
     * @return string
     */
    private function generateSecureFilename(UploadedFile $file, int $tenantId, string $prefix = 'file'): string
    {
        // Get and validate extension
        $extension = strtolower($file->getClientOriginalExtension());
        
        // Whitelist allowed extensions
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        if (!in_array($extension, $allowedExtensions)) {
            $extension = 'jpg'; // Default to jpg for unknown types
        }
        
        // Generate secure filename
        $timestamp = now()->format('YmdHis');
        $random = Str::random(16); // Longer random string for security
        $hash = substr(hash('sha256', $file->getClientOriginalName() . $timestamp), 0, 8);
        
        // Format: {prefix}_t{tenantId}_{timestamp}_{random}_{hash}.{ext}
        return "{$prefix}_t{$tenantId}_{$timestamp}_{$random}_{$hash}.{$extension}";
    }

    /**
     * Verify that a file path belongs to the specified tenant.
     * 
     * SECURITY: Prevents cross-tenant file access
     *
     * @param string $path
     * @param int $tenantId
     * @return bool
     */
    private function verifyTenantOwnership(string $path, int $tenantId): bool
    {
        // Check if path contains tenant ID in expected format
        $pattern = "/families\/{$tenantId}\//";
        
        if (preg_match($pattern, $path)) {
            return true;
        }
        
        return false;
    }

    /**
     * Optimize uploaded image (resize if too large, compress).
     *
     * @param string $path
     * @return void
     */
    private function optimizeImage(string $path): void
    {
        try {
            $fullPath = Storage::disk('public')->path($path);
            
            // Check if file exists
            if (!file_exists($fullPath)) {
                return;
            }

            // Get image dimensions
            list($width, $height) = getimagesize($fullPath);
            
            // Resize if larger than 800x800 pixels
            $maxDimension = 800;
            if ($width > $maxDimension || $height > $maxDimension) {
                $image = imagecreatefromstring(file_get_contents($fullPath));
                
                if ($image !== false) {
                    // Calculate new dimensions maintaining aspect ratio
                    $ratio = min($maxDimension / $width, $maxDimension / $height);
                    $newWidth = (int)($width * $ratio);
                    $newHeight = (int)($height * $ratio);
                    
                    // Create new image
                    $resized = imagecreatetruecolor($newWidth, $newHeight);
                    
                    // Preserve transparency for PNG and GIF
                    imagealphablending($resized, false);
                    imagesavealpha($resized, true);
                    
                    // Resize
                    imagecopyresampled(
                        $resized, $image,
                        0, 0, 0, 0,
                        $newWidth, $newHeight,
                        $width, $height
                    );
                    
                    // Save based on file type
                    $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
                    switch ($extension) {
                        case 'png':
                            imagepng($resized, $fullPath, 8); // Compression level 8
                            break;
                        case 'gif':
                            imagegif($resized, $fullPath);
                            break;
                        case 'webp':
                            imagewebp($resized, $fullPath, 80); // Quality 80
                            break;
                        default: // jpg, jpeg
                            imagejpeg($resized, $fullPath, 85); // Quality 85
                    }
                    
                    // Free memory
                    imagedestroy($image);
                    imagedestroy($resized);
                }
            }
        } catch (\Exception $e) {
            Log::warning('Image optimization failed: ' . $e->getMessage());
            // Continue without optimization if it fails
        }
    }

    /**
     * Validate file before upload (additional security check).
     *
     * @param UploadedFile $file
     * @return array ['valid' => bool, 'error' => string|null]
     */
    public function validateFile(UploadedFile $file): array
    {
        // Check file size (5MB max)
        if ($file->getSize() > 5 * 1024 * 1024) {
            return [
                'valid' => false,
                'error' => 'File size must not exceed 5MB'
            ];
        }

        // Check mime type
        $allowedMimeTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($file->getMimeType(), $allowedMimeTypes)) {
            return [
                'valid' => false,
                'error' => 'File must be a JPEG, PNG, GIF, or WebP image'
            ];
        }

        // Check if file is a valid image
        $imageInfo = @getimagesize($file->getRealPath());
        if ($imageInfo === false) {
            return [
                'valid' => false,
                'error' => 'File is not a valid image'
            ];
        }

        return ['valid' => true, 'error' => null];
    }
}

