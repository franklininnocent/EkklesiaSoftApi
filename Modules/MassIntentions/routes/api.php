<?php

use Illuminate\Support\Facades\Route;
use Modules\MassIntentions\Http\Controllers\MassIntentionAuditController;
use Modules\MassIntentions\Http\Controllers\MassIntentionCategoryController;
use Modules\MassIntentions\Http\Controllers\MassCelebrationController;
use Modules\MassIntentions\Http\Controllers\MassIntentionObligationController;
use Modules\MassIntentions\Http\Controllers\MassIntentionFulfilmentController;
use Modules\MassIntentions\Http\Controllers\MassIntentionOfferingReceiptController;
use Modules\MassIntentions\Http\Controllers\MassIntentionRequestController;
use Modules\MassIntentions\Http\Controllers\MassIntentionsDashboardController;
use Modules\MassIntentions\Http\Controllers\MassIntentionsModuleController;
use Modules\MassIntentions\Http\Controllers\MassIntentionsReportsController;
use Modules\MassIntentions\Http\Controllers\MassIntentionsSettingsController;
use Modules\MassIntentions\Http\Controllers\MassIntentionTransferController;

Route::middleware(['auth:api', 'tenant.permission:mass.intentions.view'])
    ->prefix('tenant/mass-intentions')
    ->group(function () {
        Route::get('/module-status', [MassIntentionsModuleController::class, 'status'])
            ->name('mass-intentions.module-status');
    });

Route::middleware([
    'auth:api',
    'tenant.feature.mass_intentions',
    'entitlement:MASS_INTENTIONS',
    'tenant.permission:mass.intentions.view',
])
    ->prefix('tenant/mass-intentions')
    ->group(function () {
        Route::get('/home', [MassIntentionsDashboardController::class, 'home'])
            ->name('mass-intentions.home');
        Route::get('/dashboard', [MassIntentionsDashboardController::class, 'home'])
            ->name('mass-intentions.dashboard');

        Route::get('/categories', [MassIntentionCategoryController::class, 'index'])
            ->name('mass-intentions.categories.index');
        Route::post('/categories', [MassIntentionCategoryController::class, 'store'])
            ->middleware('tenant.permission:mass.intentions.create')
            ->name('mass-intentions.categories.store');

        Route::get('/settings', [MassIntentionsSettingsController::class, 'show'])
            ->name('mass-intentions.settings.show');
        Route::put('/settings', [MassIntentionsSettingsController::class, 'update'])
            ->middleware('tenant.permission:mass.intentions.configure')
            ->name('mass-intentions.settings.update');

        Route::middleware('tenant.permission:mass.intentions.register.export')->group(function (): void {
            Route::get('/requests/export/pdf', [MassIntentionRequestController::class, 'exportRegisterPdf'])
                ->name('mass-intentions.requests.export-pdf');
            Route::get('/reports/canonical-register', [MassIntentionsReportsController::class, 'canonicalRegister'])
                ->name('mass-intentions.reports.canonical-register');
            Route::get('/reports/mass-list', [MassIntentionsReportsController::class, 'massList'])
                ->name('mass-intentions.reports.mass-list');
            Route::get('/reports/still-to-say', [MassIntentionsReportsController::class, 'stillToSay'])
                ->name('mass-intentions.reports.still-to-say');
            Route::get('/reports/masses-said', [MassIntentionsReportsController::class, 'massesSaid'])
                ->name('mass-intentions.reports.masses-said');
            Route::get('/reports/offerings', [MassIntentionsReportsController::class, 'offerings'])
                ->middleware('tenant.permission:mass.intentions.offerings.view')
                ->name('mass-intentions.reports.offerings');
            Route::get('/reports/donations-ledger-bridge', [MassIntentionsReportsController::class, 'donationsLedgerBridge'])
                ->middleware('tenant.permission:mass.intentions.offerings.view')
                ->name('mass-intentions.reports.donations-ledger-bridge');
        });

        Route::get('/audits', [MassIntentionAuditController::class, 'index'])
            ->middleware('tenant.permission:mass.intentions.configure')
            ->name('mass-intentions.audits.index');

        Route::get('/requests', [MassIntentionRequestController::class, 'index'])
            ->name('mass-intentions.requests.index');
        Route::post('/requests', [MassIntentionRequestController::class, 'store'])
            ->middleware('tenant.permission:mass.intentions.create')
            ->name('mass-intentions.requests.store');
        Route::get('/requests/{id}', [MassIntentionRequestController::class, 'show'])
            ->name('mass-intentions.requests.show');
        Route::put('/requests/{id}', [MassIntentionRequestController::class, 'update'])
            ->middleware('tenant.permission:mass.intentions.create')
            ->name('mass-intentions.requests.update');
        Route::post('/requests/{id}/accept', [MassIntentionRequestController::class, 'accept'])
            ->middleware('tenant.permission:mass.intentions.review')
            ->name('mass-intentions.requests.accept');
        Route::post('/requests/{id}/request-clarification', [MassIntentionRequestController::class, 'requestClarification'])
            ->middleware('tenant.permission:mass.intentions.review')
            ->name('mass-intentions.requests.request-clarification');
        Route::post('/requests/{id}/withdraw', [MassIntentionRequestController::class, 'withdraw'])
            ->middleware('tenant.permission:mass.intentions.create')
            ->name('mass-intentions.requests.withdraw');
        Route::post('/requests/{id}/close', [MassIntentionRequestController::class, 'close'])
            ->middleware('tenant.permission:mass.intentions.review')
            ->name('mass-intentions.requests.close');
        Route::post('/requests/{id}/schedule', [MassIntentionRequestController::class, 'schedule'])
            ->middleware('tenant.permission:mass.intentions.schedule')
            ->name('mass-intentions.requests.schedule');
        Route::post('/requests/{id}/receipts', [MassIntentionRequestController::class, 'recordReceipt'])
            ->middleware('tenant.permission:mass.intentions.offerings.record')
            ->name('mass-intentions.requests.receipts.store');

        Route::post('/receipts/{id}/void', [MassIntentionOfferingReceiptController::class, 'void'])
            ->middleware('tenant.permission:mass.intentions.offerings.record')
            ->name('mass-intentions.receipts.void');

        Route::get('/obligations/pending-schedule', [MassIntentionObligationController::class, 'pendingSchedule'])
            ->middleware('tenant.permission:mass.intentions.schedule')
            ->name('mass-intentions.obligations.pending-schedule');

        Route::get('/celebrations', [MassCelebrationController::class, 'index'])
            ->name('mass-intentions.celebrations.index');
        Route::post('/celebrations', [MassCelebrationController::class, 'store'])
            ->middleware('tenant.permission:mass.intentions.schedule')
            ->name('mass-intentions.celebrations.store');
        Route::put('/celebrations/{id}', [MassCelebrationController::class, 'update'])
            ->middleware('tenant.permission:mass.intentions.schedule')
            ->name('mass-intentions.celebrations.update');
        Route::get('/celebrations/{id}', [MassCelebrationController::class, 'show'])
            ->name('mass-intentions.celebrations.show');
        Route::get('/celebrations/{id}/workspace', [MassCelebrationController::class, 'workspace'])
            ->name('mass-intentions.celebrations.workspace');
        Route::post('/celebrations/{id}/assign-obligations', [MassCelebrationController::class, 'assignObligations'])
            ->middleware('tenant.permission:mass.intentions.schedule')
            ->name('mass-intentions.celebrations.assign-obligations');
        Route::post('/celebrations/{id}/cancel', [MassCelebrationController::class, 'cancel'])
            ->middleware('tenant.permission:mass.intentions.schedule')
            ->name('mass-intentions.celebrations.cancel');
        Route::post('/celebrations/{id}/confirm-said', [MassCelebrationController::class, 'confirmSaid'])
            ->middleware('tenant.permission:mass.intentions.fulfil')
            ->name('mass-intentions.celebrations.confirm-said');

        Route::post('/fulfilments/{id}/undo', [MassIntentionFulfilmentController::class, 'undo'])
            ->middleware('tenant.permission:mass.intentions.fulfil')
            ->name('mass-intentions.fulfilments.undo');

        Route::get('/transfers/targets', [MassIntentionTransferController::class, 'targets'])
            ->middleware('tenant.permission:mass.intentions.review')
            ->name('mass-intentions.transfers.targets');
        Route::get('/transfers/pending', [MassIntentionTransferController::class, 'pending'])
            ->middleware('tenant.permission:mass.intentions.review')
            ->name('mass-intentions.transfers.pending');
        Route::post('/requests/{id}/transfer', [MassIntentionTransferController::class, 'initiate'])
            ->middleware('tenant.permission:mass.intentions.review')
            ->name('mass-intentions.requests.transfer');
        Route::post('/transfers/{id}/accept', [MassIntentionTransferController::class, 'accept'])
            ->middleware('tenant.permission:mass.intentions.review')
            ->name('mass-intentions.transfers.accept');
        Route::post('/transfers/{id}/reject', [MassIntentionTransferController::class, 'reject'])
            ->middleware('tenant.permission:mass.intentions.review')
            ->name('mass-intentions.transfers.reject');
    });
