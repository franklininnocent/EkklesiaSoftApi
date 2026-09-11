<?php

namespace Modules\SupportTickets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\SupportTickets\Models\SupportTicket;
use Modules\SupportTickets\Support\TenantResolutionCategory;
use Modules\SupportTickets\Support\TicketStatus;
use Modules\Tenants\Support\TenantContext;

class ResolveTenantTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'resolution_summary' => ['nullable', 'string', 'max:10000'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $ticket = $this->resolveTicket();
            if (! $ticket) {
                return;
            }

            if (! in_array($ticket->status, TicketStatus::tenantResolvable(), true)) {
                $validator->errors()->add('status', 'This ticket cannot be marked as resolved.');
            }

            $summary = trim((string) $this->input('resolution_summary', ''));
            if (TenantResolutionCategory::requiresSummary($ticket->status) && $summary === '') {
                $validator->errors()->add(
                    'resolution_summary',
                    'Please explain how this ticket was resolved while support is actively working on it.'
                );
            }
        });
    }

    private function resolveTicket(): ?SupportTicket
    {
        $identifier = (string) $this->route('ticket');
        if ($identifier === '') {
            return null;
        }

        $tenantId = app(TenantContext::class)->requireEffectiveTenantId();
        $query = SupportTicket::query()->forTenant($tenantId);

        if (preg_match('/^ES-\d+$/i', $identifier)) {
            return $query->byTicketNumber($identifier)->first();
        }

        if (ctype_digit($identifier)) {
            return $query->whereKey((int) $identifier)->first();
        }

        return null;
    }
}
