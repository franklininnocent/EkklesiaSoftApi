<?php

namespace Modules\Tenants\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Modules\Authentication\Models\User;
use Modules\EcclesiasticalData\Models\BishopManagement;
use Modules\EcclesiasticalData\Services\BishopFileUploadService;
use Modules\Tenants\Models\ChurchProfile;

/**
 * Church Profile Controller
 * 
 * Manages the extended church profile information for tenants.
 * Allows tenant administrators to configure ecclesiastical details.
 */
class ChurchProfileController extends Controller
{
    /**
     * Get the church profile for the authenticated tenant user.
     * 
     * @route GET /api/church-profile
     */
    public function show(): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user || app(\Modules\Tenants\Support\TenantContext::class)->effectiveTenantId() === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not associated with a tenant/church',
                ], 404);
            }

            // Get or create church profile
            $churchProfile = ChurchProfile::with([
                'denomination',
                'archdiocese.denomination',
                'bishop.archdiocese'
            ])->firstOrCreate(
                ['tenant_id' => app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId()],
                [
                    'founded_year' => null,
                    'country' => null,
                ]
            );

            // Generate full URL for patron image if exists
            $churchProfileData = $this->formatProfileData($churchProfile);

            return response()->json([
                'success' => true,
                'data' => $churchProfileData,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching church profile: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error fetching church profile',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Update the church profile for the authenticated tenant user.
     * Only tenant administrators can update.
     * 
     * @route PUT /api/church-profile
     */
    public function update(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user || app(\Modules\Tenants\Support\TenantContext::class)->effectiveTenantId() === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not associated with a tenant/church',
                ], 404);
            }

            if (!$this->canManageChurchSettings($user, 'edit')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only church administrators can update church profile.',
                ], 403);
            }

            // Validation rules
            $validated = $request->validate([
                'denomination_id' => 'nullable|exists:denominations,id',
                'archdiocese_id' => 'nullable|exists:archdioceses,id',
                'bishop_id' => 'nullable|exists:bishops,id',
                'founded_year' => 'nullable|integer|min:1000|max:' . (date('Y') + 1),
                'country' => 'nullable|string|max:100',
                'phone' => 'nullable|string|max:20',
                'email' => 'nullable|email|max:255',
                'website' => 'nullable|url|max:255',
                'about' => 'nullable|string|max:5000',
                'vision' => 'nullable|string|max:2000',
                'mission' => 'nullable|string|max:2000',
                'core_values' => 'nullable|string|max:2000',
                'service_times' => 'nullable|string|max:1000',
                'patron_name' => 'nullable|string|max:255',
                'patron_image_path' => 'nullable|string|max:255',
            ]);

            DB::beginTransaction();
            try {
                $churchProfile = ChurchProfile::updateOrCreate(
                    ['tenant_id' => app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId()],
                    $validated
                );

                DB::commit();

                // Reload relationships
                $churchProfile->load([
                    'denomination',
                    'archdiocese.denomination',
                    'bishop.archdiocese'
                ]);

                // Add patron_image_url to response
                $churchProfileData = $this->formatProfileData($churchProfile);

                Log::info('Church profile updated', [
                    'tenant_id' => app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId(),
                    'updated_by' => $user->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Church profile updated successfully',
                    'data' => $churchProfileData,
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error updating church profile: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error updating church profile',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Upload patron image for the church.
     * Tenant-specific: Each tenant can upload their own patron image.
     * 
     * @route POST /api/church-profile/upload-patron-image
     */
    public function uploadPatronImage(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user || app(\Modules\Tenants\Support\TenantContext::class)->effectiveTenantId() === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not associated with a tenant/church',
                ], 404);
            }

            if (!$this->canManageChurchSettings($user, 'edit')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only church administrators can upload patron image.',
                ], 403);
            }

            // Validate file upload
            $request->validate([
                'image' => 'required|image|mimes:jpeg,jpg,png,webp|max:3072', // 3MB max
            ]);

            $file = $request->file('image');
            
            if (!$file instanceof UploadedFile) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid file upload',
                ], 422);
            }

            DB::beginTransaction();
            try {
                // Get or create church profile
                $churchProfile = ChurchProfile::firstOrCreate(
                    ['tenant_id' => app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId()],
                    []
                );

                // Delete existing patron image if it exists
                if ($churchProfile->patron_image_path) {
                    $this->deletePatronImageFile($churchProfile->patron_image_path, app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId());
                }

                // Upload new image (tenant-specific)
                $imagePath = $this->uploadPatronImageFile($file, app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId());

                if (!$imagePath) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Failed to upload patron image',
                    ], 500);
                }

                // Update church profile with new image path
                $churchProfile->patron_image_path = $imagePath;
                $churchProfile->save();

                DB::commit();

                // Generate full URL
                $patronImageUrl = Storage::disk('public')->url($imagePath);

                Log::info('Patron image uploaded', [
                    'tenant_id' => app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId(),
                    'updated_by' => $user->id,
                    'image_path' => $imagePath,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Patron image uploaded successfully',
                    'data' => [
                        'patron_image_path' => $imagePath,
                        'patron_image_url' => $patronImageUrl,
                    ],
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error uploading patron image: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error uploading patron image',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Delete patron image.
     * 
     * @route DELETE /api/church-profile/patron-image
     */
    public function deletePatronImage(): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user || app(\Modules\Tenants\Support\TenantContext::class)->effectiveTenantId() === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not associated with a tenant/church',
                ], 404);
            }

            if (!$this->canManageChurchSettings($user, 'edit')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only church administrators can delete patron image.',
                ], 403);
            }

            DB::beginTransaction();
            try {
                $churchProfile = ChurchProfile::where('tenant_id', app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId())->first();

                if ($churchProfile && $churchProfile->patron_image_path) {
                    $this->deletePatronImageFile($churchProfile->patron_image_path, app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId());
                    $churchProfile->patron_image_path = null;
                    $churchProfile->save();
                }

                DB::commit();

                Log::info('Patron image deleted', [
                    'tenant_id' => app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId(),
                    'updated_by' => $user->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Patron image deleted successfully',
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('Error deleting patron image: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error deleting patron image',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Upload patron image to storage (tenant-specific path)
     */
    private function uploadPatronImageFile(UploadedFile $file, int $tenantId): ?string
    {
        try {
            // Generate secure filename
            $filename = $this->generatePatronImageFilename($file, $tenantId);
            
            // Store in tenant-specific directory
            $directory = "tenants/{$tenantId}/patron";
            
            // Store the original file
            $storedPath = Storage::disk('public')->putFileAs(
                $directory,
                $file,
                $filename,
                ['visibility' => 'public']
            );

            // Create thumbnails
            $this->createPatronThumbnails($storedPath);

            return $storedPath;
        } catch (\Exception $e) {
            Log::error('Error uploading patron image file: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate secure filename for patron image
     */
    private function generatePatronImageFilename(UploadedFile $file, int $tenantId): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
        
        if (!in_array($extension, $allowedExtensions)) {
            $extension = 'jpg'; // Default fallback
        }
        
        $timestamp = now()->format('YmdHis');
        $random = \Illuminate\Support\Str::random(16);
        $hash = substr(hash('sha256', $file->getClientOriginalName() . $timestamp), 0, 8);
        
        return "patron_t{$tenantId}_{$timestamp}_{$random}_{$hash}.{$extension}";
    }

    /**
     * Create thumbnails for patron image (128x128 and 300x300)
     */
    private function createPatronThumbnails(string $imagePath): void
    {
        try {
            $fullPath = Storage::disk('public')->path($imagePath);
            
            if (!file_exists($fullPath)) {
                return;
            }

            $pathInfo = pathinfo($imagePath);
            $directory = $pathInfo['dirname'];
            $filename = $pathInfo['filename'];
            $extension = $pathInfo['extension'];

            // Thumbnail sizes
            $sizes = [
                '128x128' => [128, 128],
                '300x300' => [300, 300],
            ];

            foreach ($sizes as $sizeName => $dimensions) {
                $thumbnailPath = "{$directory}/{$filename}_{$sizeName}.{$extension}";
                $thumbnailFullPath = Storage::disk('public')->path($thumbnailPath);

                // Use GD if available
                if (function_exists('imagecreatefromstring')) {
                    $imageInfo = getimagesize($fullPath);
                    if (!$imageInfo) {
                        continue;
                    }

                    [$width, $height] = $dimensions;
                    $srcImage = imagecreatefromstring(file_get_contents($fullPath));
                    
                    if ($srcImage !== false) {
                        $thumbnail = imagecreatetruecolor($width, $height);
                        
                        // Preserve transparency
                        imagealphablending($thumbnail, false);
                        imagesavealpha($thumbnail, true);
                        
                        // Resize maintaining aspect ratio
                        $srcWidth = imagesx($srcImage);
                        $srcHeight = imagesy($srcImage);
                        $ratio = min($width / $srcWidth, $height / $srcHeight);
                        $newWidth = (int)($srcWidth * $ratio);
                        $newHeight = (int)($srcHeight * $ratio);
                        
                        // Center the image
                        $x = (int)(($width - $newWidth) / 2);
                        $y = (int)(($height - $newHeight) / 2);
                        
                        imagecopyresampled(
                            $thumbnail, $srcImage,
                            $x, $y, 0, 0,
                            $newWidth, $newHeight,
                            $srcWidth, $srcHeight
                        );
                        
                        // Save based on extension
                        switch (strtolower($extension)) {
                            case 'png':
                                imagepng($thumbnail, $thumbnailFullPath, 9);
                                break;
                            case 'webp':
                                imagewebp($thumbnail, $thumbnailFullPath, 85);
                                break;
                            default:
                                imagejpeg($thumbnail, $thumbnailFullPath, 90);
                        }
                        
                        imagedestroy($srcImage);
                        imagedestroy($thumbnail);
                    }
                }
            }
        } catch (\Exception $e) {
            Log::warning('Failed to create thumbnails for patron image: ' . $e->getMessage());
            // Don't fail the upload if thumbnails fail
        }
    }

    /**
     * Delete patron image and thumbnails
     */
    private function deletePatronImageFile(string $path, int $tenantId): void
    {
        try {
            // Verify tenant ownership
            if (!str_contains($path, "tenants/{$tenantId}/patron")) {
                Log::warning('Attempted to delete patron image not owned by tenant', [
                    'path' => $path,
                    'tenant_id' => $tenantId,
                ]);
                return;
            }

            // Delete main image
            if (Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
            }

            // Delete thumbnails
            $pathInfo = pathinfo($path);
            $directory = $pathInfo['dirname'];
            $filename = $pathInfo['filename'];
            $extension = $pathInfo['extension'];

            $thumbnailSizes = ['128x128', '300x300'];
            foreach ($thumbnailSizes as $size) {
                $thumbnailPath = "{$directory}/{$filename}_{$size}.{$extension}";
                if (Storage::disk('public')->exists($thumbnailPath)) {
                    Storage::disk('public')->delete($thumbnailPath);
                }
            }
        } catch (\Exception $e) {
            Log::error('Error deleting patron image: ' . $e->getMessage());
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formatProfileData(ChurchProfile $churchProfile): array
    {
        $churchProfile->loadMissing([
            'denomination',
            'archdiocese.denomination',
            'bishop.archdiocese',
        ]);

        $data = $churchProfile->toArray();
        $data['patron_image_url'] = $churchProfile->patron_image_path
            ? Storage::disk('public')->url($churchProfile->patron_image_path)
            : null;

        $bishop = $churchProfile->resolvePresidingBishop();
        $data['bishop'] = $bishop ? $this->formatBishopForResponse($bishop) : null;

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatBishopForResponse(BishopManagement $bishop): array
    {
        $title = $bishop->ecclesiasticalTitle?->title ?? '';
        $uploadService = app(BishopFileUploadService::class);

        return array_merge($bishop->toArray(), [
            'title' => $title,
            'full_title' => trim($title.' '.$bishop->full_name),
            'photo_public_url' => $uploadService->resolvePhotoUrl($bishop->photo_path, $bishop->photo_url),
            'has_photo' => $uploadService->hasPhoto($bishop->photo_path, $bishop->photo_url),
        ]);
    }

    private function canManageChurchSettings(User $user, string $action): bool
    {
        if ($user->isTenantAdmin() || $user->is_primary_admin) {
            return true;
        }

        $actionPermission = "church.settings.{$action}";
        return $user->hasPermission($actionPermission) || $user->hasPermission('church.settings.edit');
    }
}
