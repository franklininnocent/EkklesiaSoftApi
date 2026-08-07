<?php

use Illuminate\Support\Facades\Route;
use Modules\SupportAccess\Http\Controllers\SupportApprovalController;
use Modules\SupportAccess\Http\Controllers\SupportAuditController;
use Modules\SupportAccess\Http\Controllers\SupportGrantController;
use Modules\SupportAccess\Http\Controllers\SupportSessionController;
use Modules\SupportAccess\Http\Controllers\SupportSettingsController;
use Modules\SupportAccess\Http\Controllers\SupportTenantController;
use Modules\SupportAccess\Http\Controllers\TenantSupportGrantController;

Route::prefix('support')
    ->middleware(['auth:api'])
    ->group(function (): void {
        Route::get('/tenants', [SupportTenantController::class, 'index'])
            ->middleware('support.permission:support.sessions.start');
        Route::get('/tenants/{tenantId}', [SupportTenantController::class, 'show'])
            ->middleware('support.permission:support.sessions.start')
            ->whereNumber('tenantId');

        Route::get('/settings', [SupportSettingsController::class, 'show'])
            ->middleware('support.permission:support.configuration.manage');
        Route::put('/settings', [SupportSettingsController::class, 'update'])
            ->middleware('support.permission:support.configuration.manage');

        Route::get('/audit/events', [SupportAuditController::class, 'events'])
            ->middleware('support.permission:support.audit.view');
        Route::get('/audit/events/export', [SupportAuditController::class, 'exportEvents'])
            ->middleware('support.permission:support.audit.view');

        Route::get('/approvals', [SupportApprovalController::class, 'index'])
            ->middleware('support.permission:support.sessions.view');
        Route::post('/approvals', [SupportApprovalController::class, 'store'])
            ->middleware('support.permission:support.sessions.start');
        Route::post('/approvals/{id}/approve', [SupportApprovalController::class, 'approve'])
            ->middleware('support.permission:support.sessions.approve');
        Route::post('/approvals/{id}/reject', [SupportApprovalController::class, 'reject'])
            ->middleware('support.permission:support.sessions.approve');
        Route::post('/approvals/{id}/cancel', [SupportApprovalController::class, 'cancel'])
            ->middleware('support.permission:support.sessions.start');

        Route::get('/grants', [SupportGrantController::class, 'index'])
            ->middleware('support.permission:support.grants.view');
        Route::post('/grants', [SupportGrantController::class, 'store'])
            ->middleware('support.permission:support.grants.manage');
        Route::post('/grants/{id}/revoke', [SupportGrantController::class, 'revoke'])
            ->middleware('support.permission:support.grants.manage');

        Route::get('/sessions', [SupportSessionController::class, 'index'])
            ->middleware('support.permission:support.sessions.view');
        Route::get('/sessions/active', [SupportSessionController::class, 'active'])
            ->middleware('support.permission:support.sessions.view');
        Route::get('/sessions/monitor', [SupportSessionController::class, 'monitor'])
            ->middleware('support.permission:support.sessions.view');
        Route::get('/sessions/export', [SupportSessionController::class, 'export'])
            ->middleware('support.permission:support.audit.view');
        Route::get('/sessions/metrics', [SupportSessionController::class, 'metrics'])
            ->middleware('support.permission:support.sessions.view');
        Route::post('/sessions', [SupportSessionController::class, 'start'])
            ->middleware('support.permission:support.sessions.start');
        Route::get('/sessions/{sessionId}', [SupportSessionController::class, 'show'])
            ->middleware('support.permission:support.sessions.view');
        Route::post('/sessions/{sessionId}/renew', [SupportSessionController::class, 'renew'])
            ->middleware('support.permission:support.sessions.start');
        Route::post('/sessions/{sessionId}/end', [SupportSessionController::class, 'end'])
            ->middleware('support.permission:support.sessions.end');
        Route::post('/sessions/{sessionId}/force-end', [SupportSessionController::class, 'forceEnd'])
            ->middleware('support.permission:support.sessions.end');
        Route::post('/sessions/{sessionId}/events', [SupportSessionController::class, 'recordEvent'])
            ->middleware('support.permission:support.sessions.view');
    });

Route::prefix('tenant/support-access')
    ->middleware(['auth:api'])
    ->group(function (): void {
        Route::get('/grants', [TenantSupportGrantController::class, 'index'])
            ->middleware('tenant.permission:support.grants.parish.view');
        Route::post('/grants', [TenantSupportGrantController::class, 'store'])
            ->middleware('tenant.permission:support.grants.parish.manage');
        Route::post('/grants/{id}/revoke', [TenantSupportGrantController::class, 'revoke'])
            ->middleware('tenant.permission:support.grants.parish.manage');
    });
