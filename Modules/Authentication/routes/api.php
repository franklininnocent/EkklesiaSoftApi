<?php

use Illuminate\Support\Facades\Route;
use Modules\Authentication\Http\Controllers\AuthenticationController;
use Modules\Authentication\Http\Controllers\PasswordRecoveryController;
use Modules\Authentication\Http\Controllers\PasswordRecoveryRequestController;
use Modules\Authentication\Http\Controllers\UserController;
use Modules\Authentication\Http\Controllers\PasswordController;

/*
 * Authentication Module Routes
 *
 * This file defines all authentication and user management routes.
 * All routes are tenant-isolated and require proper permissions.
 */

// Authentication routes
Route::prefix('auth')->group(function () {
    // CORS preflight support for auth endpoints.
    Route::options('{any}', function () {
        return response()->noContent();
    })->where('any', '.*');

    // Public routes (no authentication required)
    Route::post('register', [AuthenticationController::class, 'register'])->middleware('api.throttle:auth');
    Route::post('login', [AuthenticationController::class, 'login'])->middleware('api.throttle:auth');
    Route::post('refresh', [AuthenticationController::class, 'refresh'])->middleware('api.throttle:auth');
    Route::post('logout', [AuthenticationController::class, 'logout']);

    Route::post('password/recovery/request', [PasswordRecoveryController::class, 'request'])
        ->middleware('api.throttle:password_recovery')
        ->name('auth.password.recovery.request');

    // Protected routes (require authentication)
    Route::middleware('auth:api')->group(function () {
        Route::get('user', [AuthenticationController::class, 'user']);
        Route::get('get-user', [AuthenticationController::class, 'getUser']);
        Route::post('password/change', [PasswordController::class, 'changeOwn'])
            ->middleware('api.throttle:password')
            ->name('auth.password.change');
        Route::post('profile-image', [AuthenticationController::class, 'uploadSelfProfileImage'])
            ->middleware('api.throttle:uploads')
            ->name('auth.upload-self-profile-image');
        Route::delete('profile-image', [AuthenticationController::class, 'deleteSelfProfileImage'])
            ->middleware('api.throttle:uploads')
            ->name('auth.delete-self-profile-image');

        Route::prefix('password-recovery-requests')->middleware('api.throttle:password')->group(function () {
            Route::get('/', [PasswordRecoveryRequestController::class, 'index'])
                ->name('auth.password.recovery.requests.index');
            Route::get('{id}', [PasswordRecoveryRequestController::class, 'show'])
                ->whereUuid('id')
                ->name('auth.password.recovery.requests.show');
            Route::post('{id}/approve', [PasswordRecoveryRequestController::class, 'approve'])
                ->whereUuid('id')
                ->name('auth.password.recovery.requests.approve');
            Route::post('{id}/reject', [PasswordRecoveryRequestController::class, 'reject'])
                ->whereUuid('id')
                ->name('auth.password.recovery.requests.reject');
            Route::post('{id}/retry-delivery', [PasswordRecoveryRequestController::class, 'retryDelivery'])
                ->whereUuid('id')
                ->name('auth.password.recovery.requests.retry');
        });
    });
});

// User Management Routes (Tenant-Isolated)
// All user operations require authentication and respect tenant boundaries
Route::middleware('auth:api')->prefix('users')->group(function () {
    Route::get('/linkable-clergy', [UserController::class, 'linkableClergy'])
        ->name('users.linkable-clergy');

    Route::get('/statistics', [UserController::class, 'statistics'])
        ->name('users.statistics');

    // User CRUD Operations
    Route::get('/', [UserController::class, 'index'])
        ->name('users.index');

    Route::post('/', [UserController::class, 'store'])
        ->name('users.store');

    Route::get('/{id}', [UserController::class, 'show'])
        ->where('id', '[0-9]+')
        ->name('users.show');

    Route::put('/{id}', [UserController::class, 'update'])
        ->where('id', '[0-9]+')
        ->name('users.update');

    Route::post('/{id}/password/reset', [PasswordController::class, 'adminReset'])
        ->where('id', '[0-9]+')
        ->middleware('api.throttle:password')
        ->name('users.password.reset');

    Route::delete('/{id}', [UserController::class, 'destroy'])
        ->where('id', '[0-9]+')
        ->name('users.destroy');

    // Role Management Operations
    Route::post('/{id}/roles', [UserController::class, 'assignRoles'])
        ->where('id', '[0-9]+')
        ->name('users.assign-roles');

    // Permission Query Operations
    Route::get('/{id}/permissions', [UserController::class, 'permissions'])
        ->where('id', '[0-9]+')
        ->name('users.permissions');

    // Status Update Operation
    Route::patch('/{id}/status', [UserController::class, 'updateStatus'])
        ->where('id', '[0-9]+')
        ->name('users.update-status');

    Route::post('/{id}/profile-image', [UserController::class, 'uploadProfileImage'])
        ->where('id', '[0-9]+')
        ->middleware('api.throttle:uploads')
        ->name('users.upload-profile-image');

    Route::delete('/{id}/profile-image', [UserController::class, 'deleteProfileImage'])
        ->where('id', '[0-9]+')
        ->middleware('api.throttle:uploads')
        ->name('users.delete-profile-image');
});
