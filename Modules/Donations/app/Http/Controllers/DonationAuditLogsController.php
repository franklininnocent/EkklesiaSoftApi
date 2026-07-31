<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Models\DonationAuditLog;
use Modules\Donations\Services\ParishActivityFeedPresenter;

class DonationAuditLogsController extends Controller
{
    public function __construct(private readonly ParishActivityFeedPresenter $presenter)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $tenantId = Auth::user()->tenant_id;
        $query = DonationAuditLog::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('created_at');

        if ($request->filled('target_type')) {
            $query->where('target_type', $request->string('target_type'));
        }

        if ($request->filled('target_id')) {
            $query->where('target_id', $request->string('target_id'));
        }

        if ($request->filled('event')) {
            $query->where('event', $request->string('event'));
        }

        if ($request->filled('category')) {
            $this->applyCategoryFilter($query, $request->string('category'));
        }

        $logs = $query->paginate((int) $request->input('per_page', 30));
        $actorIds = collect($logs->items())->pluck('actor_user_id')->filter()->unique()->values();
        $actorNames = $actorIds->isEmpty()
            ? []
            : User::query()->whereIn('id', $actorIds)->pluck('name', 'id')->all();

        $items = $this->presenter->presentMany(collect($logs->items()), $actorNames);
        $payload = $logs->toArray();
        $payload['data'] = $items;

        return response()->json([
            'success' => true,
            'data' => $payload,
        ]);
    }

    private function applyCategoryFilter($query, string $category): void
    {
        match ($category) {
            'payments' => $query->whereIn('target_type', ['payment', 'payment_batch', 'refund']),
            'families' => $query->whereIn('target_type', ['family']),
            'projects' => $query->whereIn('target_type', ['project', 'project_installment']),
            'plans' => $query->whereIn('target_type', ['plan', 'due']),
            'settings' => $query->whereIn('target_type', ['fund', 'donation_settings']),
            'donors' => $query->where('target_type', 'donor'),
            'categories' => $query->where('target_type', 'donation_category'),
            'offerings' => $query->where('target_type', 'donation'),
            'recurring' => $query->where('target_type', 'recurring_schedule'),
            'reports' => $query->where('target_type', 'report_export'),
            default => null,
        };
    }
}
