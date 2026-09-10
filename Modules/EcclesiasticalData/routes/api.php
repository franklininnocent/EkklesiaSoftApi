<?php

use Illuminate\Support\Facades\Route;
use Modules\EcclesiasticalData\Http\Controllers\AdminBishopUpdateRequestController;
use Modules\EcclesiasticalData\Http\Controllers\BishopAppointmentController;
use Modules\EcclesiasticalData\Http\Controllers\BishopController;
use Modules\EcclesiasticalData\Http\Controllers\ChurchBishopUpdateRequestController;
use Modules\EcclesiasticalData\Http\Controllers\DioceseController;
use Modules\EcclesiasticalData\Http\Controllers\DioceseLeadershipController;
use Modules\EcclesiasticalData\Http\Controllers\EcclesiasticalLeadershipController;
use Modules\EcclesiasticalData\Http\Controllers\EcclesiasticalTitleController;
use Modules\EcclesiasticalData\Http\Controllers\SacramentTypeController;
use Modules\EcclesiasticalData\Http\Middleware\EnsureUserIsEkklesia;

/*
|--------------------------------------------------------------------------
| Ecclesiastical Data Management API Routes
|--------------------------------------------------------------------------
|
| Platform master data — Ekklesia users only. Granular permissions via
| ecclesiastical.permission middleware on sensitive endpoints.
|
*/

Route::prefix('ecclesiastical')->middleware(['auth:api', EnsureUserIsEkklesia::class])->group(function () {

    Route::get('/leadership/current', [EcclesiasticalLeadershipController::class, 'current'])
        ->middleware('ecclesiastical.permission:dioceses.view')
        ->name('ecclesiastical.leadership.current');

    // Diocese Management Routes
    Route::prefix('dioceses')->group(function () {
        Route::get('/', [DioceseController::class, 'index'])
            ->middleware('ecclesiastical.permission:dioceses.view')
            ->name('ecclesiastical.dioceses.index');
        Route::post('/', [DioceseController::class, 'store'])
            ->middleware('ecclesiastical.permission:dioceses.create')
            ->name('ecclesiastical.dioceses.store');
        Route::get('/statistics', [DioceseController::class, 'statistics'])
            ->middleware('ecclesiastical.permission:dioceses.view')
            ->name('ecclesiastical.dioceses.statistics');
        Route::get('/archdioceses', [DioceseController::class, 'archdioceses'])
            ->middleware('ecclesiastical.permission:dioceses.view')
            ->name('ecclesiastical.archdioceses');
        Route::get('/country/{countryId}', [DioceseController::class, 'byCountry'])
            ->middleware('ecclesiastical.permission:dioceses.view')
            ->name('ecclesiastical.dioceses.by-country');

        Route::get('/{id}/leadership', [DioceseLeadershipController::class, 'current'])
            ->middleware('ecclesiastical.permission:dioceses.view')
            ->name('ecclesiastical.dioceses.leadership');
        Route::get('/{id}/leadership/history', [DioceseLeadershipController::class, 'history'])
            ->middleware('ecclesiastical.permission:dioceses.view')
            ->name('ecclesiastical.dioceses.leadership.history');
        Route::get('/{id}/leadership/ordinary-on-date', [DioceseLeadershipController::class, 'ordinaryOnDate'])
            ->middleware('ecclesiastical.permission:dioceses.view')
            ->name('ecclesiastical.dioceses.leadership.ordinary-on-date');
        Route::post('/{id}/succession/replace-ordinary', [DioceseLeadershipController::class, 'replaceOrdinary'])
            ->middleware('ecclesiastical.permission:bishops.manage_appointments')
            ->name('ecclesiastical.dioceses.succession.replace-ordinary');

        Route::get('/{id}', [DioceseController::class, 'show'])
            ->middleware('ecclesiastical.permission:dioceses.view')
            ->name('ecclesiastical.dioceses.show');
        Route::put('/{id}', [DioceseController::class, 'update'])
            ->middleware('ecclesiastical.permission:dioceses.update')
            ->name('ecclesiastical.dioceses.update');
        Route::delete('/{id}', [DioceseController::class, 'destroy'])
            ->middleware('ecclesiastical.permission:dioceses.delete')
            ->name('ecclesiastical.dioceses.destroy');
        Route::get('/{id}/audit-history', [DioceseController::class, 'auditHistory'])
            ->middleware('ecclesiastical.permission:dioceses.view')
            ->name('ecclesiastical.dioceses.audit');
    });

    Route::get('/titles', [EcclesiasticalTitleController::class, 'index'])
        ->middleware('ecclesiastical.permission:bishops.view')
        ->name('ecclesiastical.titles.index');

    // Bishop Management Routes
    Route::prefix('bishops')->group(function () {
        Route::get('/', [BishopController::class, 'index'])
            ->middleware('ecclesiastical.permission:bishops.view')
            ->name('ecclesiastical.bishops.index');
        Route::post('/', [BishopController::class, 'store'])
            ->middleware('ecclesiastical.permission:bishops.create')
            ->name('ecclesiastical.bishops.store');
        Route::get('/statistics', [BishopController::class, 'statistics'])
            ->middleware('ecclesiastical.permission:bishops.view')
            ->name('ecclesiastical.bishops.statistics');
        Route::get('/diocese/{dioceseId}', [BishopController::class, 'byDiocese'])
            ->middleware('ecclesiastical.permission:bishops.view')
            ->name('ecclesiastical.bishops.by-diocese');
        Route::get('/title/{titleId}', [BishopController::class, 'byTitle'])
            ->middleware('ecclesiastical.permission:bishops.view')
            ->name('ecclesiastical.bishops.by-title');

        Route::get('/{id}/appointments', [BishopAppointmentController::class, 'indexForBishop'])
            ->middleware('ecclesiastical.permission:bishops.view')
            ->name('ecclesiastical.bishops.appointments.index');
        Route::post('/{bishopId}/appointments', [BishopAppointmentController::class, 'store'])
            ->middleware('ecclesiastical.permission:bishops.manage_appointments')
            ->name('ecclesiastical.bishops.appointments.store');
        Route::post('/{id}/upload-photo', [BishopController::class, 'uploadPhoto'])
            ->middleware('ecclesiastical.permission:bishops.manage_images')
            ->name('ecclesiastical.bishops.upload-photo');
        Route::post('/{id}/upload-coat-of-arms', [BishopController::class, 'uploadCoatOfArms'])
            ->middleware('ecclesiastical.permission:bishops.manage_images')
            ->name('ecclesiastical.bishops.upload-coat-of-arms');
        Route::delete('/{id}/photo', [BishopController::class, 'deletePhoto'])
            ->middleware('ecclesiastical.permission:bishops.manage_images')
            ->name('ecclesiastical.bishops.delete-photo');

        Route::get('/{id}', [BishopController::class, 'show'])
            ->middleware('ecclesiastical.permission:bishops.view')
            ->name('ecclesiastical.bishops.show');
        Route::put('/{id}', [BishopController::class, 'update'])
            ->middleware('ecclesiastical.permission:bishops.update')
            ->name('ecclesiastical.bishops.update');
        Route::delete('/{id}', [BishopController::class, 'destroy'])
            ->middleware('ecclesiastical.permission:bishops.archive')
            ->name('ecclesiastical.bishops.destroy');
        Route::get('/{id}/audit-history', [BishopController::class, 'auditHistory'])
            ->middleware('ecclesiastical.permission:bishops.view_audit')
            ->name('ecclesiastical.bishops.audit');
    });

    // Episcopal appointment routes (by appointment id)
    Route::prefix('appointments')->group(function () {
        Route::get('/{id}', [BishopAppointmentController::class, 'show'])
            ->middleware('ecclesiastical.permission:bishops.view')
            ->name('ecclesiastical.appointments.show');
        Route::put('/{id}', [BishopAppointmentController::class, 'update'])
            ->middleware('ecclesiastical.permission:bishops.manage_appointments')
            ->name('ecclesiastical.appointments.update');
        Route::post('/{id}/end', [BishopAppointmentController::class, 'end'])
            ->middleware('ecclesiastical.permission:bishops.manage_appointments')
            ->name('ecclesiastical.appointments.end');
        Route::post('/{id}/activate', [BishopAppointmentController::class, 'activate'])
            ->middleware('ecclesiastical.permission:bishops.manage_appointments')
            ->name('ecclesiastical.appointments.activate');
        Route::post('/{id}/activate-succession', [BishopAppointmentController::class, 'activateSuccession'])
            ->middleware('ecclesiastical.permission:bishops.manage_appointments')
            ->name('ecclesiastical.appointments.activate-succession');
    });

    // Admin bishop update review queue
    Route::prefix('bishop-update-requests')->group(function () {
        Route::get('/', [AdminBishopUpdateRequestController::class, 'index'])
            ->middleware('ecclesiastical.permission:bishops.review_requests')
            ->name('ecclesiastical.bishop-update-requests.index');
        Route::get('/{id}', [AdminBishopUpdateRequestController::class, 'show'])
            ->middleware('ecclesiastical.permission:bishops.review_requests')
            ->name('ecclesiastical.bishop-update-requests.show');
        Route::post('/{id}/under-review', [AdminBishopUpdateRequestController::class, 'markUnderReview'])
            ->middleware('ecclesiastical.permission:bishops.review_requests')
            ->name('ecclesiastical.bishop-update-requests.under-review');
        Route::post('/{id}/request-clarification', [AdminBishopUpdateRequestController::class, 'requestClarification'])
            ->middleware('ecclesiastical.permission:bishops.request_clarification')
            ->name('ecclesiastical.bishop-update-requests.request-clarification');
        Route::post('/{id}/reject', [AdminBishopUpdateRequestController::class, 'reject'])
            ->middleware('ecclesiastical.permission:bishops.reject_requests')
            ->name('ecclesiastical.bishop-update-requests.reject');
        Route::post('/{id}/approve', [AdminBishopUpdateRequestController::class, 'approve'])
            ->middleware('ecclesiastical.permission:bishops.approve_requests')
            ->name('ecclesiastical.bishop-update-requests.approve');
    });

    // Sacrament Types Management Routes (Master Data)
    Route::prefix('sacrament-types')->group(function () {
        Route::get('/', [SacramentTypeController::class, 'index'])->name('ecclesiastical.sacrament-types.index');
        Route::post('/', [SacramentTypeController::class, 'store'])->name('ecclesiastical.sacrament-types.store');
        Route::get('/statistics', [SacramentTypeController::class, 'statistics'])->name('ecclesiastical.sacrament-types.statistics');
        Route::get('/{id}', [SacramentTypeController::class, 'show'])->name('ecclesiastical.sacrament-types.show');
        Route::put('/{id}', [SacramentTypeController::class, 'update'])->name('ecclesiastical.sacrament-types.update');
        Route::delete('/{id}', [SacramentTypeController::class, 'destroy'])->name('ecclesiastical.sacrament-types.destroy');
    });
});

// Church (tenant) bishop update workflow — never edits authoritative platform data directly.
Route::prefix('tenant/bishop-updates')->middleware(['auth:api'])->group(function () {
    Route::get('/leadership', [ChurchBishopUpdateRequestController::class, 'leadership'])
        ->name('tenant.bishop-updates.leadership');

    Route::get('/', [ChurchBishopUpdateRequestController::class, 'index'])
        ->middleware('tenant.permission:bishops.view_own_requests')
        ->name('tenant.bishop-updates.index');
    Route::post('/', [ChurchBishopUpdateRequestController::class, 'store'])
        ->middleware('tenant.permission:bishops.submit_update_request')
        ->name('tenant.bishop-updates.store');
    Route::get('/{id}', [ChurchBishopUpdateRequestController::class, 'show'])
        ->middleware('tenant.permission:bishops.view_own_requests')
        ->name('tenant.bishop-updates.show');
    Route::put('/{id}', [ChurchBishopUpdateRequestController::class, 'update'])
        ->middleware('tenant.permission:bishops.submit_update_request')
        ->name('tenant.bishop-updates.update');
    Route::post('/{id}/submit', [ChurchBishopUpdateRequestController::class, 'submit'])
        ->middleware('tenant.permission:bishops.submit_update_request')
        ->name('tenant.bishop-updates.submit');
    Route::post('/{id}/photo', [ChurchBishopUpdateRequestController::class, 'uploadPhoto'])
        ->middleware('tenant.permission:bishops.submit_update_request')
        ->name('tenant.bishop-updates.photo');
});
