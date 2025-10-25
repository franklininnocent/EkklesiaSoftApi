<?php

use Illuminate\Support\Facades\Route;
use Modules\RolesAndPermissions\Http\Controllers\RolesAndPermissionsController;
use Modules\RolesAndPermissions\Http\Controllers\PermissionsController;

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
Route::prefix('permissions')->middleware('auth:api')->group(function () {
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
