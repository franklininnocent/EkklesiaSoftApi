<?php

use Illuminate\Support\Facades\Route;
use Modules\Family\app\Http\Controllers\FamilyController;
use Modules\Family\app\Http\Controllers\PersonController;

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

    // Household transition routes (must be before /{id} wildcard)
    Route::get('/{id}/relocate-bcc/preview', [FamilyController::class, 'previewBccRelocation'])
        ->middleware('tenant.permission:families.bcc.relocate')
        ->name('families.relocate-bcc.preview');
    Route::post('/{id}/relocate-bcc', [FamilyController::class, 'relocateBcc'])
        ->middleware('tenant.permission:families.bcc.relocate')
        ->name('families.relocate-bcc');
    Route::post('/marriage-transition', [FamilyController::class, 'marriageTransition'])
        ->middleware('tenant.permission:families.marriage.transition')
        ->name('families.marriage-transition');
    Route::get('/{id}/transition-history', [FamilyController::class, 'transitionHistory'])
        ->middleware('tenant.permission:families.history.view')
        ->name('families.transition-history');
    Route::post('/history/correct', [FamilyController::class, 'correctHistory'])
        ->middleware('tenant.permission:families.history.correct')
        ->name('families.history.correct');

    // Family CRUD Routes
    Route::get('/', [FamilyController::class, 'index'])->name('families.index');
    Route::post('/', [FamilyController::class, 'store'])
        ->middleware('tenant.permission:families.create')
        ->name('families.store');
    Route::post('/merge', [FamilyController::class, 'mergeFamilies'])
        ->middleware('tenant.permission:families.edit')
        ->name('families.merge');
    Route::get('/statistics', [FamilyController::class, 'statistics'])
        ->middleware('tenant.permission:families.view')
        ->name('families.statistics');
    Route::get('/without-bcc', [FamilyController::class, 'withoutBcc'])
        ->middleware('tenant.permission:families.view')
        ->name('families.without-bcc');
    Route::get('/bcc/{bccId}', [FamilyController::class, 'byBcc'])
        ->middleware('tenant.permission:families.view')
        ->name('families.by-bcc');
    Route::get('/{id}', [FamilyController::class, 'show'])->name('families.show');
    Route::put('/{id}', [FamilyController::class, 'update'])
        ->middleware('tenant.permission:families.edit')
        ->name('families.update');
    Route::delete('/{id}', [FamilyController::class, 'destroy'])
        ->middleware('tenant.permission:families.delete')
        ->name('families.destroy');
    Route::post('/{id}/update-requests', [FamilyController::class, 'submitUpdateRequest'])->name('families.update-requests.store');
    Route::post('/{familyId}/split-member', [FamilyController::class, 'splitMember'])
        ->middleware('tenant.permission:families.edit')
        ->name('families.split-member');

    // Family Profile Image Routes
    Route::post('/{id}/profile-image', [FamilyController::class, 'uploadProfileImage'])
        ->middleware('tenant.permission:families.edit')
        ->name('families.profile-image.upload');
    Route::delete('/{id}/profile-image', [FamilyController::class, 'deleteProfileImage'])
        ->middleware('tenant.permission:families.edit')
        ->name('families.profile-image.delete');

    // Family Head Profile Image Routes
    Route::post('/{id}/head-profile-image', [FamilyController::class, 'uploadHeadProfileImage'])
        ->middleware('tenant.permission:families.edit')
        ->name('families.head-profile-image.upload');
    Route::delete('/{id}/head-profile-image', [FamilyController::class, 'deleteHeadProfileImage'])
        ->middleware('tenant.permission:families.edit')
        ->name('families.head-profile-image.delete');

    // Family Member Nested Routes
    Route::get('/{familyId}/members', [FamilyController::class, 'members'])->name('families.members.index');
    Route::post('/{familyId}/members', [FamilyController::class, 'addMember'])
        ->middleware('tenant.permission:families.edit')
        ->name('families.members.store');
    Route::put('/{familyId}/members/{memberId}', [FamilyController::class, 'updateMember'])
        ->middleware('tenant.permission:families.edit')
        ->name('families.members.update');
    Route::delete('/{familyId}/members/{memberId}', [FamilyController::class, 'deleteMember'])
        ->middleware('tenant.permission:families.edit')
        ->name('families.members.destroy');
});

// Members Routes - Get all members across all families for a tenant
Route::middleware(['auth:api'])->prefix('members')->group(function () {
    Route::get('/', [FamilyController::class, 'allMembers'])->name('members.index');
});

// Parish persons — used by family member + sacrament recipient search
Route::middleware(['auth:api'])->prefix('persons')->group(function () {
    Route::get('/', [PersonController::class, 'index'])->name('persons.index');
    Route::post('/matches', [PersonController::class, 'matches'])->name('persons.matches');
    Route::get('/{id}', [PersonController::class, 'show'])->name('persons.show');
    Route::post('/{id}/reconcile-identity', [PersonController::class, 'reconcileIdentity'])->name('persons.reconcile-identity');
});
