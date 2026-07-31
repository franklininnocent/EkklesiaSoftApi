<?php

namespace Modules\Tenants\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\ChurchLeadership;

/**
 * Church Leadership Controller
 * 
 * Manages church leaders (pastors, associate pastors, ministry leaders).
 * Full CRUD operations for tenant administrators.
 */
class ChurchLeadershipController extends Controller
{
    /**
     * Get all church leaders for the tenant.
     * 
     * @route GET /api/church-leadership
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user || !$user->tenant_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not associated with a tenant/church',
                ], 404);
            }

            $filters = [
                'active' => $request->get('active'),
                'role' => $request->get('role'),
                'current' => $request->get('current'),
            ];
            $cacheKey = $this->leadershipCacheKey($user->tenant_id, $filters);

            // Try to get from cache first (cache for 5 minutes)
            $leaders = Cache::remember($cacheKey, 300, function () use ($user, $request) {
                $query = ChurchLeadership::where('tenant_id', $user->tenant_id);

                // Filter by active status
                if ($request->has('active')) {
                    $query->where('active', $request->active);
                }

                // Filter by role
                if ($request->has('role')) {
                    $query->where('role', $request->role);
                }

                // Filter current leaders only
                if ($request->has('current') && $request->current) {
                    $query->current();
                }

                // Use ordered() scope which applies proper sorting at database level
                // This is more efficient than client-side sorting
                return $query->ordered()->get();
            });

            return response()->json([
                'success' => true,
                'data' => $leaders,
                'total' => $leaders->count(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching church leadership: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error fetching church leadership',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get a specific church leader.
     * 
     * @route GET /api/church-leadership/{id}
     */
    public function show($id): JsonResponse
    {
        try {
            $user = auth()->user();
            
            $leader = ChurchLeadership::where('tenant_id', $user->tenant_id)
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $leader,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching church leader: ' . $e->getMessage(), [
                'leader_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Church leader not found',
                'error' => config('app.debug') ? $e->getMessage() : 'Not found',
            ], 404);
        }
    }

    /**
     * Create a new church leader.
     * 
     * @route POST /api/church-leadership
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user || !$user->tenant_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not associated with a tenant/church',
                ], 404);
            }

            if (!$this->canManageChurchSettings($user, 'create')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only church administrators can manage leadership.',
                ], 403);
            }

            // Validation
            $validated = $request->validate([
                'full_name' => 'required|string|max:255',
                'role' => 'required|string|max:100',
                'title' => 'nullable|string|max:100',
                'email' => 'nullable|email|max:255',
                'phone' => 'nullable|string|max:20',
                'appointed_date' => 'nullable|date',
                'relieved_date' => 'nullable|date',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after:start_date',
                'biography' => 'nullable|string|max:2000',
                'photo_url' => 'nullable|string|max:255',
                'is_primary' => 'nullable|boolean',
                'display_order' => 'nullable|integer|min:0',
                'active' => 'nullable|boolean',
            ]);

            $validated['tenant_id'] = $user->tenant_id;
            $validated['is_primary'] = $validated['is_primary'] ?? 0;
            // If relieved_date is set, automatically set active to 0
            if (!empty($validated['relieved_date'])) {
                $validated['active'] = 0;
            } else {
            $validated['active'] = $validated['active'] ?? 1;
            }
            $validated['display_order'] = $validated['display_order'] ?? 0;

            DB::beginTransaction();
            try {
                $leader = ChurchLeadership::create($validated);

                DB::commit();

                $this->clearLeadershipCache($user->tenant_id);

                Log::info('Church leader created', [
                    'leader_id' => $leader->id,
                    'tenant_id' => $user->tenant_id,
                    'created_by' => $user->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Church leader added successfully',
                    'data' => $leader,
                ], 201);
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
            Log::error('Error creating church leader: ' . $e->getMessage(), [
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error creating church leader',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Update a church leader.
     * 
     * @route PUT /api/church-leadership/{id}
     */
    public function update(Request $request, $id): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user || !$user->tenant_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not associated with a tenant/church',
                ], 404);
            }

            if (!$this->canManageChurchSettings($user, 'edit')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only church administrators can manage leadership.',
                ], 403);
            }

            $leader = ChurchLeadership::where('tenant_id', $user->tenant_id)
                ->findOrFail($id);

            // Validation
            $validated = $request->validate([
                'full_name' => 'sometimes|required|string|max:255',
                'role' => 'sometimes|required|string|max:100',
                'title' => 'nullable|string|max:100',
                'email' => 'nullable|email|max:255',
                'phone' => 'nullable|string|max:20',
                'appointed_date' => 'nullable|date',
                'relieved_date' => 'nullable|date',
                'start_date' => 'nullable|date',
                'end_date' => 'nullable|date|after:start_date',
                'biography' => 'nullable|string|max:2000',
                'photo_url' => 'nullable|string|max:255',
                'is_primary' => 'nullable|boolean',
                'display_order' => 'nullable|integer|min:0',
                'active' => 'nullable|boolean',
            ]);

            // If relieved_date is set, automatically set active to 0
            if (!empty($validated['relieved_date'])) {
                $validated['active'] = 0;
            }

            if (array_key_exists('photo_url', $validated) && empty($validated['photo_url'])) {
                $this->deleteLeaderPhotoFile($leader->photo_url);
                $validated['photo_url'] = null;
            }

            DB::beginTransaction();
            try {
                $leader->update($validated);

                DB::commit();

                // Clear cache for this tenant's leadership
                $this->clearLeadershipCache($user->tenant_id);

                Log::info('Church leader updated', [
                    'leader_id' => $leader->id,
                    'tenant_id' => $user->tenant_id,
                    'updated_by' => $user->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Church leader updated successfully',
                    'data' => $leader->fresh(),
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
            Log::error('Error updating church leader: ' . $e->getMessage(), [
                'leader_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error updating church leader',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Upload or replace a leader profile photo.
     *
     * @route POST /api/church-leadership/{id}/upload-photo
     */
    public function uploadPhoto(Request $request, $id): JsonResponse
    {
        try {
            $user = auth()->user();

            if (!$user || !$user->tenant_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not associated with a tenant/church',
                ], 404);
            }

            if (!$this->canManageChurchSettings($user, 'edit')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only church administrators can manage leadership.',
                ], 403);
            }

            $leader = ChurchLeadership::where('tenant_id', $user->tenant_id)
                ->findOrFail($id);

            $validated = $request->validate([
                'image' => 'required|image|mimes:jpeg,jpg,png,webp|max:2048',
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
                $this->deleteLeaderPhotoFile($leader->photo_url);

                $filename = $this->generateLeaderPhotoFilename($file, $user->tenant_id, (int) $leader->id);
                $directory = "tenants/{$user->tenant_id}/leadership";
                $storedPath = Storage::disk('public')->putFileAs(
                    $directory,
                    $file,
                    $filename,
                    ['visibility' => 'public']
                );

                if (!$storedPath) {
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => 'Failed to upload leader photo',
                    ], 500);
                }

                $leader->photo_url = $storedPath;
                $leader->save();

                DB::commit();
                $this->clearLeadershipCache($user->tenant_id);

                return response()->json([
                    'success' => true,
                    'message' => 'Leader photo uploaded successfully',
                    'data' => $leader->fresh(),
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
            Log::error('Error uploading church leader photo: ' . $e->getMessage(), [
                'leader_id' => $id,
                'user_id' => auth()->id(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error uploading leader photo',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Delete a church leader.
     * 
     * @route DELETE /api/church-leadership/{id}
     */
    public function destroy($id): JsonResponse
    {
        try {
            $user = auth()->user();
            
            if (!$user || !$user->tenant_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not associated with a tenant/church',
                ], 404);
            }

            if (!$this->canManageChurchSettings($user, 'delete')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only church administrators can manage leadership.',
                ], 403);
            }

            $leader = ChurchLeadership::where('tenant_id', $user->tenant_id)
                ->findOrFail($id);

            DB::beginTransaction();
            try {
                $this->deleteLeaderPhotoFile($leader->photo_url);
                $leader->delete();

                DB::commit();

                // Clear cache for this tenant's leadership
                $this->clearLeadershipCache($user->tenant_id);

                Log::info('Church leader deleted', [
                    'leader_id' => $id,
                    'tenant_id' => $user->tenant_id,
                    'deleted_by' => $user->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Church leader deleted successfully',
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                throw $e;
            }
        } catch (\Exception $e) {
            Log::error('Error deleting church leader: ' . $e->getMessage(), [
                'leader_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error deleting church leader',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Build a versioned cache key so all filter variants invalidate together.
     */
    private function leadershipCacheKey(int $tenantId, array $filters): string
    {
        return 'church_leadership_' . $tenantId
            . '_v' . $this->leadershipCacheVersion($tenantId)
            . '_' . md5(json_encode($filters));
    }

    private function leadershipCacheVersion(int $tenantId): int
    {
        return (int) Cache::get('church_leadership_version_' . $tenantId, 0);
    }

    /**
     * Invalidate all leadership list cache entries for a tenant.
     */
    private function clearLeadershipCache(int $tenantId): void
    {
        $versionKey = 'church_leadership_version_' . $tenantId;

        if (Cache::has($versionKey)) {
            Cache::increment($versionKey);
            return;
        }

        Cache::put($versionKey, 1, 86400 * 30);
    }

    private function generateLeaderPhotoFilename(UploadedFile $file, int $tenantId, int $leaderId): string
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp'];

        if (!in_array($extension, $allowedExtensions, true)) {
            $extension = 'jpg';
        }

        $timestamp = now()->format('YmdHis');
        $random = Str::random(12);

        return "leader_t{$tenantId}_{$leaderId}_{$timestamp}_{$random}.{$extension}";
    }

    private function deleteLeaderPhotoFile(?string $photoPath): void
    {
        if (!$photoPath || str_starts_with($photoPath, 'http://') || str_starts_with($photoPath, 'https://')) {
            return;
        }

        if (Storage::disk('public')->exists($photoPath)) {
            Storage::disk('public')->delete($photoPath);
        }
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
