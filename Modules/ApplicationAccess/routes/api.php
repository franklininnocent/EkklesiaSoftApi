<?php

use Illuminate\Support\Facades\Route;
use Modules\ApplicationAccess\Http\Controllers\ApplicationAccessDashboardController;
use Modules\ApplicationAccess\Http\Controllers\ApplicationAccessExportController;
use Modules\ApplicationAccess\Http\Controllers\ApplicationAccessEventController;
use Modules\ApplicationAccess\Http\Controllers\ApplicationAccessIpBlockController;
use Modules\ApplicationAccess\Http\Controllers\ApplicationAccessSecurityEventController;
use Modules\ApplicationAccess\Http\Controllers\ApplicationAccessSessionController;
use Modules\ApplicationAccess\Http\Controllers\ApplicationAccessSignalController;
use Modules\ApplicationAccess\Http\Controllers\ApplicationAccessStreamController;

Route::prefix('admin/application-access')
    ->middleware('auth:api')
    ->group(function (): void {
        Route::get('/stream', [ApplicationAccessStreamController::class, 'stream'])
            ->middleware(['api.throttle:application_access_stream', 'application_access.permission:application_access.view']);

        Route::middleware('api.throttle:application_access')->group(function (): void {
            Route::middleware('application_access.permission:application_access.view')->group(function (): void {
                Route::get('/health', fn () => response()->json(['success' => true, 'module' => 'ApplicationAccess']));

                Route::get('/dashboard', [ApplicationAccessDashboardController::class, 'show']);
                Route::get('/sessions', [ApplicationAccessSessionController::class, 'index']);
                Route::get('/sessions/{id}', [ApplicationAccessSessionController::class, 'show'])
                    ->whereUuid('id');
                Route::get('/sessions/{id}/timeline', [ApplicationAccessSessionController::class, 'timeline'])
                    ->whereUuid('id');
                Route::get('/events', [ApplicationAccessEventController::class, 'index']);
                Route::get('/security-events', [ApplicationAccessSecurityEventController::class, 'index']);
                Route::get('/signals', [ApplicationAccessSignalController::class, 'index']);
            });

            Route::middleware('application_access.permission:application_access.revoke_session')
                ->post('/sessions/{id}/revoke', [ApplicationAccessSessionController::class, 'revoke'])
                ->whereUuid('id');

            Route::middleware('application_access.permission:application_access.block_ip')
                ->post('/ip-blocks', [ApplicationAccessIpBlockController::class, 'store']);

            Route::middleware('application_access.permission:application_access.unblock_ip')
                ->post('/ip-blocks/{id}/revoke', [ApplicationAccessIpBlockController::class, 'revoke'])
                ->whereUuid('id');

            Route::middleware('application_access.permission:application_access.export')
                ->get('/export/sessions', [ApplicationAccessExportController::class, 'sessions']);
        });
    });
