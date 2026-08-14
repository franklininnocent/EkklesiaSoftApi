<?php

use Illuminate\Support\Facades\Route;
use Modules\Sacraments\Http\Controllers\SacramentCertificateController;
use Modules\Sacraments\Http\Controllers\SacramentController;
use Modules\Sacraments\Http\Controllers\SacramentMigrationResolutionController;
use Modules\Sacraments\Http\Controllers\TenantSacramentSettingsController;

/*
|--------------------------------------------------------------------------
| Sacraments API Routes (Phase 1–4 + Phase 8 certificates)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:api'])->prefix('tenant/sacrament-settings')->name('tenant.sacrament-settings.')->group(function () {
    Route::get('/', [TenantSacramentSettingsController::class, 'index'])->name('index');

    Route::patch('/{sacramentType}', [TenantSacramentSettingsController::class, 'update'])
        ->whereNumber('sacramentType')
        ->name('update');
});

Route::middleware(['auth:api', 'tenant.permission:sacraments.view'])
    ->prefix('sacraments')
    ->name('sacraments.')
    ->group(function () {
        Route::get('/types', [SacramentController::class, 'getSacramentTypes'])->name('types');
        Route::get('/definitions', [SacramentController::class, 'getDefinitions'])->name('definitions');
        Route::get('/', [SacramentController::class, 'index'])->name('index');
        Route::get('/{id}', [SacramentController::class, 'show'])->name('show')->whereNumber('id');

        Route::post('/', [SacramentController::class, 'store'])
            ->middleware('tenant.permission:sacraments.create')
            ->name('store');

        Route::put('/{id}', [SacramentController::class, 'update'])
            ->middleware('tenant.permission:sacraments.edit')
            ->whereNumber('id')
            ->name('update');

        Route::patch('/{id}/metadata', [SacramentController::class, 'patchMetadata'])
            ->middleware('tenant.permission:sacraments.edit')
            ->whereNumber('id')
            ->name('metadata');

        Route::post('/{id}/correct', [SacramentController::class, 'correct'])
            ->middleware('tenant.permission:sacraments.correct')
            ->whereNumber('id')
            ->name('correct');

        Route::post('/{id}/void', [SacramentController::class, 'void'])
            ->middleware('tenant.permission:sacraments.void')
            ->whereNumber('id')
            ->name('void');

        Route::post('/{id}/restore', [SacramentController::class, 'restore'])
            ->middleware('tenant.permission:sacraments.restore')
            ->whereNumber('id')
            ->name('restore');

        Route::delete('/{id}', [SacramentController::class, 'destroy'])
            ->middleware('tenant.permission:sacraments.delete')
            ->whereNumber('id')
            ->name('destroy');

        Route::post('/bulk/update-status', [SacramentController::class, 'bulkUpdateStatus'])
            ->middleware('tenant.permission:sacraments.edit')
            ->name('bulk.update-status');

        Route::post('/bulk/delete', [SacramentController::class, 'bulkDelete'])
            ->middleware('tenant.permission:sacraments.delete')
            ->name('bulk.delete');

        // Phase 8 — Certificates (AuthZ download; no public URLs)
        Route::get('/{sacramentId}/certificates', [SacramentCertificateController::class, 'index'])
            ->middleware('tenant.permission:certificate.download')
            ->whereNumber('sacramentId')
            ->name('certificates.index');

        Route::post('/{sacramentId}/certificates/preview', [SacramentCertificateController::class, 'preview'])
            ->middleware('tenant.permission:certificate.generate')
            ->whereNumber('sacramentId')
            ->name('certificates.preview');

        Route::post('/{sacramentId}/certificates/generate', [SacramentCertificateController::class, 'generate'])
            ->middleware('tenant.permission:certificate.generate')
            ->whereNumber('sacramentId')
            ->name('certificates.generate');

        Route::get('/certificates/{certificateId}/download', [SacramentCertificateController::class, 'download'])
            ->middleware('tenant.permission:certificate.download')
            ->whereNumber('certificateId')
            ->name('certificates.download');

        Route::post('/certificates/{certificateId}/reissue', [SacramentCertificateController::class, 'reissue'])
            ->middleware('tenant.permission:certificate.reissue')
            ->whereNumber('certificateId')
            ->name('certificates.reissue');

        // Phase 9 — Migration / reconciliation
        Route::get('/migration-resolutions/report', [SacramentMigrationResolutionController::class, 'report'])
            ->middleware('tenant.permission:sacraments.migration.view')
            ->name('migration.report');

        Route::get('/migration-resolutions', [SacramentMigrationResolutionController::class, 'index'])
            ->middleware('tenant.permission:sacraments.migration.view')
            ->name('migration.index');

        Route::get('/migration-resolutions/{id}', [SacramentMigrationResolutionController::class, 'show'])
            ->middleware('tenant.permission:sacraments.migration.view')
            ->whereNumber('id')
            ->name('migration.show');

        Route::post('/migration-resolutions/{id}/resolve', [SacramentMigrationResolutionController::class, 'resolve'])
            ->middleware('tenant.permission:sacraments.migration.resolve')
            ->whereNumber('id')
            ->name('migration.resolve');

        Route::post('/migration/backfill', [SacramentMigrationResolutionController::class, 'backfill'])
            ->middleware('tenant.permission:sacraments.migration.resolve')
            ->name('migration.backfill');
    });
