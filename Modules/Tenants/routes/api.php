<?php

use Illuminate\Support\Facades\Route;
use Modules\Tenants\Http\Controllers\TenantsController;
use Modules\Tenants\Http\Controllers\GeographyController;
use Modules\Tenants\Http\Controllers\SecureFileController;

/*
 *--------------------------------------------------------------------------
 * API Routes - Tenants Module
 *--------------------------------------------------------------------------
 *
 * Tenant management routes for SuperAdmin and EkklesiaAdmin.
 * All routes require authentication via Passport.
 *
 */

Route::middleware('auth:api')->group(function () {
    
    // Geography endpoints (for cascading dropdowns)
    Route::prefix('geography')->group(function () {
        Route::get('/countries', [GeographyController::class, 'getCountries']);
        Route::get('/countries/search', [GeographyController::class, 'searchCountries']);
        Route::get('/countries/{countryId}/states', [GeographyController::class, 'getStatesByCountry'])
            ->where('countryId', '[0-9]+');
        Route::get('/countries/{countryId}/states/search', [GeographyController::class, 'searchStates'])
            ->where('countryId', '[0-9]+');
        Route::post('/clear-cache', [GeographyController::class, 'clearCache']);
    });
    
    // List all tenants
    Route::get('/tenant/list', [TenantsController::class, 'list']);
    
    // Get tenant statistics
    Route::get('/tenant/statistics', [TenantsController::class, 'statistics']);
    
    // Create a new tenant
    Route::post('/tenant', [TenantsController::class, 'store']);
    
    // Get a specific tenant
    Route::get('/tenant/{id}', [TenantsController::class, 'show'])
        ->where('id', '[0-9]+');
    
    // Update a tenant
    Route::put('/tenant/{id}', [TenantsController::class, 'update'])
        ->where('id', '[0-9]+');
    
    // Delete a tenant (soft delete)
    Route::delete('/tenant/{id}', [TenantsController::class, 'destroy'])
        ->where('id', '[0-9]+');
    
    // Update tenant status (activate/deactivate)
    Route::patch('/tenant/{id}/status', [TenantsController::class, 'updateStatus'])
        ->where('id', '[0-9]+');
    
    // Logo management
    Route::post('/tenant/{id}/logo', [TenantsController::class, 'uploadLogo'])
        ->where('id', '[0-9]+');
    Route::delete('/tenant/{id}/logo', [TenantsController::class, 'deleteLogo'])
        ->where('id', '[0-9]+');
    
    // Secure file access with signed URLs
    Route::post('/tenant/files/signed-url', [SecureFileController::class, 'generateSignedUrl'])
        ->name('tenants.files.signed-url');
});

// Public route with signature verification
Route::get('/tenant/files/serve', [SecureFileController::class, 'serveFile'])
    ->middleware('signed')
    ->name('tenants.files.serve');
