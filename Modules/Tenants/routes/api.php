<?php

use Illuminate\Support\Facades\Route;
use Modules\Tenants\Http\Controllers\TenantsController;
use Modules\Tenants\Http\Controllers\GeographyController;
use Modules\Tenants\Http\Controllers\SecureFileController;
use Modules\Tenants\Http\Controllers\DenominationsController;
use Modules\Tenants\Http\Controllers\ArchdiocesesController;
use Modules\Tenants\Http\Controllers\ChurchProfileController;
use Modules\Tenants\Http\Controllers\ChurchLeadershipController;
use Modules\Tenants\Http\Controllers\ChurchStatisticsController;
use Modules\Tenants\Http\Controllers\ChurchSocialMediaController;

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
    
    // Church Profile endpoints - for tenant users to view/edit their own church
    Route::get('/tenant/church-profile', [TenantsController::class, 'getChurchProfile']);
    Route::put('/tenant/church-profile', [TenantsController::class, 'updateChurchProfile']);
    
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
    
    // ================================================================
    // CHURCH MANAGEMENT ENDPOINTS - For Tenant Administrators
    // ================================================================
    
    // Lookup Tables (Read-Only)
    Route::prefix('denominations')->group(function () {
        Route::get('/', [DenominationsController::class, 'index']);
        Route::get('/{id}', [DenominationsController::class, 'show'])->where('id', '[0-9]+');
    });
    
    Route::prefix('archdioceses')->group(function () {
        Route::get('/', [ArchdiocesesController::class, 'index']);
        Route::get('/countries', [ArchdiocesesController::class, 'countries']);
        Route::get('/{id}', [ArchdiocesesController::class, 'show'])->where('id', '[0-9]+');
    });
    
    // Church Profile Management
    Route::prefix('church-profile')->group(function () {
        Route::get('/', [ChurchProfileController::class, 'show']);
        Route::put('/', [ChurchProfileController::class, 'update']);
    });
    
    // Church Leadership Management (Full CRUD)
    Route::prefix('church-leadership')->group(function () {
        Route::get('/', [ChurchLeadershipController::class, 'index']);
        Route::post('/', [ChurchLeadershipController::class, 'store']);
        Route::get('/{id}', [ChurchLeadershipController::class, 'show'])->where('id', '[0-9]+');
        Route::put('/{id}', [ChurchLeadershipController::class, 'update'])->where('id', '[0-9]+');
        Route::delete('/{id}', [ChurchLeadershipController::class, 'destroy'])->where('id', '[0-9]+');
    });
    
    // Church Statistics Management (Full CRUD)
    Route::prefix('church-statistics')->group(function () {
        Route::get('/', [ChurchStatisticsController::class, 'index']);
        Route::post('/', [ChurchStatisticsController::class, 'store']);
        Route::get('/{id}', [ChurchStatisticsController::class, 'show'])->where('id', '[0-9]+');
        Route::put('/{id}', [ChurchStatisticsController::class, 'update'])->where('id', '[0-9]+');
        Route::delete('/{id}', [ChurchStatisticsController::class, 'destroy'])->where('id', '[0-9]+');
    });
    
    // Church Social Media Management (Full CRUD)
    Route::prefix('church-social-media')->group(function () {
        Route::get('/', [ChurchSocialMediaController::class, 'index']);
        Route::post('/', [ChurchSocialMediaController::class, 'store']);
        Route::get('/{id}', [ChurchSocialMediaController::class, 'show'])->where('id', '[0-9]+');
        Route::put('/{id}', [ChurchSocialMediaController::class, 'update'])->where('id', '[0-9]+');
        Route::delete('/{id}', [ChurchSocialMediaController::class, 'destroy'])->where('id', '[0-9]+');
    });
});

// Public route with signature verification
Route::get('/tenant/files/serve', [SecureFileController::class, 'serveFile'])
    ->middleware('signed')
    ->name('tenants.files.serve');
