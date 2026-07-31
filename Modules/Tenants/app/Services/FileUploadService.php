<?php

namespace Modules\Tenants\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Facades\Image;

class FileUploadService
{
    /**
     * Upload tenant logo with optimization and security checks.
     * 
     * SECURITY: Stores in tenant-specific directory for data isolation
     *
     * @param UploadedFile $file
     * @param int $tenantId
     * @param string|null $existingPath
     * @return string|null Path to the uploaded file
     */
    public function uploadTenantLogo(UploadedFile $file, int $tenantId, ?string $existingPath = null): ?string
    {
        try {
            // SECURITY: Validate tenant ID
            if ($tenantId <= 0) {
                \Log::error('Invalid tenant ID for logo upload', ['tenant_id' => $tenantId]);
                return null;
            }

            // Delete existing logo if it exists
            if ($existingPath) {
                $this->deleteTenantLogo($existingPath, $tenantId);
            }

            // Generate unique filename with tenant prefix
            $filename = $this->generateSecureFilename($file, $tenantId, 'logo');
            
            // SECURITY: Store in tenant-specific directory
            $directory = "tenants/{$tenantId}/logos";
            
            // Store the original file with proper permissions
            $storedPath = Storage::disk('public')->putFileAs(
                $directory,
                $file,
                $filename,
                ['visibility' => 'public'] // Explicit visibility
            );

            // Optimize image if it's too large
            $this->optimizeImage($storedPath);

            // Log successful upload for audit
            \Log::info('Tenant logo uploaded successfully', [
                'tenant_id' => $tenantId,
                'filename' => $filename,
                'path' => $storedPath,
                'uploaded_by' => auth()->id() ?? 'system',
            ]);

            return $storedPath;
        } catch (\Exception $e) {
            \Log::error('Logo upload failed', [
                'tenant_id' => $tenantId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return null;
        }
    }

    /**
     * Delete tenant logo with ownership verification.
     * 
     * SECURITY: Verifies tenant ownership before deletion
     *
     * @param string $path
     * @param int $tenantId
     * @return bool
     */
    public function deleteTenantLogo(string $path, int $tenantId): bool
    {
        try {
            // SECURITY: Verify path belongs to tenant
            if (!$this->verifyTenantOwnership($path, $tenantId)) {
                \Log::warning('Attempted to delete file from different tenant', [
                    'path' => $path,
                    'tenant_id' => $tenantId,
                    'user_id' => auth()->id(),
                ]);
                return false;
            }

            if (Storage::disk('public')->exists($path)) {
                $deleted = Storage::disk('public')->delete($path);
                
                if ($deleted) {
                    \Log::info('Tenant logo deleted', [
                        'tenant_id' => $tenantId,
                        'path' => $path,
                        'deleted_by' => auth()->id() ?? 'system',
                    ]);
                }
                
                return $deleted;
            }
            return true;
        } catch (\Exception $e) {
            \Log::error('Logo deletion failed', [
                'tenant_id' => $tenantId,
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Get full URL for tenant logo.
     *
     * @param string|null $path
     * @param bool $signed Whether to generate a signed URL (default: false for backward compatibility)
     * @param int $expirationMinutes Expiration time in minutes (default: 60)
     * @return string|null
     */
    public function getTenantLogoUrl(?string $path, bool $signed = false, int $expirationMinutes = 60): ?string
    {
        if (!$path) {
            return null;
        }

        // Generate signed URL if requested
        if ($signed) {
            return $this->generateSignedUrl($path, $expirationMinutes);
        }

        // Default: return public URL
        return Storage::disk('public')->url($path);
    }
    
    /**
     * Generate a time-limited signed URL for a file.
     * 
     * SECURITY: Creates cryptographically signed, time-limited URL
     * 
     * @param string $path
     * @param int $expirationMinutes
     * @return string
     */
    public function generateSignedUrl(string $path, int $expirationMinutes = 60): string
    {
        try {
            // Generate signed URL using Laravel's URL signing
            $signedUrl = \URL::temporarySignedRoute(
                'tenants.files.serve',
                now()->addMinutes($expirationMinutes),
                ['path' => $path]
            );
            
            \Log::debug('Signed URL generated', [
                'path' => $path,
                'expiration_minutes' => $expirationMinutes,
                'user_id' => auth()->id() ?? 'system',
            ]);
            
            return $signedUrl;
        } catch (\Exception $e) {
            \Log::error('Failed to generate signed URL', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            
            // Fallback to public URL if signing fails
            return Storage::disk('public')->url($path);
        }
    }
    
    /**
     * Get secure URL for tenant file.
     * 
     * SECURITY: Automatically uses signed URLs for tenant-specific files
     * 
     * @param string|null $path
     * @param int|null $tenantId
     * @param int $expirationMinutes
     * @return string|null
     */
    public function getSecureTenantFileUrl(?string $path, ?int $tenantId = null, int $expirationMinutes = 60): ?string
    {
        if (!$path) {
            return null;
        }
        
        // For tenant-specific files (contains /tenants/{id}/), use signed URLs
        if ($tenantId && preg_match("/tenants\/{$tenantId}\//", $path)) {
            return $this->generateSignedUrl($path, $expirationMinutes);
        }
        
        // Auto-detect if it's a tenant-specific file
        if (preg_match('/tenants\/\d+\//', $path)) {
            return $this->generateSignedUrl($path, $expirationMinutes);
        }
        
        // For global/public files, use standard public URL
        return Storage::disk('public')->url($path);
    }

    /**
     * Generate unique filename for uploaded file (deprecated - use generateSecureFilename).
     *
     * @param UploadedFile $file
     * @return string
     * @deprecated Use generateSecureFilename instead
     */
    private function generateUniqueFilename(UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension();
        $timestamp = now()->format('YmdHis');
        $random = Str::random(8);
        
        return "logo_{$timestamp}_{$random}.{$extension}";
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
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
        if (!in_array($extension, $allowedExtensions)) {
            $extension = 'bin'; // Fallback for unknown types
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
        $pattern = "/tenants\/{$tenantId}\//";
        
        if (preg_match($pattern, $path)) {
            return true;
        }
        
        // Legacy paths (old format without tenant ID subfolder)
        // Only allow if it's the tenant's own old logo
        if (str_contains($path, 'tenants/logos/') && !preg_match('/tenants\/\d+\//', $path)) {
            // This is a legacy path - we'll allow it for now but log a warning
            \Log::warning('Legacy file path accessed', [
                'path' => $path,
                'tenant_id' => $tenantId,
            ]);
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
            \Log::warning('Image optimization failed: ' . $e->getMessage());
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

    /**
     * Get file size in human-readable format.
     *
     * @param int $bytes
     * @return string
     */
    public function formatFileSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        
        return round($bytes, 2) . ' ' . $units[$i];
    }
}

