<?php

namespace Modules\Family\app\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Family\app\Http\Requests\StoreFamilyRequest;
use Modules\Family\app\Http\Requests\UpdateFamilyRequest;
use Modules\Family\app\Http\Requests\StoreFamilyMemberRequest;
use Modules\Family\app\Http\Requests\UpdateFamilyMemberRequest;
use Modules\Family\app\Services\FamilyService;

class FamilyController extends Controller
{
    /**
     * @var FamilyService
     */
    protected FamilyService $familyService;

    /**
     * FamilyController constructor.
     *
     * @param FamilyService $familyService
     */
    public function __construct(FamilyService $familyService)
    {
        $this->familyService = $familyService;
    }

    /**
     * Display a listing of families
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $tenantId = Auth::user()->tenant_id;
            
            if (!$tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required'
                ], 403);
            }

            $filters = [
                'search' => $request->input('search'),
                'status' => $request->input('status'),
                'bcc_id' => $request->input('bcc_id'),
                'parish_zone_id' => $request->input('parish_zone_id'),
                'city' => $request->input('city'),
                'sort_by' => $request->input('sort_by', 'created_at'),
                'sort_order' => $request->input('sort_order', 'desc'),
            ];

            $perPage = $request->input('per_page', 15);
            $families = $this->familyService->getPaginatedFamilies($tenantId, $filters, $perPage);

            return response()->json([
                'success' => true,
                'data' => $families->items(),
                'total' => $families->total(),
                'current_page' => $families->currentPage(),
                'last_page' => $families->lastPage(),
                'per_page' => $families->perPage(),
                'from' => $families->firstItem(),
                'to' => $families->lastItem(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve families',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store a newly created family
     *
     * @param StoreFamilyRequest $request
     * @return JsonResponse
     */
    public function store(StoreFamilyRequest $request): JsonResponse
    {
        try {
            $tenantId = Auth::user()->tenant_id;
            $userId = Auth::id();
            
            if (!$tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required'
                ], 403);
            }

            $family = $this->familyService->createFamily(
                $request->validated(),
                $tenantId,
                $userId
            );

            return response()->json([
                'success' => true,
                'message' => 'Family created successfully',
                'data' => $family
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to create family',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified family
     *
     * @param string $id
     * @return JsonResponse
     */
    public function show(string $id): JsonResponse
    {
        try {
            $tenantId = Auth::user()->tenant_id;
            
            if (!$tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required'
                ], 403);
            }

            $family = $this->familyService->getFamilyById($id, $tenantId);

            if (!$family) {
                return response()->json([
                    'success' => false,
                    'message' => 'Family not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $family
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve family',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update the specified family
     *
     * @param UpdateFamilyRequest $request
     * @param string $id
     * @return JsonResponse
     */
    public function update(UpdateFamilyRequest $request, string $id): JsonResponse
    {
        try {
            $tenantId = Auth::user()->tenant_id;
            $userId = Auth::id();
            
            if (!$tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required'
                ], 403);
            }

            $family = $this->familyService->updateFamily(
                $id,
                $request->validated(),
                $tenantId,
                $userId
            );

            if (!$family) {
                return response()->json([
                    'success' => false,
                    'message' => 'Family not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Family updated successfully',
                'data' => $family
            ]);

        } catch (\RuntimeException $e) {
            if ($e->getCode() === 409) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 409);
            }
            return response()->json([
                'success' => false,
                'message' => 'Failed to update family',
                'error' => $e->getMessage()
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update family',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified family
     *
     * @param string $id
     * @return JsonResponse
     */
    public function destroy(string $id): JsonResponse
    {
        try {
            $tenantId = Auth::user()->tenant_id;
            $userId = Auth::id();
            
            if (!$tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required'
                ], 403);
            }

            $result = $this->familyService->deleteFamily($id, $tenantId, $userId);

            if (!$result) {
                return response()->json([
                    'success' => false,
                    'message' => 'Family not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Family deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete family',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get family statistics
     *
     * @return JsonResponse
     */
    public function statistics(): JsonResponse
    {
        try {
            $tenantId = Auth::user()->tenant_id;
            
            if (!$tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required'
                ], 403);
            }

            $statistics = $this->familyService->getStatistics($tenantId);

            return response()->json([
                'success' => true,
                'data' => $statistics
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve statistics',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get families by BCC
     *
     * @param string $bccId
     * @return JsonResponse
     */
    public function byBcc(string $bccId): JsonResponse
    {
        try {
            $tenantId = Auth::user()->tenant_id;
            
            if (!$tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required'
                ], 403);
            }

            $families = $this->familyService->getFamiliesByBCC($bccId, $tenantId);

            return response()->json([
                'success' => true,
                'data' => $families
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve families',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get families without BCC assignment
     *
     * @return JsonResponse
     */
    public function withoutBcc(): JsonResponse
    {
        try {
            $tenantId = Auth::user()->tenant_id;
            
            if (!$tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required'
                ], 403);
            }

            $families = $this->familyService->getFamiliesWithoutBCC($tenantId);

            return response()->json([
                'success' => true,
                'data' => $families
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve families',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    // ==================== FAMILY MEMBER OPERATIONS ====================

    /**
     * Get all members of a family
     *
     * @param string $familyId
     * @return JsonResponse
     */
    public function members(string $familyId): JsonResponse
    {
        try {
            $tenantId = Auth::user()->tenant_id;
            
            if (!$tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required'
                ], 403);
            }

            $members = $this->familyService->getFamilyMembers($familyId, $tenantId);

            if ($members === null) {
                return response()->json([
                    'success' => false,
                    'message' => 'Family not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => $members
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve family members',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Add a member to a family
     *
     * @param StoreFamilyMemberRequest $request
     * @param string $familyId
     * @return JsonResponse
     */
    public function addMember(StoreFamilyMemberRequest $request, string $familyId): JsonResponse
    {
        try {
            $tenantId = Auth::user()->tenant_id;
            $userId = Auth::id();
            
            if (!$tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required'
                ], 403);
            }

            $member = $this->familyService->addMember(
                $familyId,
                $request->validated(),
                $tenantId,
                $userId
            );

            if (!$member) {
                return response()->json([
                    'success' => false,
                    'message' => 'Family not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Family member added successfully',
                'data' => $member
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to add family member',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a family member
     *
     * @param UpdateFamilyMemberRequest $request
     * @param string $familyId
     * @param string $memberId
     * @return JsonResponse
     */
    public function updateMember(UpdateFamilyMemberRequest $request, string $familyId, string $memberId): JsonResponse
    {
        try {
            // Sanitize UUIDs from route parameters - remove any whitespace or extra characters
            $familyId = trim($familyId);
            $memberId = trim($memberId);
            
            // Extract UUID pattern (36 characters with hyphens) if there's any extra text
            if (preg_match('/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i', $familyId, $matches)) {
                $familyId = $matches[1];
            }
            if (preg_match('/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i', $memberId, $matches)) {
                $memberId = $matches[1];
            }
            
            // Validate UUIDs are properly formatted
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $familyId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid family ID format'
                ], 400);
            }
            
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $memberId)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Invalid member ID format'
                ], 400);
            }
            
            $tenantId = Auth::user()->tenant_id;
            $userId = Auth::id();
            
            if (!$tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required'
                ], 403);
            }

            // Get validated data - this ensures only valid fields are updated
            $validatedData = $request->validated();
            
            // Convert empty strings to null for nullable fields only
            // Fields that are 'sometimes|required' should not be converted to null if empty
            $updateData = [];
            foreach ($validatedData as $key => $value) {
                // Only convert to null if the value is an empty string AND the field is nullable
                // Required fields should keep their values even if they happen to be empty strings
                if ($value === '' && $this->isNullableField($key, $request)) {
                    $updateData[$key] = null;
                } else {
                    $updateData[$key] = $value;
                }
            }

            $member = $this->familyService->updateMember(
                $familyId,
                $memberId,
                $updateData,
                $tenantId,
                $userId
            );

            if (!$member) {
                return response()->json([
                    'success' => false,
                    'message' => 'Family member not found or does not belong to your tenant'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Family member updated successfully',
                'data' => $member
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Family member not found'
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Error updating family member', [
                'family_id' => $familyId,
                'member_id' => $memberId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Failed to update family member: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Check if a field is nullable in the validation rules
     */
    private function isNullableField(string $field, \Illuminate\Http\Request $request): bool
    {
        // List of fields that are explicitly nullable in UpdateFamilyMemberRequest
        $nullableFields = [
            'middle_name',
            'date_of_birth',
            'gender',
            'marital_status',
            'phone',
            'email',
            'is_primary_contact',
            'baptism_date',
            'baptism_place',
            'first_communion_date',
            'first_communion_place',
            'confirmation_date',
            'confirmation_place',
            'marriage_date',
            'marriage_place',
            'marriage_spouse_name',
            'occupation',
            'education',
            'skills_talents',
            'notes',
            'status',
            'deceased_date'
        ];
        
        return in_array($field, $nullableFields);
    }

    /**
     * Delete a family member
     *
     * @param string $familyId
     * @param string $memberId
     * @return JsonResponse
     */
    public function deleteMember(string $familyId, string $memberId): JsonResponse
    {
        try {
            $tenantId = Auth::user()->tenant_id;
            $userId = Auth::id();
            
            if (!$tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required'
                ], 403);
            }

            $result = $this->familyService->deleteMember($familyId, $memberId, $tenantId, $userId);

            if (!$result) {
                return response()->json([
                    'success' => false,
                    'message' => 'Family member not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Family member deleted successfully'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete family member',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload profile image for family head
     *
     * @param Request $request
     * @param string $id
     * @return JsonResponse
     */
    public function uploadProfileImage(Request $request, string $id): JsonResponse
    {
        try {
            $tenantId = Auth::user()->tenant_id;
            $userId = Auth::id();
            
            if (!$tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required'
                ], 403);
            }

            // Validate image file
            $request->validate([
                'profile_image' => ['required', 'image', 'mimes:jpeg,jpg,png,gif,webp', 'max:5120']
            ]);

            $family = $this->familyService->uploadProfileImage(
                $id,
                $request->file('profile_image'),
                $tenantId,
                $userId
            );

            if (!$family) {
                return response()->json([
                    'success' => false,
                    'message' => 'Family not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Profile image uploaded successfully',
                'data' => $family
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload profile image',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete profile image for family
     *
     * @param string $id
     * @return JsonResponse
     */
    public function deleteProfileImage(string $id): JsonResponse
    {
        try {
            $tenantId = Auth::user()->tenant_id;
            $userId = Auth::id();
            
            if (!$tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required'
                ], 403);
            }

            $family = $this->familyService->deleteProfileImage($id, $tenantId, $userId);

            if (!$family) {
                return response()->json([
                    'success' => false,
                    'message' => 'Family not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Profile image deleted successfully',
                'data' => $family
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete profile image',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Upload profile image for family head
     *
     * @param Request $request
     * @param string $id
     * @return JsonResponse
     */
    public function uploadHeadProfileImage(Request $request, string $id): JsonResponse
    {
        try {
            $tenantId = Auth::user()->tenant_id;
            $userId = Auth::id();
            
            if (!$tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required'
                ], 403);
            }

            // Validate image file
            $request->validate([
                'head_profile_image' => ['required', 'image', 'mimes:jpeg,jpg,png,gif,webp', 'max:5120']
            ]);

            $family = $this->familyService->uploadHeadProfileImage(
                $id,
                $request->file('head_profile_image'),
                $tenantId,
                $userId
            );

            if (!$family) {
                return response()->json([
                    'success' => false,
                    'message' => 'Family not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Head profile image uploaded successfully',
                'data' => $family
            ]);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to upload head profile image',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete profile image for family head
     *
     * @param string $id
     * @return JsonResponse
     */
    public function deleteHeadProfileImage(string $id): JsonResponse
    {
        try {
            $tenantId = Auth::user()->tenant_id;
            $userId = Auth::id();
            
            if (!$tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required'
                ], 403);
            }

            $family = $this->familyService->deleteHeadProfileImage($id, $tenantId, $userId);

            if (!$family) {
                return response()->json([
                    'success' => false,
                    'message' => 'Family not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Head profile image deleted successfully',
                'data' => $family
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to delete head profile image',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
