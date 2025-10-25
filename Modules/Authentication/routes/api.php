<?php

use Illuminate\Support\Facades\Route;
use Modules\Authentication\Http\Controllers\AuthenticationController;
use Modules\Authentication\Http\Controllers\UserController;
use App\Http\Controllers\UsersController;

/*
Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('authentications', AuthenticationController::class)->names('authentication');
});*/

// Authentication routes
Route::prefix('auth')->group(function () {
    // Public routes (no authentication required)
    Route::post('register', [AuthenticationController::class, 'register']);
    Route::post('login', [AuthenticationController::class, 'login']);
    Route::post('refresh', [AuthenticationController::class, 'refresh']); // Refresh token doesn't need auth

    // Protected routes (require authentication)
    Route::middleware('auth:api')->group(function () {
        Route::post('logout', [AuthenticationController::class, 'logout']);
        Route::get('user', [AuthenticationController::class, 'user']);
        Route::get('get-user', [AuthenticationController::class, 'getUser']);
    });
});

// Users management routes (tenant-isolated)
Route::middleware('auth:api')->group(function () {
    Route::get('/users', [UsersController::class, 'index']);
    Route::get('/users/statistics', [UsersController::class, 'statistics']);
    Route::get('/users/{id}', [UsersController::class, 'show'])
        ->where('id', '[0-9]+');
    Route::patch('/users/{id}/status', [UsersController::class, 'updateStatus'])
        ->where('id', '[0-9]+');
});

// Multi-Role User Management API (Tenant-Isolated)
// These routes enable tenant administrators to manage users with multiple role assignments
Route::middleware('auth:api')->prefix('tenant')->group(function () {
    // User CRUD Operations
    Route::get('/users', [UserController::class, 'index'])
        ->name('tenant.users.index');
    
    Route::post('/users', [UserController::class, 'store'])
        ->name('tenant.users.store');
    
    Route::get('/users/{id}', [UserController::class, 'show'])
        ->where('id', '[0-9]+')
        ->name('tenant.users.show');
    
    Route::put('/users/{id}', [UserController::class, 'update'])
        ->where('id', '[0-9]+')
        ->name('tenant.users.update');
    
    Route::delete('/users/{id}', [UserController::class, 'destroy'])
        ->where('id', '[0-9]+')
        ->name('tenant.users.destroy');
    
    // Role Assignment Operations
    Route::post('/users/{id}/roles', [UserController::class, 'assignRoles'])
        ->where('id', '[0-9]+')
        ->name('tenant.users.assign-roles');
    
    // Permission Query Operations
    Route::get('/users/{id}/permissions', [UserController::class, 'permissions'])
        ->where('id', '[0-9]+')
        ->name('tenant.users.permissions');
});