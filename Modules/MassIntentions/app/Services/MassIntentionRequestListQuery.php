<?php

namespace Modules\MassIntentions\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Modules\MassIntentions\Models\MassIntentionRequest;
use Modules\MassIntentions\Support\MassIntentionStatus;
use Modules\MassIntentions\Support\MassIntentionsSql;
use Modules\MassIntentions\Support\MassObligationStatus;

final class MassIntentionRequestListQuery
{
    /** @var array<string, string> */
    private const SORT_COLUMNS = [
        'requested_date' => 'requested_date',
        'beneficiary_name' => 'beneficiary_name',
        'intention_text' => 'intention_text',
        'intention_description' => 'intention_description',
        'status' => 'status',
    ];

    public function forTenant(Request $request, int $tenantId): Builder
    {
        $builder = MassIntentionRequest::query()
            ->where('tenant_id', $tenantId);

        if ($status = $request->input('status')) {
            $builder->where('status', $status);
        }

        if ($queue = $request->input('queue')) {
            if ($queue === 'not_scheduled') {
                $builder->where('status', MassIntentionStatus::ACCEPTED)
                    ->whereHas('obligations', fn ($q) => $q->where('status', MassObligationStatus::PENDING));
            } elseif ($queue === 'still_to_say') {
                $builder->where('status', MassIntentionStatus::ACCEPTED)
                    ->whereHas('obligations', fn ($q) => $q->whereIn('status', [
                        MassObligationStatus::PENDING,
                        MassObligationStatus::SCHEDULED,
                    ]));
            }
        }

        $like = MassIntentionsSql::likeOperator();

        if ($search = trim((string) $request->input('search', ''))) {
            $builder->where(function ($q) use ($search, $like): void {
                $q->where('beneficiary_name', $like, "%{$search}%")
                    ->orWhere('beneficiary_place', $like, "%{$search}%")
                    ->orWhere('beneficiary_bcc_name', $like, "%{$search}%")
                    ->orWhere('intention_text', $like, "%{$search}%")
                    ->orWhere('intention_description', $like, "%{$search}%");
            });
        }

        $requestedDate = trim((string) $request->input('requested_date', ''));
        if ($requestedDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $requestedDate) === 1) {
            $builder->whereDate('requested_date', $requestedDate);
        }

        return $this->applySort($builder, $request);
    }

    private function applySort(Builder $builder, Request $request): Builder
    {
        $sortKey = (string) $request->input('sort', 'requested_date');
        $column = self::SORT_COLUMNS[$sortKey] ?? 'requested_date';
        $direction = strtolower((string) $request->input('direction', 'asc')) === 'desc' ? 'desc' : 'asc';

        return $builder
            ->orderBy($column, $direction)
            ->orderByDesc('created_at');
    }
}
