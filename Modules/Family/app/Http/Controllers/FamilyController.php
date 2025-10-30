<?php

namespace Modules\Family\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Family\Http\Requests\StoreFamilyRequest;
use Modules\Family\Http\Requests\UpdateFamilyRequest;
use Modules\Family\Http\Requests\StoreFamilyMemberRequest;
use Modules\Family\Http\Requests\UpdateFamilyMemberRequest;
use Modules\Family\Services\FamilyService;

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
            $tenantId = Auth::user()->tenant_id;
            $userId = Auth::id();
            
            if (!$tenantId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant ID is required'
                ], 403);
            }

            $member = $this->familyService->updateMember(
                $familyId,
                $memberId,
                $request->validated(),
                $tenantId,
                $userId
            );

            if (!$member) {
                return response()->json([
                    'success' => false,
                    'message' => 'Family member not found'
                ], 404);
            }

            return response()->json([
                'success' => true,
                'message' => 'Family member updated successfully',
                'data' => $member
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to update family member',
                'error' => $e->getMessage()
            ], 500);
        }
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
}
