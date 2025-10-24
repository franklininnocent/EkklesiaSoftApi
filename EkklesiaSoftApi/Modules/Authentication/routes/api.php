<?php

use Illuminate\Support\Facades\Route;
use Modules\Authentication\Http\Controllers\AuthenticationController;
/*
Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('authentications', AuthenticationController::class)->names('authentication');
});*/
Route::prefix('auth')->group(function () {
    Route::post('register', [AuthenticationController::class, 'register']);
    Route::post('login', [AuthenticationController::class, 'login']);

    Route::middleware('auth:api')->group(function () {
        Route::post('logout', [AuthenticationController::class, 'logout']);
        Route::get('user', [AuthenticationController::class, 'user']);
    });
});