<?php

namespace Modules\Donations\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Donations\Http\Requests\IndexProjectInstallmentDuesRequest;
use Modules\Donations\Http\Requests\UpdateDueStatusRequest;
use Modules\Donations\Models\DonationProject;
use Modules\Donations\Models\ProjectInstallmentDue;
use Modules\Donations\Services\ProjectInstallmentDueService;
use Modules\Donations\Support\ContributionBalance;
use Modules\Donations\Support\MoneyMath;
use Modules\Family\Models\Family;
use Modules\Family\Support\FamilyHeadDisplayName;

class ProjectInstallmentDuesController extends Controller
{
    public function __construct(private readonly ProjectInstallmentDueService $installmentService)
    {
    }

    public function index(IndexProjectInstallmentDuesRequest $request): JsonResponse
    {
        $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
        $table = (new ProjectInstallmentDue)->getTable();

        $query = ProjectInstallmentDue::forTenant($tenantId)
            ->select("{$table}.*")
            ->with([
                'family',
                'family.members' => function ($query): void {
                    $query->whereIn('relationship_to_head', ['self', 'head']);
                },
                'project',
            ]);

        if ($request->filled('project_id')) {
            $query->where("{$table}.project_id", $request->string('project_id'));
        }
        if ($request->filled('family_id')) {
            $query->where("{$table}.family_id", $request->string('family_id'));
        }
        if ($request->filled('status')) {
            $query->where("{$table}.status", $request->string('status'));
        }
        if ($request->filled('due_date_from')) {
            $query->whereDate("{$table}.due_date", '>=', $request->string('due_date_from'));
        }
        if ($request->filled('due_date_to')) {
            $query->whereDate("{$table}.due_date", '<=', $request->string('due_date_to'));
        }
        if ($request->boolean('overdue_only')) {
            $query->whereDate("{$table}.due_date", '<', now()->toDateString())
                ->whereIn("{$table}.status", ['pending', 'partially_paid']);
        }

        $request->bccFilter($tenantId)->applyToProjectInstallmentDueQuery($query, $tenantId);

        if ($request->filled('search')) {
            $needle = '%'.mb_strtolower($request->string('search')->toString()).'%';
            $query->where(function (Builder $scoped) use ($needle, $table): void {
                $scoped->whereRaw('LOWER('.$table.'.installment_label) LIKE ?', [$needle])
                    ->orWhereHas('project', function (Builder $projectQuery) use ($needle): void {
                        $projectQuery->whereRaw('LOWER(name) LIKE ?', [$needle]);
                    })
                    ->orWhereHas('family', function (Builder $familyQuery) use ($needle): void {
                        $familyQuery->whereRaw('LOWER(family_code) LIKE ?', [$needle])
                            ->orWhereRaw('LOWER(head_of_family) LIKE ?', [$needle]);
                    });
            });
        }

        $this->applyInstallmentListSort($query, $request->sortColumn(), $request->sortDirection());

        $paginator = $query->paginate((int) $request->input('per_page', 20));
        $paginator->getCollection()->transform(function (ProjectInstallmentDue $due): ProjectInstallmentDue {
            $due->setAttribute('outstanding_amount', ContributionBalance::outstandingForDue($due));
            $due->setAttribute('family_head_name', FamilyHeadDisplayName::resolve($due->family));

            return $due;
        });

        return response()->json([
            'success' => true,
            'data' => $paginator,
        ]);
    }

    public function waive(string $id, UpdateDueStatusRequest $request): JsonResponse
    {
        $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
        $userId = (int) Auth::id();
        $due = ProjectInstallmentDue::forTenant($tenantId)->findOrFail($id);

        if ($due->status !== 'pending' || MoneyMath::isPositive($due->amount_paid)) {
            return response()->json([
                'success' => false,
                'message' => 'Only unpaid installments can be waived. Reverse any payment before changing this installment.',
            ], 422);
        }

        $due = $this->installmentService->waiveDue($tenantId, $userId, $due, $request->validated()['reason'] ?? null);

        return response()->json([
            'success' => true,
            'message' => 'Installment waived successfully.',
            'data' => $due,
        ]);
    }

    public function cancel(string $id, UpdateDueStatusRequest $request): JsonResponse
    {
        $tenantId = app(\Modules\Tenants\Support\TenantContext::class)->requireEffectiveTenantId();
        $userId = (int) Auth::id();
        $due = ProjectInstallmentDue::forTenant($tenantId)->findOrFail($id);

        if ($due->status !== 'pending' || MoneyMath::isPositive($due->amount_paid)) {
            return response()->json([
                'success' => false,
                'message' => 'Only unpaid installments can be cancelled. Reverse any payment before changing this installment.',
            ], 422);
        }

        $due = $this->installmentService->cancelDue($tenantId, $userId, $due, $request->validated()['reason'] ?? null);

        return response()->json([
            'success' => true,
            'message' => 'Installment cancelled successfully.',
            'data' => $due,
        ]);
    }

    /**
     * @param  Builder<ProjectInstallmentDue>  $query
     */
    private function applyInstallmentListSort(Builder $query, string $sort, string $direction): void
    {
        $table = (new ProjectInstallmentDue)->getTable();
        $direction = $direction === 'desc' ? 'desc' : 'asc';

        if ($sort === 'due_date') {
            $query->orderBy("{$table}.due_date", $direction)->orderBy("{$table}.id");

            return;
        }

        if ($sort === 'installment_label') {
            $query->orderBy("{$table}.installment_label", $direction)
                ->orderBy("{$table}.due_date");

            return;
        }

        if ($sort === 'status') {
            $query->orderBy("{$table}.status", $direction)
                ->orderBy("{$table}.due_date");

            return;
        }

        if ($sort === 'outstanding') {
            $this->orderByOutstandingBalance($query, $table, $direction);

            return;
        }

        if ($sort === 'family_name') {
            $query->orderBy(
                Family::query()
                    ->select('head_of_family')
                    ->whereColumn('families.id', "{$table}.family_id")
                    ->limit(1),
                $direction
            )->orderBy("{$table}.due_date");

            return;
        }

        if ($sort === 'project_name') {
            $query->orderBy(
                DonationProject::query()
                    ->select('name')
                    ->whereColumn('donation_projects.id', "{$table}.project_id")
                    ->limit(1),
                $direction
            )->orderBy("{$table}.due_date");

            return;
        }

        $query->orderBy("{$table}.due_date", 'asc')->orderBy("{$table}.id");
    }

    /**
     * @param  Builder<ProjectInstallmentDue>  $query
     */
    private function orderByOutstandingBalance(Builder $query, string $table, string $direction): void
    {
        $direction = $direction === 'desc' ? 'desc' : 'asc';
        $expression = "{$table}.amount_due - {$table}.amount_paid";
        if ($query->getConnection()->getDriverName() === 'pgsql') {
            $query->orderByRaw("GREATEST({$expression}, 0) {$direction}");
        } else {
            $query->orderByRaw("(CASE WHEN ({$expression}) > 0 THEN ({$expression}) ELSE 0 END) {$direction}");
        }
        $query->orderBy("{$table}.due_date");
    }
}
