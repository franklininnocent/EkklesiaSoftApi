<?php

use Illuminate\Support\Facades\Route;
use Modules\Family\Http\Controllers\FamilyController;

/*
 |--------------------------------------------------------------------------
 | API Routes - Family Module
 |--------------------------------------------------------------------------
 |
 | Here is where you can register API routes for your Family module.
 | These routes are loaded by the RouteServiceProvider and are assigned
 | the "api" middleware group. Enjoy building your API!
 |
 */

Route::middleware(['auth:api'])->prefix('families')->group(function () {
    
    // Family CRUD Routes
    Route::get('/', [FamilyController::class, 'index'])->name('families.index');
    Route::post('/', [FamilyController::class, 'store'])->name('families.store');
    Route::get('/statistics', [FamilyController::class, 'statistics'])->name('families.statistics');
    Route::get('/without-bcc', [FamilyController::class, 'withoutBcc'])->name('families.without-bcc');
    Route::get('/bcc/{bccId}', [FamilyController::class, 'byBcc'])->name('families.by-bcc');
    Route::get('/{id}', [FamilyController::class, 'show'])->name('families.show');
    Route::put('/{id}', [FamilyController::class, 'update'])->name('families.update');
    Route::delete('/{id}', [FamilyController::class, 'destroy'])->name('families.destroy');
    
    // Family Member Nested Routes
    Route::get('/{familyId}/members', [FamilyController::class, 'members'])->name('families.members.index');
    Route::post('/{familyId}/members', [FamilyController::class, 'addMember'])->name('families.members.store');
    Route::put('/{familyId}/members/{memberId}', [FamilyController::class, 'updateMember'])->name('families.members.update');
    Route::delete('/{familyId}/members/{memberId}', [FamilyController::class, 'deleteMember'])->name('families.members.destroy');
});
