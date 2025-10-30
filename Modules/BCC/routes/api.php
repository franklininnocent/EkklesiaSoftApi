<?php

use Illuminate\Support\Facades\Route;
use Modules\BCC\Http\Controllers\BCCController;

/*
 |--------------------------------------------------------------------------
 | API Routes - BCC Module
 |--------------------------------------------------------------------------
 |
 | Here is where you can register API routes for your BCC module.
 | These routes are loaded by the RouteServiceProvider and are assigned
 | the "api" middleware group. Enjoy building your API!
 |
 */

Route::middleware(['auth:api'])->prefix('bccs')->group(function () {
    
    // BCC CRUD Routes
    Route::get('/', [BCCController::class, 'index'])->name('bccs.index');
    Route::post('/', [BCCController::class, 'store'])->name('bccs.store');
    Route::get('/statistics', [BCCController::class, 'statistics'])->name('bccs.statistics');
    Route::get('/with-space', [BCCController::class, 'withSpace'])->name('bccs.with-space');
    Route::get('/{id}', [BCCController::class, 'show'])->name('bccs.show');
    Route::put('/{id}', [BCCController::class, 'update'])->name('bccs.update');
    Route::delete('/{id}', [BCCController::class, 'destroy'])->name('bccs.destroy');
    
    // BCC Leader Routes
    Route::get('/{bccId}/leaders', [BCCController::class, 'leaders'])->name('bccs.leaders.index');
    Route::post('/{bccId}/leaders', [BCCController::class, 'addLeader'])->name('bccs.leaders.store');
    Route::put('/{bccId}/leaders/{leaderId}', [BCCController::class, 'updateLeader'])->name('bccs.leaders.update');
    Route::delete('/{bccId}/leaders/{leaderId}', [BCCController::class, 'deleteLeader'])->name('bccs.leaders.destroy');
    
    // Family Assignment Routes
    Route::post('/{bccId}/assign-families', [BCCController::class, 'assignFamilies'])->name('bccs.assign-families');
    Route::post('/remove-families', [BCCController::class, 'removeFamilies'])->name('bccs.remove-families');
});
