<?php

use Illuminate\Support\Facades\Route;
use Modules\Notifications\Http\Controllers\NotificationInboxController;

$inboxRoutes = function (string $audience): void {
    Route::get('/', [NotificationInboxController::class, 'index']);
    Route::get('/unread-count', [NotificationInboxController::class, 'unreadCount']);
    Route::get('/preferences', [NotificationInboxController::class, 'preferences']);
    Route::put('/preferences', [NotificationInboxController::class, 'updatePreferences']);
    Route::patch('/read-all', [NotificationInboxController::class, 'markAllRead']);
    Route::post('/bulk-action', [NotificationInboxController::class, 'bulkAction'])
        ->middleware('api.throttle:notifications');
    Route::get('/{id}', [NotificationInboxController::class, 'show']);
    Route::get('/{id}/open', [NotificationInboxController::class, 'open']);
    Route::patch('/{id}/read', [NotificationInboxController::class, 'markRead'])
        ->middleware('api.throttle:notifications');
    Route::patch('/{id}/unread', [NotificationInboxController::class, 'markUnread'])
        ->middleware('api.throttle:notifications');
    Route::patch('/{id}/archive', [NotificationInboxController::class, 'archive'])
        ->middleware('api.throttle:notifications');
    Route::patch('/{id}/restore', [NotificationInboxController::class, 'restore'])
        ->middleware('api.throttle:notifications');
};

Route::middleware(['auth:api'])
    ->prefix('tenant/notifications')
    ->group(function () use ($inboxRoutes): void {
        $inboxRoutes('tenant');
    });

Route::middleware(['auth:api'])
    ->prefix('admin/notifications')
    ->group(function () use ($inboxRoutes): void {
        $inboxRoutes('admin');
    });
