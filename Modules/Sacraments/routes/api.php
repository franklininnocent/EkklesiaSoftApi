<?php

use Illuminate\Support\Facades\Route;
use Modules\Sacraments\Http\Controllers\MarriagePreparationCaseController;
use Modules\Sacraments\Http\Controllers\SacramentCanonicalAnnotationController;
use Modules\Sacraments\Http\Controllers\SacramentCertificateController;
use Modules\Sacraments\Http\Controllers\SacramentContextController;
use Modules\Sacraments\Http\Controllers\SacramentController;
use Modules\Sacraments\Http\Controllers\SacramentDashboardController;
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
        Route::get('/context', [SacramentContextController::class, 'show'])->name('context');
        Route::get('/dashboard/summary', [SacramentDashboardController::class, 'summary'])->name('dashboard.summary');

        Route::get('/marriage-preparation-cases', [MarriagePreparationCaseController::class, 'index'])
            ->name('marriage-preparation-cases.index');

        Route::post('/marriage-preparation-cases', [MarriagePreparationCaseController::class, 'store'])
            ->middleware('tenant.permission:sacraments.edit')
            ->name('marriage-preparation-cases.store');

        Route::patch('/marriage-preparation-cases/{id}', [MarriagePreparationCaseController::class, 'update'])
            ->middleware('tenant.permission:sacraments.edit')
            ->whereNumber('id')
            ->name('marriage-preparation-cases.update');

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
        Route::get('/{sacramentId}/certificate-view', [SacramentCertificateController::class, 'certificateView'])
            ->middleware('tenant.permission:certificate.download')
            ->whereNumber('sacramentId')
            ->name('certificates.view');

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

        Route::get('/{sacramentId}/certificates/latest/download', [SacramentCertificateController::class, 'downloadLatest'])
            ->middleware('tenant.permission:certificate.download')
            ->whereNumber('sacramentId')
            ->name('certificates.download-latest');

        Route::get('/certificates/{certificateId}/download', [SacramentCertificateController::class, 'download'])
            ->middleware('tenant.permission:certificate.download')
            ->whereNumber('certificateId')
            ->name('certificates.download');

        Route::get('/certificates/{certificateId}/print', [SacramentCertificateController::class, 'printHtml'])
            ->middleware('tenant.permission:certificate.download')
            ->whereNumber('certificateId')
            ->name('certificates.print');

        Route::post('/certificates/{certificateId}/void', [SacramentCertificateController::class, 'void'])
            ->middleware('tenant.permission:certificate.generate')
            ->whereNumber('certificateId')
            ->name('certificates.void');

        Route::post('/certificates/{certificateId}/reissue', [SacramentCertificateController::class, 'reissue'])
            ->middleware('tenant.permission:certificate.reissue')
            ->whereNumber('certificateId')
            ->name('certificates.reissue');

        Route::get('/{sacramentId}/canonical-annotations', [SacramentCanonicalAnnotationController::class, 'index'])
            ->middleware('tenant.permission:sacraments.view')
            ->whereNumber('sacramentId')
            ->name('annotations.index');

        Route::post('/{sacramentId}/canonical-annotations', [SacramentCanonicalAnnotationController::class, 'store'])
            ->middleware('tenant.permission:sacraments.correct')
            ->whereNumber('sacramentId')
            ->name('annotations.store');

        Route::delete('/{sacramentId}/canonical-annotations/{annotationId}', [SacramentCanonicalAnnotationController::class, 'destroy'])
            ->middleware('tenant.permission:sacraments.correct')
            ->whereNumber('sacramentId')
            ->whereNumber('annotationId')
            ->name('annotations.destroy');

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

Route::prefix('public/sacrament-certificates')->name('public.sacrament-certificates.')->group(function () {
    Route::get('/verify/{token}', [SacramentCertificateController::class, 'verify'])
        ->where('token', '[A-Za-z0-9]+')
        ->name('verify');
});
