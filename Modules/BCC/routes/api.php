<?php

use Illuminate\Support\Facades\Route;
use Modules\BCC\Http\Controllers\BCCController;
use Modules\BCC\Http\Controllers\BccAuditLogController;
use Modules\BCC\Http\Controllers\BccDashboardController;
use Modules\BCC\Http\Controllers\BccLeadershipController;
use Modules\BCC\Http\Controllers\BccMembershipController;

Route::middleware(['auth:api', 'tenant.permission:bcc.view'])->prefix('bccs')->group(function () {
    Route::get('/dashboard', [BccDashboardController::class, 'show'])->name('bccs.dashboard');
    Route::get('/statistics', [BCCController::class, 'statistics'])->name('bccs.statistics');
    Route::get('/with-space', [BCCController::class, 'withSpace'])->name('bccs.with-space');
    Route::get('/families/lookup', [BccMembershipController::class, 'lookup'])
        ->middleware('tenant.permission:bcc.manage_members')
        ->name('bccs.families.lookup');
    Route::get('/audit-logs', [BccAuditLogController::class, 'index'])->name('bccs.audit-logs.index');

    Route::get('/', [BCCController::class, 'index'])->name('bccs.index');
    Route::post('/', [BCCController::class, 'store'])
        ->middleware('tenant.permission:bcc.create')
        ->name('bccs.store');

    Route::get('/{id}/dashboard', [BccDashboardController::class, 'overview'])->name('bccs.overview');
    Route::get('/{id}', [BCCController::class, 'show'])->name('bccs.show');
    Route::put('/{id}', [BCCController::class, 'update'])
        ->middleware('tenant.permission:bcc.edit')
        ->name('bccs.update');
    Route::delete('/{id}', [BCCController::class, 'destroy'])
        ->middleware('tenant.permission:bcc.delete')
        ->name('bccs.destroy');

    Route::get('/{bccId}/members', [BccMembershipController::class, 'index'])->name('bccs.members.index');
    Route::post('/{bccId}/members', [BccMembershipController::class, 'store'])
        ->middleware('tenant.permission:bcc.manage_members')
        ->name('bccs.members.store');
    Route::delete('/{bccId}/members/{membershipId}', [BccMembershipController::class, 'destroy'])
        ->middleware('tenant.permission:bcc.manage_members')
        ->name('bccs.members.destroy');
    Route::get('/{bccId}/people', [BccMembershipController::class, 'people'])->name('bccs.people.index');
    Route::get('/{bccId}/member-history', [BccMembershipController::class, 'history'])->name('bccs.member-history');

    Route::get('/{bccId}/leadership/current', [BccLeadershipController::class, 'current'])->name('bccs.leadership.current');
    Route::get('/{bccId}/leadership/timeline', [BccLeadershipController::class, 'timeline'])->name('bccs.leadership.timeline');
    Route::get('/{bccId}/leadership/eligible', [BccLeadershipController::class, 'eligible'])
        ->middleware('tenant.permission:bcc.manage_leadership')
        ->name('bccs.leadership.eligible');
    Route::post('/{bccId}/leadership/assign', [BccLeadershipController::class, 'assign'])
        ->middleware('tenant.permission:bcc.manage_leadership')
        ->name('bccs.leadership.assign');
    Route::post('/{bccId}/leadership/handover', [BccLeadershipController::class, 'handover'])
        ->middleware('tenant.permission:bcc.manage_leadership')
        ->name('bccs.leadership.handover');
    Route::post('/{bccId}/leadership/{leaderId}/terminate', [BccLeadershipController::class, 'terminate'])
        ->middleware('tenant.permission:bcc.manage_leadership')
        ->name('bccs.leadership.terminate');

    Route::get('/{bccId}/audit-logs', [BccAuditLogController::class, 'organizationIndex'])->name('bccs.audit-logs.bcc');

    Route::get('/{bccId}/leaders', [BCCController::class, 'leaders'])->name('bccs.leaders.index');
    Route::post('/{bccId}/leaders', [BccLeadershipController::class, 'assign'])
        ->middleware('tenant.permission:bcc.manage_leadership')
        ->name('bccs.leaders.store');
    Route::put('/{bccId}/leaders/{leaderId}', [BCCController::class, 'updateLeader'])
        ->middleware('tenant.permission:bcc.manage_leadership')
        ->name('bccs.leaders.update');
    Route::delete('/{bccId}/leaders/{leaderId}', [BCCController::class, 'deleteLeader'])
        ->middleware('tenant.permission:bcc.manage_leadership')
        ->name('bccs.leaders.destroy');

    Route::post('/{bccId}/assign-families', [BccMembershipController::class, 'store'])
        ->middleware('tenant.permission:bcc.manage_members')
        ->name('bccs.assign-families');
    Route::post('/remove-families', [BCCController::class, 'removeFamilies'])
        ->middleware('tenant.permission:bcc.manage_members')
        ->name('bccs.remove-families');
});
