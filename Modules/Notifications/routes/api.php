<?php

use Illuminate\Support\Facades\Route;
use Modules\Notifications\Http\Controllers\NotificationInboxController;

$inboxRoutes = function (string $audience): void {
    $register = function (string $method, string $uri, array|string $action) use ($audience) {
        return Route::{$method}($uri, $action)->defaults('audience', $audience);
    };

    $register('get', '/', [NotificationInboxController::class, 'index']);
    $register('get', '/unread-count', [NotificationInboxController::class, 'unreadCount']);
    $register('get', '/preferences', [NotificationInboxController::class, 'preferences']);
    $register('put', '/preferences', [NotificationInboxController::class, 'updatePreferences']);
    $register('patch', '/read-all', [NotificationInboxController::class, 'markAllRead']);
    $register('post', '/bulk-action', [NotificationInboxController::class, 'bulkAction'])
        ->middleware('api.throttle:notifications');
    $register('get', '/{id}', [NotificationInboxController::class, 'show']);
    $register('get', '/{id}/open', [NotificationInboxController::class, 'open']);
    $register('patch', '/{id}/read', [NotificationInboxController::class, 'markRead'])
        ->middleware('api.throttle:notifications');
    $register('patch', '/{id}/unread', [NotificationInboxController::class, 'markUnread'])
        ->middleware('api.throttle:notifications');
    $register('patch', '/{id}/archive', [NotificationInboxController::class, 'archive'])
        ->middleware('api.throttle:notifications');
    $register('patch', '/{id}/restore', [NotificationInboxController::class, 'restore'])
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
