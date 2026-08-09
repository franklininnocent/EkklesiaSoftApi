<?php

use Illuminate\Support\Facades\Route;
use Modules\MinistriesAssociations\Http\Controllers\Admin\AdminMinistriesAnalyticsController;
use Modules\MinistriesAssociations\Http\Controllers\Admin\AdminMinistriesAuditController;
use Modules\MinistriesAssociations\Http\Controllers\Admin\AdminMinistriesHealthController;
use Modules\MinistriesAssociations\Http\Controllers\Admin\AdminMinistriesOrganizationsController;
use Modules\MinistriesAssociations\Http\Controllers\Admin\AdminMinistriesOverviewController;
use Modules\MinistriesAssociations\Http\Controllers\Admin\AdminMinistriesReportsController;
use Modules\MinistriesAssociations\Http\Controllers\Admin\AdminMinistriesTenantsController;
use Modules\MinistriesAssociations\Http\Controllers\FamilyMinistriesController;
use Modules\MinistriesAssociations\Http\Controllers\GuestMemberController;
use Modules\MinistriesAssociations\Http\Controllers\MinistriesAuditLogController;
use Modules\MinistriesAssociations\Http\Controllers\MinistriesDashboardController;
use Modules\MinistriesAssociations\Http\Controllers\MinistriesModuleController;
use Modules\MinistriesAssociations\Http\Controllers\OrganizationCategoryController;
use Modules\MinistriesAssociations\Http\Controllers\OrganizationController;
use Modules\MinistriesAssociations\Http\Controllers\OrganizationLeadershipController;
use Modules\MinistriesAssociations\Http\Controllers\OrganizationMembershipController;
use Modules\MinistriesAssociations\Http\Controllers\OrganizationTypeController;
use Modules\MinistriesAssociations\Http\Controllers\ParishionerLookupController;
use Modules\MinistriesAssociations\Http\Controllers\PositionController;

/*
|--------------------------------------------------------------------------
| Ekklesia / Super Admin — Ministries Insights (platform, cross-tenant)
|--------------------------------------------------------------------------
| Do not merge this group into tenant middleware (feature/permission gates).
*/
Route::prefix('admin/ministries')
    ->middleware(['auth:api'])
    ->group(function (): void {
        Route::get('/overview', [AdminMinistriesOverviewController::class, 'show'])
            ->middleware('ministries.platform.permission:ministries.platform.overview');

        Route::get('/attention', [AdminMinistriesOverviewController::class, 'attention'])
            ->middleware('ministries.platform.permission:ministries.platform.overview');

        Route::get('/activity', [AdminMinistriesOverviewController::class, 'activity'])
            ->middleware('ministries.platform.permission:ministries.platform.overview');

        Route::get('/tenants/export', [AdminMinistriesTenantsController::class, 'export'])
            ->middleware('ministries.platform.permission:ministries.platform.export');

        Route::get('/tenants', [AdminMinistriesTenantsController::class, 'index'])
            ->middleware('ministries.platform.permission:ministries.platform.tenants');

        Route::get('/tenants/{tenantId}', [AdminMinistriesTenantsController::class, 'show'])
            ->middleware('ministries.platform.permission:ministries.platform.tenants')
            ->whereNumber('tenantId');

        Route::get('/organizations', [AdminMinistriesOrganizationsController::class, 'index'])
            ->middleware('ministries.platform.permission:ministries.platform.organizations');

        Route::get('/health', [AdminMinistriesHealthController::class, 'show'])
            ->middleware('ministries.platform.permission:ministries.platform.organizations');

        Route::get('/analytics/adoption', [AdminMinistriesAnalyticsController::class, 'adoption'])
            ->middleware('ministries.platform.permission:ministries.platform.analytics');

        Route::get('/analytics/usage', [AdminMinistriesAnalyticsController::class, 'usage'])
            ->middleware('ministries.platform.permission:ministries.platform.analytics');

        Route::get('/analytics/features', [AdminMinistriesAnalyticsController::class, 'features'])
            ->middleware('ministries.platform.permission:ministries.platform.analytics');

        Route::get('/analytics/trends', [AdminMinistriesAnalyticsController::class, 'trends'])
            ->middleware('ministries.platform.permission:ministries.platform.analytics');

        Route::get('/audit', [AdminMinistriesAuditController::class, 'index'])
            ->middleware('ministries.platform.permission:ministries.platform.audit');

        Route::get('/reports', [AdminMinistriesReportsController::class, 'index'])
            ->middleware('ministries.platform.permission:ministries.platform.reports');

        Route::get('/reports/{type}/export', [AdminMinistriesReportsController::class, 'export'])
            ->middleware('ministries.platform.permission:ministries.platform.export');

        Route::get('/reports/{type}', [AdminMinistriesReportsController::class, 'show'])
            ->middleware('ministries.platform.permission:ministries.platform.reports');
    });

Route::prefix('tenant/ministries')
    ->middleware(['auth:api', 'tenant.permission:ministries.view'])
    ->group(function (): void {
        Route::get('/module-status', [MinistriesModuleController::class, 'moduleStatus']);
    });

Route::prefix('tenant/ministries')
    ->middleware(['auth:api', 'tenant.feature.ministries', 'tenant.permission:ministries.view'])
    ->group(function (): void {
        Route::get('/categories', [OrganizationCategoryController::class, 'index']);
        Route::post('/categories/seed-defaults', [OrganizationCategoryController::class, 'seedDefaults'])
            ->middleware('tenant.permission:ministries.configure');
        Route::post('/categories', [OrganizationCategoryController::class, 'store'])
            ->middleware('tenant.permission:ministries.configure');
        Route::put('/categories/{categoryId}', [OrganizationCategoryController::class, 'update'])
            ->middleware('tenant.permission:ministries.configure');
        Route::patch('/categories/{categoryId}/status', [OrganizationCategoryController::class, 'updateStatus'])
            ->middleware('tenant.permission:ministries.configure');

        Route::get('/types', [OrganizationTypeController::class, 'index']);
        Route::post('/types/seed-defaults', [OrganizationTypeController::class, 'seedDefaults'])
            ->middleware('tenant.permission:ministries.configure');
        Route::post('/types', [OrganizationTypeController::class, 'store'])
            ->middleware('tenant.permission:ministries.configure');
        Route::put('/types/{typeId}', [OrganizationTypeController::class, 'update'])
            ->middleware('tenant.permission:ministries.configure');
        Route::patch('/types/{typeId}/status', [OrganizationTypeController::class, 'updateStatus'])
            ->middleware('tenant.permission:ministries.configure');

        Route::get('/positions', [PositionController::class, 'index']);
        Route::post('/positions/seed-defaults', [PositionController::class, 'seedDefaults'])
            ->middleware('tenant.permission:ministries.configure');
        Route::post('/positions', [PositionController::class, 'store'])
            ->middleware('tenant.permission:ministries.configure');
        Route::put('/positions/{positionId}', [PositionController::class, 'update'])
            ->middleware('tenant.permission:ministries.configure');
        Route::patch('/positions/{positionId}/status', [PositionController::class, 'updateStatus'])
            ->middleware('tenant.permission:ministries.configure');

        Route::get('/guest-members', [GuestMemberController::class, 'index']);
        Route::post('/guest-members', [GuestMemberController::class, 'store'])
            ->middleware('tenant.permission:ministries.manage_members');
        Route::post('/guest-members/{guestMemberId}/link-parishioner', [GuestMemberController::class, 'linkParishioner'])
            ->middleware('tenant.permission:ministries.manage_members');
        Route::get('/guest-members/{guestMemberId}', [GuestMemberController::class, 'show']);
        Route::put('/guest-members/{guestMemberId}', [GuestMemberController::class, 'update'])
            ->middleware('tenant.permission:ministries.manage_members');

        Route::get('/parishioners/lookup', [ParishionerLookupController::class, 'lookup'])
            ->middleware('tenant.permission:ministries.manage_members');

        Route::get('/family-members/{familyMemberId}/affiliations', [FamilyMinistriesController::class, 'affiliations']);
        Route::post('/family-members/{familyMemberId}/enroll', [FamilyMinistriesController::class, 'enroll'])
            ->middleware('tenant.permission:ministries.manage_members');

        Route::get('/audit-logs', [MinistriesAuditLogController::class, 'index']);

        Route::get('/dashboard', [MinistriesDashboardController::class, 'show']);

        Route::get('/organizations', [OrganizationController::class, 'index']);
        Route::post('/organizations', [OrganizationController::class, 'store'])
            ->middleware('tenant.permission:ministries.create');

        Route::get('/organizations/{organizationId}/summary', [OrganizationController::class, 'summary']);

        Route::get('/organizations/{organizationId}/members', [OrganizationMembershipController::class, 'index']);
        Route::post('/organizations/{organizationId}/members', [OrganizationMembershipController::class, 'store'])
            ->middleware('tenant.permission:ministries.manage_members');
        Route::get('/organizations/{organizationId}/members/{membershipId}', [OrganizationMembershipController::class, 'show']);
        Route::patch('/organizations/{organizationId}/members/{membershipId}/status', [OrganizationMembershipController::class, 'updateStatus'])
            ->middleware('tenant.permission:ministries.manage_members');
        Route::post('/organizations/{organizationId}/members/{membershipId}/re-enroll', [OrganizationMembershipController::class, 'reEnroll'])
            ->middleware('tenant.permission:ministries.manage_members');

        Route::get('/organizations/{organizationId}/leadership/current', [OrganizationLeadershipController::class, 'current']);
        Route::get('/organizations/{organizationId}/leadership/timeline', [OrganizationLeadershipController::class, 'timeline']);
        Route::post('/organizations/{organizationId}/leadership/assign', [OrganizationLeadershipController::class, 'assign'])
            ->middleware('tenant.permission:ministries.manage_leadership');
        Route::post('/organizations/{organizationId}/leadership/handover', [OrganizationLeadershipController::class, 'handover'])
            ->middleware('tenant.permission:ministries.manage_leadership');
        Route::post('/organizations/{organizationId}/leadership/{termId}/terminate', [OrganizationLeadershipController::class, 'terminate'])
            ->middleware('tenant.permission:ministries.manage_leadership');

        Route::get('/organizations/{organizationId}/audit-logs', [MinistriesAuditLogController::class, 'organizationIndex']);

        Route::post('/organizations/{organizationId}/restore', [OrganizationController::class, 'restore'])
            ->middleware('tenant.permission:ministries.delete');

        Route::get('/organizations/{organizationId}', [OrganizationController::class, 'show']);
        Route::put('/organizations/{organizationId}', [OrganizationController::class, 'update'])
            ->middleware('tenant.permission:ministries.edit');
        Route::patch('/organizations/{organizationId}/status', [OrganizationController::class, 'updateStatus'])
            ->middleware('tenant.permission:ministries.edit');
        Route::delete('/organizations/{organizationId}', [OrganizationController::class, 'destroy'])
            ->middleware('tenant.permission:ministries.delete');
    });
