<?php

namespace Modules\Tenants\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Modules\Tenants\Models\PopeDetails;
use Illuminate\Http\UploadedFile;

/**
 * Pope Details Controller
 * 
 * Manages global Pope image and details.
 * Requires manage_pope_details permission.
 * Pope data is global (not tenant-specific).
 */
class PopeDetailsController extends Controller
{
    /**
     * Get global pope details.
     * 
     * @route GET /api/church-profile/pope
     */
    public function show(): JsonResponse
    {
        try {
            $popeDetails = PopeDetails::getCurrent();

            if (!$popeDetails) {
                return response()->json([
                    'success' => true,
                    'data' => [
                        'pope_name' => null,
                        'pope_image_path' => null,
                        'pope_image_url' => null,
                        'pope_title' => null,
                        'pope_effective_from' => null,
                    ],
                ]);
            }

            // Generate full URL for pope image if exists
            $popeImageUrl = null;
            if ($popeDetails->pope_image_path) {
                $popeImageUrl = $this->getPopeImageUrl($popeDetails->pope_image_path);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'pope_name' => $popeDetails->pope_name,
                    'pope_image_path' => $popeDetails->pope_image_path,
                    'pope_image_url' => $popeImageUrl,
                    'pope_title' => $popeDetails->pope_title,
                    'pope_effective_from' => $popeDetails->pope_effective_from?->format('Y-m-d'),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching pope details: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error fetching pope details',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Update global pope details.
     * Requires manage_pope_details permission.
     * 
     * @route PUT /api/church-profile/pope
     */
    public function update(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            // Check permission (try both formats for compatibility)
            $hasPermission = $user->hasPermission('pope.manage_pope_details') || 
                            $user->hasPermission('manage_pope_details');
            
            // Also allow SuperAdmin and EkklesiaAdmin roles
            $isEkklesiaAdmin = $user->isSuperAdmin() || $user->isEkklesiaAdmin();
            
            if (!$hasPermission && !$isEkklesiaAdmin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You do not have permission to manage pope details.',
                ], 403);
            }

            // Validation rules
            $validated = $request->validate([
                'pope_name' => 'required|string|max:255',
                'pope_title' => 'nullable|string|max:100',
                'pope_effective_from' => 'nullable|date',
            ]);

            DB::beginTransaction();
            try {
                // Get or create the global pope details record
                $popeDetails = PopeDetails::getCurrent();
                
                if (!$popeDetails) {
                    $popeDetails = new PopeDetails();
                    $popeDetails->created_by = $user->id;
                }

                $popeDetails->pope_name = $validated['pope_name'];
                $popeDetails->pope_title = $validated['pope_title'] ?? null;
                $popeDetails->pope_effective_from = $validated['pope_effective_from'] ?? null;
                $popeDetails->updated_by = $user->id;
                $popeDetails->save();

                DB::commit();

                // Generate full URL for pope image if exists
                $popeImageUrl = null;
                if ($popeDetails->pope_image_path) {
                    $popeImageUrl = $this->getPopeImageUrl($popeDetails->pope_image_path);
                }

                Log::info('Pope details updated', [
                    'updated_by' => $user->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Pope details updated successfully',
                    'data' => [
                        'pope_name' => $popeDetails->pope_name,
                        'pope_image_path' => $popeDetails->pope_image_path,
                        'pope_image_url' => $popeImageUrl,
                        'pope_title' => $popeDetails->pope_title,
                        'pope_effective_from' => $popeDetails->pope_effective_from?->format('Y-m-d'),
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
            Log::error('Error updating pope details: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error updating pope details',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Upload pope image.
     * Requires manage_pope_details permission.
     * 
     * @route POST /api/church-profile/pope/upload-image
     */
    public function uploadImage(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            // Check permission (try both formats for compatibility)
            $hasPermission = $user->hasPermission('pope.manage_pope_details') || 
                            $user->hasPermission('manage_pope_details');
            
            // Also allow SuperAdmin and EkklesiaAdmin roles
            $isEkklesiaAdmin = $user->isSuperAdmin() || $user->isEkklesiaAdmin();
            
            if (!$hasPermission && !$isEkklesiaAdmin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You do not have permission to manage pope details.',
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
                // Get or create the global pope details record
                $popeDetails = PopeDetails::getCurrent();
                
                if (!$popeDetails) {
                    $popeDetails = new PopeDetails();
                    $popeDetails->created_by = $user->id;
                }

                // Delete existing pope image if it exists
                if ($popeDetails->pope_image_path) {
                    $this->deletePopeImage($popeDetails->pope_image_path);
                }

                // Upload new image (global storage, not tenant-specific)
                $imagePath = $this->uploadPopeImage($file);

                if (!$imagePath) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Failed to upload pope image',
                    ], 500);
                }

                // Update pope details with new image path
                $popeDetails->pope_image_path = $imagePath;
                $popeDetails->updated_by = $user->id;
                $popeDetails->save();

                DB::commit();

                // Generate full URL
                $popeImageUrl = $this->getPopeImageUrl($imagePath);

                Log::info('Pope image uploaded', [
                    'updated_by' => $user->id,
                    'image_path' => $imagePath,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Pope image uploaded successfully',
                    'data' => [
                        'pope_image_path' => $imagePath,
                        'pope_image_url' => $popeImageUrl,
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
            Log::error('Error uploading pope image: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error uploading pope image',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Delete pope image.
     * Requires manage_pope_details permission.
     * 
     * @route DELETE /api/church-profile/pope/image
     */
    public function deleteImage(): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated',
                ], 401);
            }

            // Check permission (try both formats for compatibility)
            $hasPermission = $user->hasPermission('pope.manage_pope_details') || 
                            $user->hasPermission('manage_pope_details');
            
            // Also allow SuperAdmin and EkklesiaAdmin roles
            $isEkklesiaAdmin = $user->isSuperAdmin() || $user->isEkklesiaAdmin();
            
            if (!$hasPermission && !$isEkklesiaAdmin) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You do not have permission to manage pope details.',
                ], 403);
            }

            DB::beginTransaction();
            try {
                $popeDetails = PopeDetails::getCurrent();

                if ($popeDetails && $popeDetails->pope_image_path) {
                    $this->deletePopeImage($popeDetails->pope_image_path);
                    $popeDetails->pope_image_path = null;
                    $popeDetails->updated_by = $user->id;
                    $popeDetails->save();
                }

                DB::commit();

                Log::info('Pope image deleted', [
                    'updated_by' => $user->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Pope image deleted successfully',
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('Error deleting pope image: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error deleting pope image',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Upload pope image to storage (global path)
     */
    private function uploadPopeImage(UploadedFile $file): ?string
    {
        try {
            // Generate secure filename
            $filename = $this->generatePopeImageFilename($file);
            
            // Store in global directory (not tenant-specific)
            $directory = "pope";
            
            // Store the original file
            $storedPath = Storage::disk('public')->putFileAs(
                $directory,
                $file,
                $filename,
                ['visibility' => 'public']
            );

            // Create thumbnails
            $this->createThumbnails($storedPath);

            return $storedPath;
        } catch (\Exception $e) {
            Log::error('Error uploading pope image: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Generate secure filename for pope image
     */
    private function generatePopeImageFilename(UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];
        
        if (!in_array($extension, $allowedExtensions)) {
            $extension = 'jpg'; // Default fallback
        }
        
        $timestamp = now()->format('YmdHis');
        $random = \Illuminate\Support\Str::random(16);
        $hash = substr(hash('sha256', $file->getClientOriginalName() . $timestamp), 0, 8);
        
        return "pope_{$timestamp}_{$random}_{$hash}.{$extension}";
    }

    /**
     * Create thumbnails for pope image (128x128 and 300x300)
     */
    private function createThumbnails(string $imagePath): void
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

                // Use GD or Intervention Image if available
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
            Log::warning('Failed to create thumbnails for pope image: ' . $e->getMessage());
            // Don't fail the upload if thumbnails fail
        }
    }

    /**
     * Get full URL for pope image
     */
    private function getPopeImageUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        return Storage::disk('public')->url($path);
    }

    /**
     * Delete pope image and thumbnails
     */
    private function deletePopeImage(string $path): void
    {
        try {
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
            Log::error('Error deleting pope image: ' . $e->getMessage());
        }
    }
}
