<?php

use Illuminate\Support\Facades\Route;
use Modules\PastoralCare\Http\Controllers\PastoralCareController;

Route::middleware(['auth:api', 'tenant.permission:pastoral.care.view'])
    ->prefix('tenant/pastoral')
    ->group(function () {
        Route::get('/dashboard', [PastoralCareController::class, 'dashboard'])
            ->name('pastoral.dashboard');
        Route::get('/staff', [PastoralCareController::class, 'staff'])
            ->middleware('tenant.permission:pastoral.care.assign')
            ->name('pastoral.staff');
        Route::get('/requests', [PastoralCareController::class, 'index'])
            ->name('pastoral.requests.index');
        Route::post('/requests', [PastoralCareController::class, 'store'])
            ->middleware('tenant.permission:pastoral.care.create')
            ->name('pastoral.requests.store');
        Route::get('/requests/{id}', [PastoralCareController::class, 'show'])
            ->name('pastoral.requests.show');
        Route::post('/requests/{id}/assign', [PastoralCareController::class, 'assign'])
            ->middleware('tenant.permission:pastoral.care.assign')
            ->name('pastoral.requests.assign');
        Route::post('/requests/{id}/complete', [PastoralCareController::class, 'complete'])
            ->name('pastoral.requests.complete');
        Route::post('/requests/{id}/cancel', [PastoralCareController::class, 'cancel'])
            ->middleware('tenant.permission:pastoral.care.assign')
            ->name('pastoral.requests.cancel');
    });
