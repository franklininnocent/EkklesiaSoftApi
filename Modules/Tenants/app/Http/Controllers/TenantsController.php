<?php

namespace Modules\Tenants\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Models\TenantStatusAudit;
use Modules\Tenants\Http\Requests\StoreTenantRequest;
use Modules\Tenants\Http\Requests\UpdateTenantRequest;
use Modules\Tenants\Services\FileUploadService;
use Modules\Tenants\Services\AddressService;
use Modules\Authentication\Models\User;
use Modules\Authentication\Models\Role;

class TenantsController extends Controller
{
    protected $fileUploadService;
    protected $addressService;

    public function __construct(
        FileUploadService $fileUploadService,
        AddressService $addressService
    ) {
        $this->fileUploadService = $fileUploadService;
        $this->addressService = $addressService;
    }

    /**
     * List all tenants with pagination and filters.
     * 
     * @route GET /api/tenant/list
     */
    public function list(Request $request): JsonResponse
    {
        try {
            // Check authorization
            if (!$this->canManageTenants()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only SuperAdmin and EkklesiaAdmin can manage tenants.',
                ], 403);
            }

            $query = Tenant::query();

            // Apply filters
            if ($request->has('active')) {
                $query->where('active', $request->active);
            }

            if ($request->has('plan')) {
                $query->where('plan', $request->plan);
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('slug', 'like', "%{$search}%")
                      ->orWhereHas('primaryContact', function($q) use ($search) {
                          $q->where('name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                      });
                });
            }

            // Sorting
            $sortBy = $request->get('sort_by', 'created_at');
            $sortOrder = $request->get('sort_order', 'desc');
            $query->orderBy($sortBy, $sortOrder);

            // Eager load relationships
            $query->with(['creator', 'updater', 'addresses', 'primaryContact.addresses', 'secondaryContact.addresses']);

            // Pagination
            $perPage = $request->get('per_page', 15);
            
            if ($perPage === 'all') {
                $tenants = $query->get();
                
                // Generate full logo URLs for all tenants
                $tenants->each(function ($tenant) {
                    if ($tenant->logo_url) {
                        $tenant->logo_full_url = $this->fileUploadService->getTenantLogoUrl($tenant->logo_url);
                    }
                });
                
                $result = [
                    'success' => true,
                    'data' => $tenants,
                    'total' => $tenants->count(),
                ];
            } else {
                $tenants = $query->paginate($perPage);
                
                // Generate full logo URLs for all tenants
                $tenants->getCollection()->transform(function ($tenant) {
                    if ($tenant->logo_url) {
                        $tenant->logo_full_url = $this->fileUploadService->getTenantLogoUrl($tenant->logo_url);
                    }
                    return $tenant;
                });
                
                $result = [
                    'success' => true,
                    'data' => $tenants->items(),
                    'pagination' => [
                        'current_page' => $tenants->currentPage(),
                        'last_page' => $tenants->lastPage(),
                        'per_page' => $tenants->perPage(),
                        'total' => $tenants->total(),
                        'from' => $tenants->firstItem(),
                        'to' => $tenants->lastItem(),
                    ],
                ];
            }

            return response()->json($result);
        } catch (\Exception $e) {
            Log::error('Error fetching tenants list: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Error fetching tenants',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get a specific tenant by ID.
     * 
     * @route GET /api/tenant/{id}
     */
    public function show($id): JsonResponse
    {
        try {
            if (!$this->canManageTenants()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $tenant = Tenant::with([
                'creator', 
                'updater', 
                'users',
                'primaryContact.addresses',
                'secondaryContact.addresses',
                'addresses'
            ])->findOrFail($id);

            // Generate full logo URL
            if ($tenant->logo_url) {
                $tenant->logo_full_url = $this->fileUploadService->getTenantLogoUrl($tenant->logo_url);
            }

            return response()->json([
                'success' => true,
                'data' => $tenant,
                'stats' => [
                    'total_users' => $tenant->users()->count(),
                    'active_users' => $tenant->users()->where('active', 1)->count(),
                    'remaining_slots' => $tenant->getRemainingUserSlots(),
                    'has_active_subscription' => $tenant->hasActiveSubscription(),
                    'is_in_trial' => $tenant->isInTrial(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching tenant: ' . $e->getMessage(), [
                'tenant_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'success' => false,
                'message' => 'Tenant not found',
                'error' => config('app.debug') ? $e->getMessage() : 'Tenant not found',
            ], 404);
        }
    }

    /**
     * Create a new tenant with normalized structure.
     * 
     * @route POST /api/tenant
     */
    public function store(StoreTenantRequest $request): JsonResponse
    {
        DB::beginTransaction();
        
        try {
            if (!$this->canManageTenants()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            // Step 1: Create the tenant
            $tenantData = [
                'name' => $request->tenant_name,
                'slogan' => $request->slogan,
                'slug' => $request->slug ?? Str::slug($request->tenant_name),
                'domain' => $request->domain,
                'plan' => $request->plan ?? 'free',
                'max_users' => $request->max_users ?? 10,
                'max_storage_mb' => $request->max_storage_mb ?? 100,
                'trial_ends_at' => $request->trial_ends_at,
                'subscription_ends_at' => $request->subscription_ends_at,
                'primary_color' => $request->primary_color ?? '#3B82F6',
                'secondary_color' => $request->secondary_color ?? '#10B981',
                'settings' => $request->settings ?? [],
                'features' => $request->features ?? [],
            ];

            // Create tenant first (needed for tenant ID in file path)
            $tenant = Tenant::create($tenantData);

            // SECURITY: Handle logo upload AFTER tenant creation with tenant-specific path
            if ($request->hasFile('tenant_logo')) {
                $logoPath = $this->fileUploadService->uploadTenantLogo(
                    $request->file('tenant_logo'),
                    $tenant->id,
                    null
                );
                if ($logoPath) {
                    $tenant->logo_url = $logoPath;
                    $tenant->save();
                }
            }

            // Step 1.5: Create tenant official address (stored as 'official' type)
            if ($request->tenant_official_address && is_array($request->tenant_official_address)) {
                $this->addressService->create(
                    $tenant,
                    $request->tenant_official_address,
                    'official',  // Mark as tenant's official address
                    true  // Set as default
                );
            }

            // Step 2: Create Administrator role for the tenant
            // Each tenant gets their own "Administrator" role (unique per tenant via composite constraint)
            $adminRole = Role::create([
                'name' => 'Administrator',
                'description' => "{$tenant->name} Super Administrator",
                'level' => 1,
                'tenant_id' => $tenant->id,
                'is_custom' => false, // System-level role for tenant administration
                'active' => 1,
            ]);

            Log::info('Administrator role created for tenant', [
                'tenant_id' => $tenant->id,
                'role_id' => $adminRole->id,
                'role_name' => $adminRole->name,
            ]);

            // Step 2.1: Assign all tenant-relevant permissions to Administrator role
            // Get all active system permissions (excluding custom permissions)
            $tenantPermissions = \Modules\RolesAndPermissions\Models\Permission::where('active', 1)
                ->where('is_custom', false)  // Only system permissions
                ->whereNull('tenant_id')      // Global permissions
                ->pluck('id')
                ->toArray();

            if (!empty($tenantPermissions)) {
                // Assign all permissions to the Administrator role
                $adminRole->permissions()->sync($tenantPermissions);
                
                Log::info('Permissions assigned to Administrator role', [
                    'tenant_id' => $tenant->id,
                    'role_id' => $adminRole->id,
                    'permissions_count' => count($tenantPermissions),
                ]);
            } else {
                Log::warning('No system permissions found to assign to Administrator role', [
                    'tenant_id' => $tenant->id,
                    'role_id' => $adminRole->id,
                ]);
            }

            // Step 3: Create primary contact user with Administrator role
            $primaryUser = User::create([
                'tenant_id' => $tenant->id,
                'name' => $request->primary_user_name,
                'email' => $request->primary_user_email,
                'contact_number' => $request->primary_contact_number,
                'user_type' => User::USER_TYPE_PRIMARY_CONTACT,  // 1 = primary_contact
                'is_primary_admin' => true,  // Mark as primary admin - cannot be deleted/deactivated by tenant users
                'role_id' => $adminRole->id,  // Assign Administrator role
                'password' => Hash::make('TempPassword123!'), // Temporary password
                'active' => 1,
            ]);

            Log::info('Primary user created and assigned Administrator role', [
                'tenant_id' => $tenant->id,
                'user_id' => $primaryUser->id,
                'role_id' => $adminRole->id,
                'user_email' => $primaryUser->email,
            ]);

            // Step 4: Create primary contact address
            if ($request->primary_user_address && is_array($request->primary_user_address)) {
                $this->addressService->create(
                    $primaryUser,
                    $request->primary_user_address,
                    'primary',
                    true  // Set as default
                );
            }

            // Step 5: Create secondary contact user (if provided)
            if ($request->secondary_user_name && $request->secondary_user_email) {
                $secondaryUser = User::create([
                    'tenant_id' => $tenant->id,
                    'name' => $request->secondary_user_name,
                    'email' => $request->secondary_user_email,
                    'contact_number' => $request->secondary_contact_number,
                    'user_type' => User::USER_TYPE_SECONDARY_CONTACT,  // 2 = secondary_contact
                    'password' => Hash::make('TempPassword123!'), // Temporary password
                    'active' => 1,
                ]);

                // Step 6: Create secondary contact address (if provided)
                if ($request->secondary_user_address && is_array($request->secondary_user_address)) {
                    $this->addressService->create(
                        $secondaryUser,
                        $request->secondary_user_address,
                        'primary',  // Primary address for secondary user
                        true  // Set as default
                    );
                }
            }

            // Reload tenant with all relationships
            $tenant = Tenant::with([
                'addresses',  // Include tenant's official address
                'primaryContact.addresses',
                'secondaryContact.addresses'
            ])->find($tenant->id);

            Log::info('Tenant created successfully with normalized structure', [
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
                'primary_user_id' => $primaryUser->id,
                'created_by' => auth()->id(),
            ]);

            // Generate full logo URL for response
            if ($tenant->logo_url) {
                $tenant->logo_full_url = $this->fileUploadService->getTenantLogoUrl($tenant->logo_url);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Tenant created successfully',
                'data' => $tenant,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Error creating tenant: ' . $e->getMessage(), [
                'request_data' => $request->except(['tenant_logo']),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error creating tenant',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Update an existing tenant with normalized structure.
     * 
     * @route PUT /api/tenant/{id}
     */
    public function update(UpdateTenantRequest $request, $id): JsonResponse
    {
        DB::beginTransaction();
        
        try {
            if (!$this->canManageTenants()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $tenant = Tenant::with(['primaryContact', 'secondaryContact'])->findOrFail($id);

            // Step 1: Update tenant basic info
            $tenantUpdateData = [];
            
            foreach (['tenant_name' => 'name', 'slogan', 'slug', 'domain', 'plan', 'max_users', 
                     'max_storage_mb', 'trial_ends_at', 'subscription_ends_at', 'active', 
                     'primary_color', 'secondary_color', 'settings', 'features'] as $key => $dbKey) {
                $requestKey = is_int($key) ? $dbKey : $key;
                if ($request->has($requestKey)) {
                    $tenantUpdateData[$dbKey] = $request->$requestKey;
                }
            }

            // SECURITY: Handle logo upload with tenant-specific path
            if ($request->hasFile('tenant_logo')) {
                $logoPath = $this->fileUploadService->uploadTenantLogo(
                    $request->file('tenant_logo'),
                    $tenant->id,
                    $tenant->logo_url
                );
                if ($logoPath) {
                    $tenantUpdateData['logo_url'] = $logoPath;
                }
            }

            if (!empty($tenantUpdateData)) {
                $tenant->update($tenantUpdateData);
            }

            // Step 1.5: Update tenant official address
            if ($request->has('tenant_official_address') && is_array($request->tenant_official_address)) {
                $tenantAddress = $tenant->addresses()
                    ->where('address_type', 'official')
                    ->first();
                
                if ($tenantAddress) {
                    // Update existing address
                    $this->addressService->update($tenantAddress, $request->tenant_official_address);
                } else {
                    // Create new address
                    $this->addressService->create(
                        $tenant,
                        $request->tenant_official_address,
                        'official',
                        true
                    );
                }
            }

            // Step 2: Update primary contact user (if exists)
            if ($tenant->primaryContact) {
                $primaryUserUpdate = [];
                if ($request->has('primary_user_name')) {
                    $primaryUserUpdate['name'] = $request->primary_user_name;
                }
                if ($request->has('primary_user_email')) {
                    $primaryUserUpdate['email'] = $request->primary_user_email;
                }
                if ($request->has('primary_contact_number')) {
                    $primaryUserUpdate['contact_number'] = $request->primary_contact_number;
                }
                
                if (!empty($primaryUserUpdate)) {
                    $tenant->primaryContact->update($primaryUserUpdate);
                }

                // Update primary contact address
                if ($request->has('primary_user_address') && is_array($request->primary_user_address)) {
                    $primaryAddress = $tenant->primaryContact->addresses()
                        ->where('address_type', 'primary')
                        ->first();
                    
                    if ($primaryAddress) {
                        $this->addressService->update($primaryAddress, $request->primary_user_address);
                    } else {
                        $this->addressService->create(
                            $tenant->primaryContact,
                            $request->primary_user_address,
                            'primary',
                            true
                        );
                    }
                }
            }

            // Step 3: Update secondary contact user
            if ($request->has('secondary_user_name') && $request->has('secondary_user_email')) {
                if ($tenant->secondaryContact) {
                    // Update existing secondary contact
                    $secondaryUserUpdate = [
                        'name' => $request->secondary_user_name,
                        'email' => $request->secondary_user_email,
                    ];
                    if ($request->has('secondary_contact_number')) {
                        $secondaryUserUpdate['contact_number'] = $request->secondary_contact_number;
                    }
                    $tenant->secondaryContact->update($secondaryUserUpdate);

                    // Update secondary contact address
                    if ($request->has('secondary_user_address') && is_array($request->secondary_user_address)) {
                        $secondaryAddress = $tenant->secondaryContact->addresses()
                            ->where('address_type', 'primary')
                            ->first();
                        
                        if ($secondaryAddress) {
                            $this->addressService->update($secondaryAddress, $request->secondary_user_address);
                        } else {
                            $this->addressService->create(
                                $tenant->secondaryContact,
                                $request->secondary_user_address,
                                'primary',
                                true
                            );
                        }
                    }
                } else {
                    // Create new secondary contact
                    $secondaryUser = User::create([
                        'tenant_id' => $tenant->id,
                        'name' => $request->secondary_user_name,
                        'email' => $request->secondary_user_email,
                        'contact_number' => $request->secondary_contact_number,
                        'user_type' => User::USER_TYPE_SECONDARY_CONTACT,  // 2 = secondary_contact
                        'password' => Hash::make('TempPassword123!'),
                        'active' => 1,
                    ]);

                    if ($request->has('secondary_user_address') && is_array($request->secondary_user_address)) {
                        $this->addressService->create(
                            $secondaryUser,
                            $request->secondary_user_address,
                            'primary',
                            true
                        );
                    }
                }
            }

            // Reload tenant with relationships
            $tenant = Tenant::with([
                'addresses',  // Include tenant's official address
                'primaryContact.addresses',
                'secondaryContact.addresses'
            ])->find($id);

            Log::info('Tenant updated successfully with normalized structure', [
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
                'updated_by' => auth()->id(),
            ]);

            // Generate full logo URL for response
            if ($tenant->logo_url) {
                $tenant->logo_full_url = $this->fileUploadService->getTenantLogoUrl($tenant->logo_url);
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Tenant updated successfully',
                'data' => $tenant,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Error updating tenant: ' . $e->getMessage(), [
                'tenant_id' => $id,
                'request_data' => $request->except(['tenant_logo']),
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error updating tenant',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Delete a tenant (soft delete).
     * 
     * @route DELETE /api/tenant/{id}
     */
    public function destroy($id): JsonResponse
    {
        DB::beginTransaction();
        
        try {
            if (!$this->canManageTenants()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $tenant = Tenant::findOrFail($id);

            // Check if tenant has users
            $userCount = $tenant->users()->count();
            if ($userCount > 0) {
                return response()->json([
                    'success' => false,
                    'message' => "Cannot delete tenant with {$userCount} existing user(s). Please remove or transfer users first.",
                ], 422);
            }

            // Soft delete
            $tenant->delete();

            Log::warning('Tenant deleted', [
                'tenant_id' => $id,
                'tenant_name' => $tenant->name,
                'deleted_by' => auth()->id(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Tenant deleted successfully',
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            
            Log::error('Error deleting tenant: ' . $e->getMessage(), [
                'tenant_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error deleting tenant',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Update tenant active status.
     * 
     * @route PATCH /api/tenant/{id}/status
     */
    public function updateStatus(Request $request, $id): JsonResponse
    {
        try {
            // Check authorization
            if (!$this->canManageTenants()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized. Only SuperAdmin and EkklesiaAdmin can manage tenants.',
                ], 403);
            }

            // Validate request
            $request->validate([
                'active' => 'required|integer|in:0,1',
                'description' => 'nullable|string|max:500',
            ]);

            // Find tenant
            $tenant = Tenant::find($id);

            if (!$tenant) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant not found',
                ], 404);
            }

            // Store old status for logging
            $oldStatus = $tenant->active;

            // Update status
            $tenant->active = $request->active;
            $tenant->updated_by = auth()->id();
            $tenant->save();

            // Create audit log entry in database
            try {
                TenantStatusAudit::log(
                    tenant: $tenant,
                    previousStatus: $oldStatus,
                    newStatus: $tenant->active,
                    reason: $request->description,
                    performedBy: auth()->id(),
                    additionalData: [
                        'user_name' => auth()->user()->name ?? 'Unknown',
                        'user_email' => auth()->user()->email ?? 'Unknown',
                    ]
                );
            } catch (\Exception $e) {
                // Log error but don't fail the status update
                Log::error('Failed to create audit log entry', [
                    'tenant_id' => $tenant->id,
                    'error' => $e->getMessage(),
                ]);
            }

            // Log status change to application log
            Log::info('Tenant status updated', [
                'tenant_id' => $tenant->id,
                'tenant_name' => $tenant->name,
                'old_status' => $oldStatus,
                'new_status' => $tenant->active,
                'description' => $request->description ?? 'No description provided',
                'updated_by' => auth()->id(),
                'updated_by_name' => auth()->user()->name ?? 'Unknown',
            ]);

            // Load relationships for response
            $tenant->load(['creator', 'updater', 'addresses', 'primaryContact.addresses', 'secondaryContact.addresses']);

            return response()->json([
                'success' => true,
                'message' => 'Tenant status updated successfully',
                'data' => $tenant,
            ], 200);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error updating tenant status: ' . $e->getMessage(), [
                'tenant_id' => $id,
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error updating tenant status',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Upload or update tenant logo.
     * 
     * @route POST /api/tenant/{id}/logo
     */
    public function uploadLogo(Request $request, $id): JsonResponse
    {
        try {
            if (!$this->canManageTenants()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            // Validate file
            $request->validate([
                'logo' => 'required|image|mimes:jpeg,jpg,png,gif,webp|max:5120',
            ]);

            $tenant = Tenant::findOrFail($id);

            // Additional file validation
            $validation = $this->fileUploadService->validateFile($request->file('logo'));
            if (!$validation['valid']) {
                return response()->json([
                    'success' => false,
                    'message' => $validation['error'],
                ], 422);
            }

            // SECURITY: Upload logo with tenant-specific path
            $logoPath = $this->fileUploadService->uploadTenantLogo(
                $request->file('logo'),
                $tenant->id,
                $tenant->logo_url
            );

            if (!$logoPath) {
                return response()->json([
                    'success' => false,
                    'message' => 'Failed to upload logo',
                ], 500);
            }

            // Update tenant
            $tenant->update(['logo_url' => $logoPath]);

            Log::info('Tenant logo uploaded', [
                'tenant_id' => $id,
                'logo_path' => $logoPath,
                'uploaded_by' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Logo uploaded successfully',
                'data' => [
                    'logo_url' => $logoPath,
                    'logo_full_url' => $this->fileUploadService->getTenantLogoUrl($logoPath),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Error uploading logo: ' . $e->getMessage(), [
                'tenant_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error uploading logo',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Delete tenant logo.
     * 
     * @route DELETE /api/tenant/{id}/logo
     */
    public function deleteLogo($id): JsonResponse
    {
        try {
            if (!$this->canManageTenants()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $tenant = Tenant::findOrFail($id);

            if (!$tenant->logo_url) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tenant has no logo',
                ], 404);
            }

            // SECURITY: Delete logo file with ownership verification
            $this->fileUploadService->deleteTenantLogo($tenant->logo_url, $tenant->id);

            // Update tenant
            $tenant->update(['logo_url' => null]);

            Log::info('Tenant logo deleted', [
                'tenant_id' => $id,
                'deleted_by' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Logo deleted successfully',
            ]);
        } catch (\Exception $e) {
            Log::error('Error deleting logo: ' . $e->getMessage(), [
                'tenant_id' => $id,
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error deleting logo',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Get tenant statistics.
     * 
     * @route GET /api/tenant/statistics
     */
    public function statistics(): JsonResponse
    {
        try {
            if (!$this->canManageTenants()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized',
                ], 403);
            }

            $stats = [
                'total_tenants' => Tenant::count(),
                'active_tenants' => Tenant::active()->count(),
                'inactive_tenants' => Tenant::inactive()->count(),
                'tenants_by_plan' => [
                    'free' => Tenant::where('plan', 'free')->count(),
                    'basic' => Tenant::where('plan', 'basic')->count(),
                    'premium' => Tenant::where('plan', 'premium')->count(),
                    'enterprise' => Tenant::where('plan', 'enterprise')->count(),
                ],
                'in_trial' => Tenant::inTrial()->count(),
                'subscribed' => Tenant::subscribed()->count(),
                'recent_tenants' => Tenant::orderBy('created_at', 'desc')->take(5)->get(),
            ];

            return response()->json([
                'success' => true,
                'data' => $stats,
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching tenant statistics: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error fetching statistics',
                'error' => config('app.debug') ? $e->getMessage() : 'Internal server error',
            ], 500);
        }
    }

    /**
     * Check if current user can manage tenants.
     * Only SuperAdmin and EkklesiaAdmin can manage tenants.
     */
    private function canManageTenants(): bool
    {
        if (!auth()->check()) {
            return false;
        }

        $user = auth()->user();
        
        // SuperAdmin and EkklesiaAdmin can manage all tenants
        return $user->isSuperAdmin() || $user->isEkklesiaAdmin();
    }
}
