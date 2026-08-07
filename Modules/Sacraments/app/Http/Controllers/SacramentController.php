<?php

namespace Modules\Sacraments\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Modules\Family\Models\FamilyMember;
use Modules\Sacraments\Services\SacramentService;
use Modules\Sacraments\Models\SacramentType;

/**
 * SacramentController - Tenant Sacrament Records Management
 * 
 * This controller handles CRUD operations for Sacrament Records.
 * Only Tenant users can access these endpoints. Ekklesia users are blocked.
 * 
 * Note: Sacrament Types are managed by Ekklesia users in the
 * EcclesiasticalData module and are read-only here.
 */
class SacramentController extends Controller
{
    protected array $sacramentTypeCache = [];

    public function __construct(protected SacramentService $service) {}

    /**
     * Verify user is a tenant user (not Ekklesia role)
     */
    private function verifyTenantUser(Request $request): ?JsonResponse
    {
        $user = $request->user();
        
        // Check if user has tenant_id (tenant users must have this)
        if (app(\Modules\Tenants\Support\TenantContext::class)->effectiveTenantId() === null) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only tenant users can manage sacrament records.',
            ], 403);
        }

        // Block Ekklesia roles from accessing tenant sacrament records
        if ($user->hasEkklesiaRole()) {
            return response()->json([
                'success' => false,
                'message' => 'Ekklesia users cannot access tenant sacrament records. Please use a tenant account.',
            ], 403);
        }

        return null; // User is authorized
    }

    /**
     * Get paginated list of sacraments (tenant-isolated)
     */
    public function index(Request $request): JsonResponse
    {
        try {
            // Verify tenant user
            if ($error = $this->verifyTenantUser($request)) {
                return $error;
            }

            $user = $request->user();
            
            // Get params and enforce tenant isolation
            $params = $request->only([
                'sacrament_type_id', 'status', 'search',
                'date_from', 'date_to', 'per_page', 'sort_by', 'sort_dir',
                'minister_name', 'certificate_number', 'book_number',
                'family_id', 'bcc_id'
            ]);
            
            // Force tenant_id to current user's tenant
            $params['tenant_id'] = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();

            $sacraments = $this->service->getAll($params);

            return response()->json([
                'success' => true,
                'data' => $sacraments,
                'message' => 'Sacraments retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching sacraments', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id ?? null
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve sacraments',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred'
            ], 500);
        }
    }

    /**
     * Get single sacrament by ID (tenant-isolated)
     */
    public function show(Request $request, int $id): JsonResponse
    {
        try {
            // Verify tenant user
            if ($error = $this->verifyTenantUser($request)) {
                return $error;
            }

            $user = $request->user();
            $sacrament = $this->service->getById($id);

            if (!$sacrament) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sacrament not found'
                ], 404);
            }

            // Verify sacrament belongs to user's tenant
            if ($sacrament->tenant_id !== app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You can only access sacraments from your own tenant.',
                ], 403);
            }

            return response()->json([
                'success' => true,
                'data' => $sacrament,
                'message' => 'Sacrament retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching sacrament', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve sacrament',
            ], 500);
        }
    }

    /**
     * Create new sacrament record (tenant-isolated)
     */
    public function store(Request $request): JsonResponse
    {
        try {
            // Verify tenant user
            if ($error = $this->verifyTenantUser($request)) {
                return $error;
            }

            $user = $request->user();

            // Prepare data - convert empty strings to null for nullable fields
            $data = $request->all();
            $nullableFields = [
                'family_id', 'bcc_id', 'recipient_dob', 'recipient_birth_date', 
                'recipient_birth_place', 'recipient_gender', 'place_administered', 'minister_name', 
                'minister_title', 'certificate_number', 'book_number', 'page_number',
                'father_name', 'mother_name', 'godparent1_name', 'godparent2_name',
                'witnesses', 'notes', 'status',
                'marriage_bride_full_name', 'marriage_bride_father_name', 'marriage_bride_mother_name',
                'marriage_bride_address', 'marriage_bride_church_type', 'marriage_bride_church_name',
                'marriage_bride_church_address', 'marriage_groom_full_name', 'marriage_groom_father_name',
                'marriage_groom_mother_name', 'marriage_groom_address', 'marriage_groom_church_type',
                'marriage_groom_church_name', 'marriage_groom_church_address',
            ];
            
            foreach ($nullableFields as $field) {
                if (isset($data[$field]) && $data[$field] === '') {
                    $data[$field] = null;
                }
            }

            // Build validation rules with tenant isolation
            $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
            $validationRules = [
                'sacrament_type_id' => 'required|exists:sacrament_types,id',
                'family_id' => [
                    'nullable',
                    'uuid',
                    Rule::exists('families', 'id')->where('tenant_id', $tenantId)
                ],
                'bcc_id' => [
                    'nullable',
                    'uuid',
                    Rule::exists('bccs', 'id')->where('tenant_id', $tenantId)
                ],
                'recipient_name' => 'required|string|max:255',
                'recipient_dob' => 'nullable|date',
                'recipient_birth_date' => 'nullable|date',
                'recipient_birth_place' => 'nullable|string|max:255',
                'date_administered' => 'required|date',
                'place_administered' => 'nullable|string|max:255',
                'recipient_gender' => 'nullable|in:male,female,other',
                'minister_name' => 'nullable|string|max:255',
                'minister_title' => 'nullable|string|max:50',
                'certificate_number' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('sacraments', 'certificate_number')
                        ->where('tenant_id', $tenantId)
                        ->whereNull('deleted_at')
                ],
                'book_number' => 'nullable|string|max:255',
                'page_number' => 'nullable|string|max:255',
                'father_name' => 'nullable|string|max:255',
                'mother_name' => 'nullable|string|max:255',
                'godparent1_name' => 'nullable|string|max:255',
                'godparent2_name' => 'nullable|string|max:255',
                'witnesses' => 'nullable|string',
                'notes' => 'nullable|string',
                'status' => 'nullable|in:active,cancelled,conditional',
                'marriage_bride_full_name' => 'nullable|string|max:255',
                'marriage_bride_father_name' => 'nullable|string|max:255',
                'marriage_bride_mother_name' => 'nullable|string|max:255',
                'marriage_bride_address' => 'nullable|string',
                'marriage_bride_church_type' => 'nullable|in:home_parish,other',
                'marriage_bride_church_name' => 'nullable|string|max:255',
                'marriage_bride_church_address' => 'nullable|string',
                'marriage_groom_full_name' => 'nullable|string|max:255',
                'marriage_groom_father_name' => 'nullable|string|max:255',
                'marriage_groom_mother_name' => 'nullable|string|max:255',
                'marriage_groom_address' => 'nullable|string',
                'marriage_groom_church_type' => 'nullable|in:home_parish,other',
                'marriage_groom_church_name' => 'nullable|string|max:255',
                'marriage_groom_church_address' => 'nullable|string',
            ];
            
            $validated = validator($data, $validationRules)->validate();

            // Auto-set tenant_id from authenticated user
            $validated['tenant_id'] = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
            $validated['created_by'] = $user->id;

            $sacrament = $this->service->create($validated);
            $this->syncBaptismFamilyMembership($sacrament, $user);

            Log::info('Sacrament record created', [
                'sacrament_id' => $sacrament->id,
                'tenant_id' => app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId(),
                'created_by' => $user->id
            ]);

            return response()->json([
                'success' => true,
                'data' => $sacrament,
                'message' => 'Sacrament created successfully'
            ], 201);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error creating sacrament', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all(),
                'user_id' => $request->user()->id ?? null
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create sacrament',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred while creating the sacrament record'
            ], 500);
        }
    }

    /**
     * Update existing sacrament record (tenant-isolated)
     */
    public function update(Request $request, int $id): JsonResponse
    {
        try {
            // Verify tenant user
            if ($error = $this->verifyTenantUser($request)) {
                return $error;
            }

            $user = $request->user();

            // Check if sacrament exists and belongs to user's tenant
            $existingSacrament = $this->service->getById($id);
            
            if (!$existingSacrament) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sacrament not found'
                ], 404);
            }

            if ($existingSacrament->tenant_id !== app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You can only update sacraments from your own tenant.',
                ], 403);
            }

            $data = $request->all();
            $nullableFields = [
                'family_id', 'bcc_id', 'recipient_dob', 'recipient_birth_date', 
                'recipient_birth_place', 'recipient_gender', 'place_administered', 'minister_name', 
                'minister_title', 'certificate_number', 'book_number', 'page_number',
                'father_name', 'mother_name', 'godparent1_name', 'godparent2_name',
                'witnesses', 'notes', 'status',
                'marriage_bride_full_name', 'marriage_bride_father_name', 'marriage_bride_mother_name',
                'marriage_bride_address', 'marriage_bride_church_type', 'marriage_bride_church_name',
                'marriage_bride_church_address', 'marriage_groom_full_name', 'marriage_groom_father_name',
                'marriage_groom_mother_name', 'marriage_groom_address', 'marriage_groom_church_type',
                'marriage_groom_church_name', 'marriage_groom_church_address',
            ];

            foreach ($nullableFields as $field) {
                if (isset($data[$field]) && $data[$field] === '') {
                    $data[$field] = null;
                }
            }

            $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();

            $validationRules = [
                'family_id' => [
                    'nullable',
                    'uuid',
                    Rule::exists('families', 'id')->where('tenant_id', $tenantId)
                ],
                'bcc_id' => [
                    'nullable',
                    'uuid',
                    Rule::exists('bccs', 'id')->where('tenant_id', $tenantId)
                ],
                'recipient_name' => 'sometimes|string|max:255',
                'recipient_dob' => 'nullable|date',
                'recipient_birth_date' => 'nullable|date',
                'recipient_birth_place' => 'nullable|string|max:255',
                'date_administered' => 'sometimes|date',
                'place_administered' => 'nullable|string|max:255',
                'recipient_gender' => 'nullable|in:male,female,other',
                'minister_name' => 'nullable|string|max:255',
                'minister_title' => 'nullable|string|max:50',
                'certificate_number' => [
                    'nullable',
                    'string',
                    'max:255',
                    Rule::unique('sacraments', 'certificate_number')
                        ->where('tenant_id', $tenantId)
                        ->whereNull('deleted_at')
                        ->ignore($id)
                ],
                'book_number' => 'nullable|string|max:255',
                'page_number' => 'nullable|string|max:255',
                'father_name' => 'nullable|string|max:255',
                'mother_name' => 'nullable|string|max:255',
                'godparent1_name' => 'nullable|string|max:255',
                'godparent2_name' => 'nullable|string|max:255',
                'witnesses' => 'nullable|string',
                'notes' => 'nullable|string',
                'status' => 'nullable|in:active,cancelled,conditional',
                'marriage_bride_full_name' => 'nullable|string|max:255',
                'marriage_bride_father_name' => 'nullable|string|max:255',
                'marriage_bride_mother_name' => 'nullable|string|max:255',
                'marriage_bride_address' => 'nullable|string',
                'marriage_bride_church_type' => 'nullable|in:home_parish,other',
                'marriage_bride_church_name' => 'nullable|string|max:255',
                'marriage_bride_church_address' => 'nullable|string',
                'marriage_groom_full_name' => 'nullable|string|max:255',
                'marriage_groom_father_name' => 'nullable|string|max:255',
                'marriage_groom_mother_name' => 'nullable|string|max:255',
                'marriage_groom_address' => 'nullable|string',
                'marriage_groom_church_type' => 'nullable|in:home_parish,other',
                'marriage_groom_church_name' => 'nullable|string|max:255',
                'marriage_groom_church_address' => 'nullable|string',
            ];

            $validated = validator($data, $validationRules)->validate();

            $validated['updated_by'] = $user->id;

            $sacrament = $this->service->update($id, $validated);
            $this->syncBaptismFamilyMembership($sacrament, $user);

            Log::info('Sacrament record updated', [
                'sacrament_id' => $id,
                'updated_by' => $user->id
            ]);

            return response()->json([
                'success' => true,
                'data' => $sacrament,
                'message' => 'Sacrament updated successfully'
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error updating sacrament', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update sacrament',
            ], 500);
        }
    }

    /**
     * Delete sacrament record (tenant-isolated)
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        try {
            // Verify tenant user
            if ($error = $this->verifyTenantUser($request)) {
                return $error;
            }

            $user = $request->user();

            // SECURITY: Only Tenant Admins can delete sacraments
            if (!$user->isTenantAdmin() && !$user->isSuperAdmin() && !$user->isEkklesiaAdmin()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only Tenant Administrators can delete sacrament records.',
                ], 403);
            }

            // Check if sacrament exists and belongs to user's tenant
            $sacrament = $this->service->getById($id);
            
            if (!$sacrament) {
                return response()->json([
                    'success' => false,
                    'message' => 'Sacrament not found'
                ], 404);
            }

            if ($sacrament->tenant_id !== app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. You can only delete sacraments from your own tenant.',
                ], 403);
            }

            $deleted = $this->service->delete($id);

            Log::info('Sacrament record deleted', [
                'sacrament_id' => $id,
                'deleted_by' => $user->id,
                'deleted_by' => $user->id
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Sacrament deleted successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting sacrament', [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete sacrament',
            ], 500);
        }
    }

    /**
     * Get all sacrament types (read-only for tenants)
     * 
     * Tenant users can VIEW sacrament types to select them,
     * but cannot CREATE/UPDATE/DELETE types.
     */
    public function getSacramentTypes(Request $request): JsonResponse
    {
        try {
            // Verify tenant user
            if ($error = $this->verifyTenantUser($request)) {
                return $error;
            }

            $types = SacramentType::where('active', true)
                ->orderBy('display_order')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $types,
                'message' => 'Sacrament types retrieved successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching sacrament types', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve sacrament types',
            ], 500);
        }
    }

    /**
     * Determine if the sacrament type is Baptism.
     */
    protected function isBaptismType(int $sacramentTypeId): bool
    {
        if (!array_key_exists($sacramentTypeId, $this->sacramentTypeCache)) {
            $type = SacramentType::find($sacramentTypeId);
            $this->sacramentTypeCache[$sacramentTypeId] = $type ? strtoupper($type->code) === 'BAPTISM' : false;
        }

        return $this->sacramentTypeCache[$sacramentTypeId];
    }

    /**
     * Ensure baptized recipients become members of their associated family.
     */
    protected function syncBaptismFamilyMembership($sacrament, $user): void
    {
        if (
            !$sacrament ||
            empty($sacrament->family_id) ||
            !$this->isBaptismType((int) $sacrament->sacrament_type_id)
        ) {
            return;
        }

        $nameParts = $this->parseRecipientName($sacrament->recipient_name);
        if (!$nameParts) {
            return;
        }

        $memberData = array_filter(
            [
                'first_name' => $nameParts['first_name'],
                'middle_name' => $nameParts['middle_name'],
                'last_name' => $nameParts['last_name'],
                'date_of_birth' => $sacrament->recipient_birth_date,
                'gender' => $sacrament->recipient_gender,
                'baptism_date' => $sacrament->date_administered,
                'baptism_place' => $sacrament->place_administered,
                'baptism_godparent_primary' => $sacrament->godparent1_name,
                'baptism_godparent_secondary' => $sacrament->godparent2_name,
                'baptism_church_name' => $sacrament->place_administered,
                'baptism_priest_name' => $sacrament->minister_name,
                'notes' => $sacrament->notes,
            ],
            fn($value) => !is_null($value) && $value !== ''
        );

        $existingMember = FamilyMember::where('family_id', $sacrament->family_id)
            ->whereRaw('LOWER(first_name) = ?', [mb_strtolower($nameParts['first_name'])])
            ->whereRaw('LOWER(last_name) = ?', [mb_strtolower($nameParts['last_name'])])
            ->when(
                $sacrament->recipient_birth_date,
                fn($query) => $query->whereDate('date_of_birth', $sacrament->recipient_birth_date)
            )
            ->first();

        if ($existingMember) {
            $updateData = $memberData;
            $updateData['updated_by'] = $user->id;
            $existingMember->fill($updateData);
            $existingMember->save();
            return;
        }

        $memberData['family_id'] = $sacrament->family_id;
        $memberData['relationship_to_head'] = $this->determineRelationshipToHead($sacrament->family_id);
        $memberData['is_primary_contact'] = false;
        $memberData['status'] = 'active';
        $memberData['created_by'] = $user->id;
        $memberData['updated_by'] = $user->id;

        FamilyMember::create($memberData);
    }

    /**
     * Split recipient name into first/middle/last components.
     */
    protected function parseRecipientName(?string $name): ?array
    {
        if (!$name) {
            return null;
        }

        $normalized = trim(preg_replace('/\s+/', ' ', $name));
        if ($normalized === '') {
            return null;
        }

        $parts = explode(' ', $normalized);
        $firstName = array_shift($parts);
        $lastName = count($parts) ? array_pop($parts) : $firstName;
        $middleName = count($parts) ? implode(' ', $parts) : null;

        return [
            'first_name' => $firstName,
            'middle_name' => $middleName,
            'last_name' => $lastName,
        ];
    }

    /**
     * Determine default relationship role for a new member.
     */
    protected function determineRelationshipToHead(string $familyId): string
    {
        $existingMembers = FamilyMember::where('family_id', $familyId)->count();
        return $existingMembers === 0 ? 'self' : 'other';
    }

    /**
     * Bulk update status for multiple sacraments (tenant-isolated)
     */
    public function bulkUpdateStatus(Request $request): JsonResponse
    {
        try {
            // Verify tenant user
            if ($error = $this->verifyTenantUser($request)) {
                return $error;
            }

            $user = $request->user();
            
            $validated = $request->validate([
                'ids' => 'required|array|min:1',
                'ids.*' => 'required|integer|exists:sacraments,id',
                'status' => 'required|in:active,cancelled,conditional'
            ]);

            $ids = $validated['ids'];
            $status = $validated['status'];
            $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();

            // Verify all sacraments belong to user's tenant
            $sacraments = $this->service->getByIds($ids);
            $unauthorized = $sacraments->filter(fn($sacrament) => $sacrament->tenant_id !== $tenantId);
            
            if ($unauthorized->isNotEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Some sacraments do not belong to your tenant.',
                ], 403);
            }

            // Update all sacraments
            $updated = $this->service->bulkUpdateStatus($ids, $status, $user->id);

            Log::info('Bulk status update performed', [
                'count' => count($ids),
                'status' => $status,
                'updated_by' => $user->id
            ]);

            return response()->json([
                'success' => true,
                'message' => "Successfully updated {$updated} sacrament(s) to {$status}",
                'data' => [
                    'updated_count' => $updated,
                    'status' => $status
                ]
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error in bulk status update', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id ?? null
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to update sacraments',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred'
            ], 500);
        }
    }

    /**
     * Bulk delete multiple sacraments (tenant-isolated)
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        try {
            // Verify tenant user
            if ($error = $this->verifyTenantUser($request)) {
                return $error;
            }

            $user = $request->user();
            
            $validated = $request->validate([
                'ids' => 'required|array|min:1',
                'ids.*' => 'required|integer|exists:sacraments,id'
            ]);

            $ids = $validated['ids'];
            $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();

            // Verify all sacraments belong to user's tenant
            $sacraments = $this->service->getByIds($ids);
            $unauthorized = $sacraments->filter(fn($sacrament) => $sacrament->tenant_id !== $tenantId);
            
            if ($unauthorized->isNotEmpty()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Some sacraments do not belong to your tenant.',
                ], 403);
            }

            // Delete all sacraments
            $deleted = $this->service->bulkDelete($ids);

            Log::info('Bulk delete performed', [
                'count' => count($ids),
                'deleted_by' => $user->id
            ]);

            return response()->json([
                'success' => true,
                'message' => "Successfully deleted {$deleted} sacrament(s)",
                'data' => [
                    'deleted_count' => $deleted
                ]
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error in bulk delete', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id ?? null
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to delete sacraments',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred'
            ], 500);
        }
    }
}


