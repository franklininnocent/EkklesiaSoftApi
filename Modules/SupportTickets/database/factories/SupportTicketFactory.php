<?php

namespace Modules\SupportTickets\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Authentication\Models\User;
use Modules\SupportTickets\Models\SupportQueue;
use Modules\SupportTickets\Models\SupportRequestType;
use Modules\SupportTickets\Models\SupportTicket;
use Modules\SupportTickets\Support\TicketPriority;
use Modules\SupportTickets\Support\TicketStatus;
use Modules\Tenants\Models\Tenant;

class SupportTicketFactory extends Factory
{
    protected $model = SupportTicket::class;

    public function definition(): array
    {
        $type = SupportRequestType::query()->first()
            ?? SupportRequestType::query()->create([
                'slug' => 'other',
                'name' => 'Other',
                'sort_order' => 99,
                'active' => true,
            ]);

        return [
            'ticket_number' => 'ES-PENDING',
            'tenant_id' => Tenant::factory(),
            'requester_user_id' => User::factory(),
            'request_type_id' => $type->id,
            'queue_id' => SupportQueue::query()->value('id'),
            'subject' => fake()->sentence(6),
            'description' => fake()->paragraph(),
            'priority' => TicketPriority::NORMAL,
            'status' => TicketStatus::NEW,
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (SupportTicket $ticket): void {
            if ($ticket->ticket_number === 'ES-PENDING') {
                $ticket->updateQuietly([
                    'ticket_number' => SupportTicket::formatTicketNumber($ticket->id),
                ]);
            }
        });
    }
}
