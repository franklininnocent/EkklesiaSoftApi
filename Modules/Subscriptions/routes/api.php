<?php

use Illuminate\Support\Facades\Route;
use Modules\Subscriptions\Http\Controllers\Admin\FeatureController;
use Modules\Subscriptions\Http\Controllers\Admin\PlanCatalogController;
use Modules\Subscriptions\Http\Controllers\Admin\PlanVersionController;
use Modules\Subscriptions\Http\Controllers\Admin\SubscriptionAnalyticsController;
use Modules\Subscriptions\Http\Controllers\Admin\SubscriptionAuditController;
use Modules\Subscriptions\Http\Controllers\Admin\SubscriptionPolicyController;
use Modules\Subscriptions\Http\Controllers\Admin\TenantSubscriptionController;
use Modules\Subscriptions\Http\Controllers\Admin\UpgradeRequestController;
use Modules\Subscriptions\Http\Controllers\PublicPlanController;
use Modules\Subscriptions\Http\Controllers\Tenant\TenantEntitlementController;
use Modules\Subscriptions\Http\Controllers\Tenant\TenantUpgradeRequestController;

/*
 *--------------------------------------------------------------------------
 * API Routes - Subscriptions Module
 *--------------------------------------------------------------------------
 *
 * admin/subscriptions/*  platform actors only (SuperAdmin / EkklesiaAdmin with permission)
 * tenant/*               authenticated tenant user; tenant resolved from TenantContext
 * public/*               unauthenticated pricing catalog
 */

Route::get('/public/subscription-plans', [PublicPlanController::class, 'index'])
    ->name('subscriptions.public.plans');

Route::middleware('auth:api')->group(function () {
    Route::prefix('tenant')->name('subscriptions.tenant.')->group(function () {
        Route::get('/entitlements', [TenantEntitlementController::class, 'entitlements'])->name('entitlements');
        Route::get('/subscription/overview', [TenantEntitlementController::class, 'subscription'])->name('overview');
        Route::get('/subscription/comparison', [TenantEntitlementController::class, 'comparison'])->name('comparison');
        Route::get('/subscription/usage', [TenantEntitlementController::class, 'usage'])->name('usage');
        Route::get('/subscription/upgrade-requests', [TenantUpgradeRequestController::class, 'index'])->name('upgrade-requests.index');
        Route::post('/subscription/upgrade-requests', [TenantUpgradeRequestController::class, 'store'])
            ->middleware('throttle:10,60')->name('upgrade-requests.store');
    });

    Route::prefix('admin/subscriptions')->name('subscriptions.admin.')->group(function () {
        $view = 'subscriptions.platform.permission:subscriptions.plans.view';
        $manage = 'subscriptions.platform.permission:subscriptions.plans.manage';
        $publish = 'subscriptions.platform.permission:subscriptions.plans.publish';
        $features = 'subscriptions.platform.permission:subscriptions.features.manage';

        Route::get('/plans', [PlanCatalogController::class, 'index'])->middleware($view)->name('plans.index');
        Route::get('/plans/{plan}', [PlanCatalogController::class, 'show'])->middleware($view)->whereNumber('plan')->name('plans.show');
        Route::post('/plans', [PlanCatalogController::class, 'store'])->middleware($manage)->name('plans.store');
        Route::patch('/plans/{plan}', [PlanCatalogController::class, 'update'])->middleware($manage)->whereNumber('plan')->name('plans.update');
        Route::delete('/plans/{plan}', [PlanCatalogController::class, 'destroy'])
            ->middleware('subscriptions.platform.permission:subscriptions.plans.delete')->whereNumber('plan')->name('plans.destroy');
        Route::post('/plans/{plan}/archive', [PlanCatalogController::class, 'archive'])->middleware($publish)->whereNumber('plan')->name('plans.archive');
        Route::post('/plans/{plan}/restore', [PlanCatalogController::class, 'restore'])->middleware($publish)->whereNumber('plan')->name('plans.restore');
        Route::post('/plans/{plan}/duplicate', [PlanCatalogController::class, 'duplicate'])->middleware($manage)->whereNumber('plan')->name('plans.duplicate');
        Route::get('/plans/{plan}/tenants', [PlanCatalogController::class, 'tenants'])
            ->middleware('subscriptions.platform.permission:subscriptions.usage.view')->whereNumber('plan')->name('plans.tenants');
        Route::get('/matrix', [PlanCatalogController::class, 'matrix'])->middleware($view)->name('matrix');

        Route::prefix('/plans/{plan}/versions')->whereNumber('plan')->name('versions.')->group(function () use ($view, $manage, $publish) {
            Route::post('/', [PlanVersionController::class, 'store'])->middleware($manage)->name('store');
            Route::get('/{version}', [PlanVersionController::class, 'show'])->middleware($view)->whereNumber('version')->name('show');
            Route::get('/{version}/impact', [PlanVersionController::class, 'impact'])->middleware($view)->whereNumber('version')->name('impact');
            Route::get('/{version}/preview', [PlanVersionController::class, 'preview'])->middleware($view)->whereNumber('version')->name('preview');
            Route::post('/{version}/migrate-tenants', [PlanVersionController::class, 'migrateTenants'])
                ->middleware('subscriptions.platform.permission:subscriptions.tenants.manage')->whereNumber('version')->name('migrate-tenants');
            Route::patch('/{version}', [PlanVersionController::class, 'update'])->middleware($manage)->whereNumber('version')->name('update');
            Route::put('/{version}/entitlements', [PlanVersionController::class, 'entitlements'])->middleware($manage)->whereNumber('version')->name('entitlements');
            Route::delete('/{version}', [PlanVersionController::class, 'destroy'])->middleware($manage)->whereNumber('version')->name('destroy');
            Route::post('/{version}/publish', [PlanVersionController::class, 'publish'])->middleware($publish)->whereNumber('version')->name('publish');
            Route::post('/{version}/unschedule', [PlanVersionController::class, 'unschedule'])->middleware($publish)->whereNumber('version')->name('unschedule');
            Route::post('/{version}/retire', [PlanVersionController::class, 'retire'])->middleware($publish)->whereNumber('version')->name('retire');
        });

        Route::get('/features', [FeatureController::class, 'index'])->middleware($view)->name('features.index');
        Route::post('/features', [FeatureController::class, 'store'])->middleware($features)->name('features.store');
        Route::patch('/features/{feature}', [FeatureController::class, 'update'])->middleware($features)->whereNumber('feature')->name('features.update');
        Route::put('/features/{feature}/dependencies', [FeatureController::class, 'dependencies'])->middleware($features)->whereNumber('feature')->name('features.dependencies');

        Route::prefix('/tenants/{tenant}')->whereNumber('tenant')->name('tenants.')->group(function () {
            Route::get('/', [TenantSubscriptionController::class, 'show'])
                ->middleware('subscriptions.platform.permission:subscriptions.usage.view')->name('show');
            Route::post('/preview', [TenantSubscriptionController::class, 'preview'])
                ->middleware('subscriptions.platform.permission:subscriptions.tenants.manage')->name('preview');
            Route::post('/assign', [TenantSubscriptionController::class, 'assign'])
                ->middleware('subscriptions.platform.permission:subscriptions.tenants.manage')->name('assign');
            Route::post('/pending/cancel', [TenantSubscriptionController::class, 'cancelPending'])
                ->middleware('subscriptions.platform.permission:subscriptions.tenants.manage')->name('pending.cancel');
            Route::post('/overrides', [TenantSubscriptionController::class, 'grantOverride'])
                ->middleware('subscriptions.platform.permission:subscriptions.overrides.manage')->name('overrides.grant');
            Route::post('/overrides/{override}/revoke', [TenantSubscriptionController::class, 'revokeOverride'])
                ->middleware('subscriptions.platform.permission:subscriptions.overrides.manage')->whereNumber('override')->name('overrides.revoke');
        });

        Route::get('/policies', [SubscriptionPolicyController::class, 'show'])->middleware($view)->name('policies.show');
        Route::put('/policies', [SubscriptionPolicyController::class, 'update'])
            ->middleware('subscriptions.platform.permission:subscriptions.policies.manage')->name('policies.update');

        Route::get('/tax', [SubscriptionPolicyController::class, 'showTax'])->middleware($view)->name('tax.show');
        Route::put('/tax', [SubscriptionPolicyController::class, 'updateTax'])
            ->middleware('subscriptions.platform.permission:subscriptions.policies.manage')->name('tax.update');
        Route::post('/tax/preview', [SubscriptionPolicyController::class, 'previewTax'])
            ->middleware('subscriptions.platform.permission:subscriptions.policies.manage')->name('tax.preview');

        $usage = 'subscriptions.platform.permission:subscriptions.usage.view';
        $review = 'subscriptions.platform.permission:subscriptions.requests.review';
        Route::get('/overview', [SubscriptionAnalyticsController::class, 'overview'])->middleware($usage)->name('overview');
        Route::get('/usage', [SubscriptionAnalyticsController::class, 'usage'])->middleware($usage)->name('usage');
        Route::get('/revenue', [SubscriptionAnalyticsController::class, 'revenue'])
            ->middleware('subscriptions.platform.permission:subscriptions.revenue.view')->name('revenue');

        Route::get('/upgrade-requests', [UpgradeRequestController::class, 'index'])->middleware($review)->name('upgrade-requests.index');
        Route::post('/upgrade-requests/{upgradeRequest}/approve', [UpgradeRequestController::class, 'approve'])
            ->middleware([$review, 'subscriptions.platform.permission:subscriptions.tenants.manage'])->whereNumber('upgradeRequest')->name('upgrade-requests.approve');
        Route::post('/upgrade-requests/{upgradeRequest}/reject', [UpgradeRequestController::class, 'reject'])
            ->middleware($review)->whereNumber('upgradeRequest')->name('upgrade-requests.reject');
        Route::post('/upgrade-requests/{upgradeRequest}/request-info', [UpgradeRequestController::class, 'requestInfo'])
            ->middleware($review)->whereNumber('upgradeRequest')->name('upgrade-requests.request-info');

        Route::get('/audits', [SubscriptionAuditController::class, 'catalog'])
            ->middleware('subscriptions.platform.permission:subscriptions.audit.view')->name('audits.catalog');
    });
});
