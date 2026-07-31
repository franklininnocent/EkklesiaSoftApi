<?php

use Illuminate\Support\Facades\Route;
use Modules\Authentication\Http\Controllers\UserController;
use Modules\RolesAndPermissions\Http\Controllers\RolesAndPermissionsController;
use Modules\RolesAndPermissions\Http\Controllers\PermissionsController;
use Modules\RolesAndPermissions\Http\Middleware\EnsureTenant;
use Modules\RolesAndPermissions\Http\Middleware\EnsureTenantAdmin;
use Modules\RolesAndPermissions\Http\Middleware\RateLimitPermissionChecks;

/*
 *--------------------------------------------------------------------------
 * API Routes - RolesAndPermissions Module
 *--------------------------------------------------------------------------
 *
 * Role and Permission management routes for SuperAdmin, EkklesiaAdmin, and EkklesiaManager.
 * All routes require authentication.
 *
*/

// ROLES ENDPOINTS
Route::prefix('roles')->middleware('auth:api')->group(function () {
    // List all roles
    Route::get('/', [RolesAndPermissionsController::class, 'index']);
    
    // Get a specific role
    Route::get('/{id}', [RolesAndPermissionsController::class, 'show']);
    
    // Create a new role
    Route::post('/', [RolesAndPermissionsController::class, 'store']);
    
    // Update a role
    Route::put('/{id}', [RolesAndPermissionsController::class, 'update']);
    Route::patch('/{id}', [RolesAndPermissionsController::class, 'update']);
    
    // Delete a role (soft delete)
    Route::delete('/{id}', [RolesAndPermissionsController::class, 'destroy']);
    
    // Restore a soft-deleted role
    Route::post('/{id}/restore', [RolesAndPermissionsController::class, 'restore']);
    
    // Activate/deactivate role
    Route::post('/{id}/activate', [RolesAndPermissionsController::class, 'activate']);
    Route::post('/{id}/deactivate', [RolesAndPermissionsController::class, 'deactivate']);
});

// PERMISSIONS ENDPOINTS
// PERFORMANCE: Apply rate limiting to permission check endpoints
Route::prefix('permissions')->middleware(['auth:api', RateLimitPermissionChecks::class])->group(function () {
    // List all permissions
    Route::get('/', [PermissionsController::class, 'index']);
    
    // Get a specific permission
    Route::get('/{id}', [PermissionsController::class, 'show']);
    
    // Get permissions for a specific role
    Route::get('/role/{roleId}', [PermissionsController::class, 'getPermissionsForRole']);
    
    // Create a new permission
    Route::post('/', [PermissionsController::class, 'store']);
    
    // Update a permission
    Route::put('/{id}', [PermissionsController::class, 'update']);
    Route::patch('/{id}', [PermissionsController::class, 'update']);
    
    // Delete a permission (soft delete)
    Route::delete('/{id}', [PermissionsController::class, 'destroy']);
    
    // Assign/remove permission to/from role
    Route::post('/assign-to-role', [PermissionsController::class, 'assignToRole']);
    Route::post('/remove-from-role', [PermissionsController::class, 'removeFromRole']);
    
    // Bulk assign permissions to role (replaces all existing permissions)
    Route::post('/bulk-assign-to-role', [PermissionsController::class, 'bulkAssignToRole']);
    
    // Assign/remove permission to/from user (direct assignment)
    Route::post('/assign-to-user', [PermissionsController::class, 'assignToUser']);
    Route::post('/remove-from-user', [PermissionsController::class, 'removeFromUser']);
});

// TENANT-SCOPED RBAC ENDPOINTS
Route::prefix('tenant')
    ->middleware(['auth:api', EnsureTenant::class])
    ->group(function () {
        // Roles
        Route::get('/roles', [RolesAndPermissionsController::class, 'index'])->middleware('tenant.permission:roles.view');
        Route::get('/roles/{id}', [RolesAndPermissionsController::class, 'show'])->middleware('tenant.permission:roles.view');
        Route::post('/roles', [RolesAndPermissionsController::class, 'tenantStore'])->middleware([EnsureTenantAdmin::class, 'tenant.permission:roles.create']);
        Route::put('/roles/{id}', [RolesAndPermissionsController::class, 'tenantUpdate'])->middleware([EnsureTenantAdmin::class, 'tenant.permission:roles.update']);
        Route::delete('/roles/{id}', [RolesAndPermissionsController::class, 'tenantDestroy'])->middleware([EnsureTenantAdmin::class, 'tenant.permission:roles.delete']);
        Route::post('/roles/{id}/activate', [RolesAndPermissionsController::class, 'tenantActivate'])->middleware([EnsureTenantAdmin::class, 'tenant.permission:roles.update']);
        Route::post('/roles/{id}/deactivate', [RolesAndPermissionsController::class, 'tenantDeactivate'])->middleware([EnsureTenantAdmin::class, 'tenant.permission:roles.update']);

        // Permissions
        Route::get('/permissions', [PermissionsController::class, 'tenantIndex'])->middleware('tenant.permission:permissions.view');

        // Role permissions
        Route::get('/roles/{roleId}/permissions', [PermissionsController::class, 'getPermissionsForRole'])->middleware('tenant.permission:permissions.view');
        Route::put('/roles/{roleId}/permissions', [PermissionsController::class, 'syncPermissionsForRole'])->middleware([EnsureTenantAdmin::class, 'tenant.permission:permissions.assign']);

        // User roles
        Route::get('/users/{id}/roles', [UserController::class, 'getRoles'])->middleware('tenant.permission:users.view');
        Route::put('/users/{id}/roles', [UserController::class, 'syncTenantRoles'])->middleware([EnsureTenantAdmin::class, 'tenant.permission:roles.assign']);
    });
