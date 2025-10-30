<?php

use Illuminate\Support\Facades\Route;
use Modules\Authentication\Http\Controllers\AuthenticationController;
use Modules\Authentication\Http\Controllers\UserController;

/*
 * Authentication Module Routes
 * 
 * This file defines all authentication and user management routes.
 * All routes are tenant-isolated and require proper permissions.
 */

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

// User Management Routes (Tenant-Isolated)
// All user operations require authentication and respect tenant boundaries
Route::middleware('auth:api')->prefix('users')->group(function () {
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
});