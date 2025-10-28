<?php

use Illuminate\Support\Facades\Route;
use Modules\EcclesiasticalData\Http\Controllers\DioceseController;
use Modules\EcclesiasticalData\Http\Controllers\BishopController;
use Modules\EcclesiasticalData\Http\Middleware\EnsureUserIsEkklesia;

/*
|--------------------------------------------------------------------------
| Ecclesiastical Data Management API Routes
|--------------------------------------------------------------------------
|
| These routes handle CRUD operations for ecclesiastical master data
| All routes require authentication and Ekklesia role access
| 
| SECURITY: Only Ekklesia users (SuperAdmin, EkklesiaAdmin, EkklesiaManager, EkklesiaUser)
| can access these endpoints. Tenant users are explicitly blocked.
|
*/

Route::prefix('ecclesiastical')->middleware(['auth:api', EnsureUserIsEkklesia::class])->group(function () {
    
    // Diocese Management Routes
    Route::prefix('dioceses')->group(function () {
        Route::get('/', [DioceseController::class, 'index'])->name('ecclesiastical.dioceses.index');
        Route::post('/', [DioceseController::class, 'store'])->name('ecclesiastical.dioceses.store');
        Route::get('/statistics', [DioceseController::class, 'statistics'])->name('ecclesiastical.dioceses.statistics');
        Route::get('/archdioceses', [DioceseController::class, 'archdioceses'])->name('ecclesiastical.archdioceses');
        Route::get('/country/{countryId}', [DioceseController::class, 'byCountry'])->name('ecclesiastical.dioceses.by-country');
        Route::get('/{id}', [DioceseController::class, 'show'])->name('ecclesiastical.dioceses.show');
        Route::put('/{id}', [DioceseController::class, 'update'])->name('ecclesiastical.dioceses.update');
        Route::delete('/{id}', [DioceseController::class, 'destroy'])->name('ecclesiastical.dioceses.destroy');
        Route::get('/{id}/audit-history', [DioceseController::class, 'auditHistory'])->name('ecclesiastical.dioceses.audit');
    });

    // Bishop Management Routes
    Route::prefix('bishops')->group(function () {
        Route::get('/', [BishopController::class, 'index'])->name('ecclesiastical.bishops.index');
        Route::post('/', [BishopController::class, 'store'])->name('ecclesiastical.bishops.store');
        Route::get('/statistics', [BishopController::class, 'statistics'])->name('ecclesiastical.bishops.statistics');
        Route::get('/diocese/{dioceseId}', [BishopController::class, 'byDiocese'])->name('ecclesiastical.bishops.by-diocese');
        Route::get('/title/{titleId}', [BishopController::class, 'byTitle'])->name('ecclesiastical.bishops.by-title');
        Route::get('/{id}', [BishopController::class, 'show'])->name('ecclesiastical.bishops.show');
        Route::put('/{id}', [BishopController::class, 'update'])->name('ecclesiastical.bishops.update');
        Route::delete('/{id}', [BishopController::class, 'destroy'])->name('ecclesiastical.bishops.destroy');
        Route::get('/{id}/audit-history', [BishopController::class, 'auditHistory'])->name('ecclesiastical.bishops.audit');
    });
});
