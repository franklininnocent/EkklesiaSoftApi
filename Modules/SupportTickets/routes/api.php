<?php

use Illuminate\Support\Facades\Route;
use Modules\SupportTickets\Http\Controllers\OpsSupportTicketController;
use Modules\SupportTickets\Http\Controllers\OpsTicketCatalogController;
use Modules\SupportTickets\Http\Controllers\TenantSupportTicketController;

Route::prefix('tenant/support')
    ->middleware(['auth:api', 'tenant.permission:support.tickets.view'])
    ->group(function (): void {
        Route::get('/dashboard', [TenantSupportTicketController::class, 'dashboard']);
        Route::get('/lookups', [TenantSupportTicketController::class, 'lookups']);
        Route::get('/tickets', [TenantSupportTicketController::class, 'index']);
        Route::get('/tickets/{ticket}', [TenantSupportTicketController::class, 'show']);
        Route::get('/tickets/{ticket}/attachments/{attachmentId}/download', [TenantSupportTicketController::class, 'downloadAttachment']);

        Route::middleware('support.ticket.parish_actor')->group(function (): void {
            Route::post('/tickets', [TenantSupportTicketController::class, 'store'])
                ->middleware('tenant.permission:support.tickets.create');
            Route::post('/tickets/{ticket}/comments', [TenantSupportTicketController::class, 'comment'])
                ->middleware('tenant.permission:support.tickets.comment');
            Route::post('/tickets/{ticket}/participants', [TenantSupportTicketController::class, 'addParticipant'])
                ->middleware('tenant.permission:support.tickets.participants');
            Route::delete('/tickets/{ticket}/participants/{userId}', [TenantSupportTicketController::class, 'removeParticipant'])
                ->middleware('tenant.permission:support.tickets.participants');
            Route::post('/tickets/{ticket}/attachments', [TenantSupportTicketController::class, 'uploadAttachment'])
                ->middleware('tenant.permission:support.tickets.attachments');
            Route::post('/tickets/{ticket}/cancel', [TenantSupportTicketController::class, 'cancel'])
                ->middleware('tenant.permission:support.tickets.cancel');
            Route::post('/tickets/{ticket}/resolve', [TenantSupportTicketController::class, 'resolve'])
                ->middleware('tenant.permission:support.tickets.resolve');
            Route::post('/tickets/{ticket}/reopen', [TenantSupportTicketController::class, 'reopen'])
                ->middleware('tenant.permission:support.tickets.reopen');
            Route::post('/tickets/{ticket}/confirm-resolution', [TenantSupportTicketController::class, 'confirmResolution']);
        });
    });

Route::prefix('support/ticket-catalog/request-types')
    ->middleware(['auth:api', 'support.permission:support.configuration.manage'])
    ->group(function (): void {
        Route::get('/', [OpsTicketCatalogController::class, 'index']);
        Route::post('/', [OpsTicketCatalogController::class, 'store']);
        Route::put('/{requestType}', [OpsTicketCatalogController::class, 'update']);
        Route::delete('/{requestType}', [OpsTicketCatalogController::class, 'destroy']);
        Route::post('/{requestType}/categories', [OpsTicketCatalogController::class, 'storeCategory']);
        Route::put('/{requestType}/categories/{category}', [OpsTicketCatalogController::class, 'updateCategory']);
        Route::delete('/{requestType}/categories/{category}', [OpsTicketCatalogController::class, 'destroyCategory']);
    });

Route::prefix('support/tickets')
    ->middleware(['auth:api'])
    ->group(function (): void {
        Route::get('/dashboard', [OpsSupportTicketController::class, 'dashboard'])
            ->middleware('support.permission:support.ops.tickets.view');
        Route::get('/', [OpsSupportTicketController::class, 'index'])
            ->middleware('support.permission:support.ops.tickets.view');
        Route::get('/{ticket}', [OpsSupportTicketController::class, 'show'])
            ->middleware('support.permission:support.ops.tickets.view');
        Route::post('/{ticket}/assign', [OpsSupportTicketController::class, 'assign'])
            ->middleware('support.permission:support.ops.tickets.assign');
        Route::post('/{ticket}/status/in-progress', [OpsSupportTicketController::class, 'startProgress'])
            ->middleware('support.permission:support.ops.tickets.change_status');
        Route::post('/{ticket}/status/awaiting-you', [OpsSupportTicketController::class, 'awaitingYou'])
            ->middleware('support.permission:support.ops.tickets.change_status');
        Route::post('/{ticket}/status/awaiting-ekklesia', [OpsSupportTicketController::class, 'awaitingEkklesia'])
            ->middleware('support.permission:support.ops.tickets.change_status');
        Route::post('/{ticket}/close', [OpsSupportTicketController::class, 'close'])
            ->middleware('support.permission:support.ops.tickets.close');
        Route::post('/{ticket}/reopen', [OpsSupportTicketController::class, 'reopen'])
            ->middleware('support.permission:support.ops.tickets.reopen');
        Route::post('/{ticket}/cancel', [OpsSupportTicketController::class, 'cancel'])
            ->middleware('support.permission:support.ops.tickets.close');
        Route::put('/{ticket}/priority', [OpsSupportTicketController::class, 'changePriority'])
            ->middleware('support.permission:support.ops.tickets.change_priority');
        Route::post('/{ticket}/comments', [OpsSupportTicketController::class, 'comment'])
            ->middleware('support.permission:support.ops.tickets.comment');
        Route::post('/{ticket}/internal-notes', [OpsSupportTicketController::class, 'internalNote'])
            ->middleware('support.permission:support.ops.tickets.internal_note');
        Route::post('/{ticket}/resolve', [OpsSupportTicketController::class, 'resolve'])
            ->middleware('support.permission:support.ops.tickets.resolve');
        Route::get('/{ticket}/attachments/{attachmentId}/download', [OpsSupportTicketController::class, 'downloadAttachment'])
            ->middleware('support.permission:support.ops.tickets.view');
    });
