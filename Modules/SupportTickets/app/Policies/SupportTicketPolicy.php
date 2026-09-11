<?php

namespace Modules\SupportTickets\Policies;

use Modules\Authentication\Models\User;
use Modules\SupportTickets\Models\SupportTicket;
use Modules\SupportTickets\Services\SupportTicketService;
use Modules\SupportTickets\Support\ResolvedByActor;
use Modules\SupportTickets\Support\TicketStatus;
use Modules\Tenants\Policies\Concerns\AuthorizesTenantPermission;
use Modules\Tenants\Support\TenantContext;

class SupportTicketPolicy
{
    use AuthorizesTenantPermission;

    public function __construct(
        private readonly SupportTicketService $tickets,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'support.tickets.view')
            || $this->tickets->actorHasOpsView($user);
    }

    public function view(User $user, SupportTicket $ticket): bool
    {
        if ($this->tickets->actorHasOpsView($user)) {
            return true;
        }

        if (! $this->allows($user, 'support.tickets.view')) {
            return false;
        }

        if (! $this->matchesTenant($user, $ticket->tenant_id)) {
            return false;
        }

        if ($this->allows($user, 'support.tickets.view_all_tenant')) {
            return true;
        }

        return (int) $ticket->requester_user_id === (int) $user->id
            || $ticket->isParticipant((int) $user->id);
    }

    public function create(User $user): bool
    {
        if (app(TenantContext::class)->isSupportSession()) {
            return false;
        }

        return $this->allows($user, 'support.tickets.create');
    }

    public function comment(User $user, SupportTicket $ticket): bool
    {
        if ($this->tickets->actorHasOpsView($user)) {
            return $user->hasPermission('support.ops.tickets.comment') && $this->view($user, $ticket);
        }

        if (app(TenantContext::class)->isSupportSession()) {
            return false;
        }

        return $this->allows($user, 'support.tickets.comment') && $this->view($user, $ticket);
    }

    public function internalNote(User $user): bool
    {
        return method_exists($user, 'hasPermission')
            && $user->hasPermission('support.ops.tickets.internal_note');
    }

    public function cancel(User $user, SupportTicket $ticket): bool
    {
        if (app(TenantContext::class)->isSupportSession()) {
            return false;
        }

        return $this->allows($user, 'support.tickets.cancel') && $this->view($user, $ticket);
    }

    public function resolveTenant(User $user, SupportTicket $ticket): bool
    {
        if (app(TenantContext::class)->isSupportSession()) {
            return false;
        }

        return $this->allows($user, 'support.tickets.resolve') && $this->view($user, $ticket);
    }

    public function confirmResolution(User $user, SupportTicket $ticket): bool
    {
        if (app(TenantContext::class)->isSupportSession()) {
            return false;
        }

        if (! $this->view($user, $ticket)) {
            return false;
        }

        if ($ticket->status !== TicketStatus::RESOLVED) {
            return false;
        }

        return $ticket->resolved_by_actor === ResolvedByActor::EKKLESIA;
    }

    public function reopen(User $user, SupportTicket $ticket): bool
    {
        if ($this->tickets->actorHasOpsView($user)) {
            return $user->hasPermission('support.ops.tickets.reopen') && $this->view($user, $ticket);
        }

        if (app(TenantContext::class)->isSupportSession()) {
            return false;
        }

        return $this->allows($user, 'support.tickets.reopen') && $this->view($user, $ticket);
    }

    public function manageParticipants(User $user, SupportTicket $ticket): bool
    {
        if (app(TenantContext::class)->isSupportSession()) {
            return false;
        }

        return $this->allows($user, 'support.tickets.participants') && $this->view($user, $ticket);
    }

    public function attach(User $user, SupportTicket $ticket): bool
    {
        if (app(TenantContext::class)->isSupportSession()) {
            return false;
        }

        return $this->allows($user, 'support.tickets.attachments') && $this->view($user, $ticket);
    }

    public function assign(User $user): bool
    {
        return $user->hasPermission('support.ops.tickets.assign');
    }

    public function changeStatus(User $user): bool
    {
        return $user->hasPermission('support.ops.tickets.change_status');
    }

    public function changePriority(User $user): bool
    {
        return $user->hasPermission('support.ops.tickets.change_priority');
    }

    public function resolve(User $user): bool
    {
        return $user->hasPermission('support.ops.tickets.resolve');
    }

    public function close(User $user): bool
    {
        return $user->hasPermission('support.ops.tickets.close');
    }

    public function cancelOps(User $user): bool
    {
        return $user->hasPermission('support.ops.tickets.close')
            && $user->hasPermission('support.ops.tickets.change_status');
    }
}
