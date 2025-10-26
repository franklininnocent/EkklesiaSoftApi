<?php

namespace Modules\Tenants\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
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
            
            if (!$user || !$user->tenant_id) {
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
                ['tenant_id' => $user->tenant_id],
                [
                    'founded_year' => null,
                    'country' => null,
                ]
            );

            return response()->json([
                'success' => true,
                'data' => $churchProfile,
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
            
            if (!$user || !$user->tenant_id) {
                return response()->json([
                    'success' => false,
                    'message' => 'User is not associated with a tenant/church',
                ], 404);
            }

            // Check if user has permission to edit church profile
            if (!$user->is_primary_admin && !$user->hasPermissionTo('manage_tenants')) {
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
            ]);

            DB::beginTransaction();
            try {
                $churchProfile = ChurchProfile::updateOrCreate(
                    ['tenant_id' => $user->tenant_id],
                    $validated
                );

                DB::commit();

                // Reload relationships
                $churchProfile->load([
                    'denomination',
                    'archdiocese.denomination',
                    'bishop.archdiocese'
                ]);

                Log::info('Church profile updated', [
                    'tenant_id' => $user->tenant_id,
                    'updated_by' => $user->id,
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Church profile updated successfully',
                    'data' => $churchProfile,
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
}
