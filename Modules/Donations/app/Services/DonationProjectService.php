<?php

namespace Modules\Donations\Services;

use Illuminate\Support\Facades\DB;
use Modules\Donations\Models\DonationProject;
use Modules\Donations\Models\ProjectFamilyAssignment;
use Modules\Donations\Models\ProjectInstallmentDue;
use Modules\Donations\Support\ContributionBalance;
use Modules\Family\Models\Family;

class DonationProjectService
{
    public function __construct(private readonly DonationAuditService $auditService)
    {
    }

    public function create(int $tenantId, int $userId, array $payload): DonationProject
    {
        return DB::transaction(function () use ($tenantId, $userId, $payload): DonationProject {
            $assignments = $payload['assignments'] ?? [];
            unset($payload['assignments']);

            $payload['tenant_id'] = $tenantId;
            $payload['created_by'] = $userId;
            $payload['updated_by'] = $userId;
            $payload['raised_amount'] = $payload['raised_amount'] ?? 0;
            $payload['entity_kind'] = $payload['entity_kind'] ?? 'project';

            $project = DonationProject::create($payload);

            if (!empty($assignments)) {
                $this->syncAssignments($tenantId, $userId, $project, $assignments);
            }

            $this->auditService->log($tenantId, 'project.created', 'project', $project->id, null, $project->toArray());

            return $project->fresh(['fund', 'assignments.family']);
        });
    }

    public function update(int $tenantId, int $userId, DonationProject $project, array $payload): DonationProject
    {
        return DB::transaction(function () use ($tenantId, $userId, $project, $payload): DonationProject {
            $assignments = $payload['assignments'] ?? null;
            unset($payload['assignments']);

            $project->fill($payload);
            $project->updated_by = $userId;
            $project->save();

            if ($assignments !== null) {
                $this->syncAssignments($tenantId, $userId, $project, $assignments);
            }

            $this->maybeMarkCompleted($project);

            $this->auditService->log($tenantId, 'project.updated', 'project', $project->id, null, $project->fresh()->toArray());

            return $project->fresh(['fund', 'assignments.family']);
        });
    }

    /**
     * @param array<int, array<string, mixed>> $assignments
     */
    public function syncAssignments(int $tenantId, int $userId, DonationProject $project, array $assignments): void
    {
        foreach ($assignments as $row) {
            $familyId = $row['family_id'];
            $effectiveFrom = $row['effective_from'] ?? now()->toDateString();

            ProjectFamilyAssignment::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'project_id' => $project->id,
                    'family_id' => $familyId,
                    'effective_from' => $effectiveFrom,
                ],
                [
                    'target_amount' => $row['is_exempt'] ?? false ? null : (float) ($row['target_amount'] ?? $project->default_family_target),
                    'is_exempt' => (bool) ($row['is_exempt'] ?? false),
                    'effective_to' => $row['effective_to'] ?? null,
                    'status' => $row['status'] ?? 'active',
                    'notes' => $row['notes'] ?? null,
                    'updated_by' => $userId,
                ]
            );
        }
    }

    public function resolveFamilyTarget(DonationProject $project, string $familyId, string $asOfDate): ?float
    {
        $override = $this->findActiveAssignment($project, $familyId, $asOfDate);

        if ($override?->is_exempt) {
            return null;
        }

        if ($override && $override->target_amount !== null) {
            return (float) $override->target_amount;
        }

        if ($project->assignment_mode === 'individual') {
            return null;
        }

        return (float) $project->default_family_target;
    }

    /**
     * @return array<int, string>
     */
    public function getEnrolledFamilyIds(DonationProject $project, string $asOfDate): array
    {
        if ($project->assignment_mode === 'individual') {
            return ProjectFamilyAssignment::forTenant($project->tenant_id)
                ->where('project_id', $project->id)
                ->where('status', 'active')
                ->where('is_exempt', false)
                ->whereDate('effective_from', '<=', $asOfDate)
                ->where(function ($query) use ($asOfDate): void {
                    $query->whereNull('effective_to')
                        ->orWhereDate('effective_to', '>=', $asOfDate);
                })
                ->pluck('family_id')
                ->unique()
                ->values()
                ->all();
        }

        $familyIds = Family::query()
            ->where('tenant_id', $project->tenant_id)
            ->where('status', 'active')
            ->pluck('id')
            ->all();

        if ($project->assignment_mode === 'uniform_with_exceptions') {
            $exemptIds = ProjectFamilyAssignment::forTenant($project->tenant_id)
                ->where('project_id', $project->id)
                ->where('status', 'active')
                ->where('is_exempt', true)
                ->whereDate('effective_from', '<=', $asOfDate)
                ->where(function ($query) use ($asOfDate): void {
                    $query->whereNull('effective_to')
                        ->orWhereDate('effective_to', '>=', $asOfDate);
                })
                ->pluck('family_id')
                ->all();

            $familyIds = array_values(array_diff($familyIds, $exemptIds));
        }

        return $familyIds;
    }

    public function getDashboard(int $tenantId, DonationProject $project): array
    {
        $asOfDate = now()->toDateString();
        $enrolledIds = $this->getEnrolledFamilyIds($project, $asOfDate);

        $familyTargets = [];
        $exemptCount = ProjectFamilyAssignment::forTenant($tenantId)
            ->where('project_id', $project->id)
            ->where('status', 'active')
            ->where('is_exempt', true)
            ->count();

        $completedFamilies = 0;
        $partialFamilies = 0;
        $totalFamilyTarget = 0;
        $totalFamilyCollected = 0;

        foreach ($enrolledIds as $familyId) {
            $target = $this->resolveFamilyTarget($project, $familyId, $asOfDate) ?? 0;
            $collected = $this->resolveFamilyCollected($tenantId, $project->id, $familyId);
            $outstanding = max(0, $target - $collected);

            $familyTargets[] = [
                'family_id' => $familyId,
                'target_amount' => round($target, 2),
                'amount_collected' => round($collected, 2),
                'outstanding_amount' => round($outstanding, 2),
                'completion_percentage' => $target > 0 ? round(($collected / $target) * 100, 2) : 0,
            ];

            $totalFamilyTarget += $target;
            $totalFamilyCollected += $collected;

            if ($target > 0 && $collected >= $target) {
                $completedFamilies++;
            } elseif ($collected > 0) {
                $partialFamilies++;
            }
        }

        $installmentOutstanding = ContributionBalance::sumOutstanding(
            ProjectInstallmentDue::forTenant($tenantId)->where('project_id', $project->id)
        );

        $overallTarget = (float) $project->target_amount > 0
            ? (float) $project->target_amount
            : $totalFamilyTarget;

        $collected = (float) $project->raised_amount;
        $collectionPercentage = $overallTarget > 0 ? round(($collected / $overallTarget) * 100, 2) : 0;

        return [
            'project' => $project->load('fund'),
            'totals' => [
                'overall_target' => round($overallTarget, 2),
                'family_target_total' => round($totalFamilyTarget, 2),
                'collected' => round($collected, 2),
                'outstanding' => round(max(0, $overallTarget - $collected), 2),
                'installment_outstanding' => round($installmentOutstanding, 2),
                'collection_percentage' => $collectionPercentage,
            ],
            'families' => [
                'enrolled' => count($enrolledIds),
                'completed' => $completedFamilies,
                'partial' => $partialFamilies,
                'exempt' => $exemptCount,
            ],
            'family_progress' => $familyTargets,
            'installments' => [
                'total' => ProjectInstallmentDue::forTenant($tenantId)->where('project_id', $project->id)->count(),
                'paid' => ProjectInstallmentDue::forTenant($tenantId)->where('project_id', $project->id)->where('status', 'paid')->count(),
                'pending' => ProjectInstallmentDue::forTenant($tenantId)->where('project_id', $project->id)->whereIn('status', ['pending', 'partially_paid'])->count(),
            ],
        ];
    }

    public function recordCollection(int $tenantId, DonationProject $project, string $familyId, float $amount): void
    {
        $project->raised_amount = round((float) $project->raised_amount + $amount, 2);
        $project->save();

        $assignment = ProjectFamilyAssignment::forTenant($tenantId)
            ->where('project_id', $project->id)
            ->where('family_id', $familyId)
            ->where('status', 'active')
            ->latest('effective_from')
            ->first();

        if ($assignment) {
            $assignment->amount_collected = round((float) $assignment->amount_collected + $amount, 2);
            $assignment->save();
        }

        $this->maybeMarkCompleted($project->fresh());
    }

    public function getFamilyProjectSummary(int $tenantId, string $familyId): array
    {
        $asOfDate = now()->toDateString();
        $activeProjects = DonationProject::forTenant($tenantId)
            ->whereIn('status', ['active', 'completed'])
            ->orderBy('name')
            ->get();

        $projects = [];
        $totalTarget = 0;
        $totalCollected = 0;
        $totalOutstanding = 0;
        $totalInstallmentOutstanding = 0;

        foreach ($activeProjects as $project) {
            $enrolledIds = $this->getEnrolledFamilyIds($project, $asOfDate);
            if (!in_array($familyId, $enrolledIds, true)) {
                continue;
            }

            $target = $this->resolveFamilyTarget($project, $familyId, $asOfDate) ?? 0;
            $collected = $this->resolveFamilyCollected($tenantId, $project->id, $familyId);
            $outstanding = max(0, $target - $collected);
            $installmentOutstanding = ContributionBalance::sumOutstanding(
                ProjectInstallmentDue::forTenant($tenantId)
                    ->where('project_id', $project->id)
                    ->where('family_id', $familyId)
            );

            $projects[] = [
                'project_id' => $project->id,
                'project_name' => $project->name,
                'project_code' => $project->code,
                'assignment_mode' => $project->assignment_mode,
                'target_amount' => round($target, 2),
                'amount_collected' => round($collected, 2),
                'outstanding_amount' => round($outstanding, 2),
                'installment_outstanding' => round($installmentOutstanding, 2),
                'completion_percentage' => $target > 0 ? round(($collected / $target) * 100, 2) : 0,
                'status' => $project->status,
            ];

            $totalTarget += $target;
            $totalCollected += $collected;
            $totalOutstanding += $outstanding;
            $totalInstallmentOutstanding += $installmentOutstanding;
        }

        $outstandingInstallments = ProjectInstallmentDue::forTenant($tenantId)
            ->where('family_id', $familyId)
            ->whereIn('status', ['pending', 'partially_paid'])
            ->with('project:id,name,code')
            ->orderBy('due_date')
            ->limit(20)
            ->get()
            ->map(function (ProjectInstallmentDue $due): array {
                return [
                    'id' => $due->id,
                    'project_id' => $due->project_id,
                    'project_name' => $due->project?->name,
                    'installment_label' => $due->installment_label,
                    'due_date' => $due->due_date?->toDateString(),
                    'amount_due' => (float) $due->amount_due,
                    'amount_paid' => (float) $due->amount_paid,
                    'outstanding_amount' => ContributionBalance::outstandingForDue($due),
                    'status' => $due->status,
                ];
            });

        return [
            'projects' => $projects,
            'outstanding_installments' => $outstandingInstallments,
            'totals' => [
                'target_total' => round($totalTarget, 2),
                'collected' => round($totalCollected, 2),
                'outstanding' => round($totalOutstanding, 2),
                'installment_outstanding' => round($totalInstallmentOutstanding, 2),
            ],
        ];
    }

    public function reverseCollection(int $tenantId, DonationProject $project, string $familyId, float $amount): void
    {
        $project->raised_amount = max(0, round((float) $project->raised_amount - $amount, 2));
        $project->save();

        $assignment = ProjectFamilyAssignment::forTenant($tenantId)
            ->where('project_id', $project->id)
            ->where('family_id', $familyId)
            ->where('status', 'active')
            ->latest('effective_from')
            ->first();

        if ($assignment) {
            $assignment->amount_collected = max(0, round((float) $assignment->amount_collected - $amount, 2));
            $assignment->save();
        }
    }

    public function maybeMarkCompleted(DonationProject $project): void
    {
        if ($project->status !== 'active') {
            return;
        }

        $dashboard = $this->getDashboard($project->tenant_id, $project);
        $overallTarget = $dashboard['totals']['overall_target'];
        $collected = $dashboard['totals']['collected'];

        if ($overallTarget > 0 && $collected >= $overallTarget) {
            $project->status = 'completed';
            $project->completed_at = now();
            $project->save();
        }
    }

    private function resolveFamilyCollected(int $tenantId, string $projectId, string $familyId): float
    {
        $assignmentCollected = (float) ProjectFamilyAssignment::forTenant($tenantId)
            ->where('project_id', $projectId)
            ->where('family_id', $familyId)
            ->sum('amount_collected');

        if ($assignmentCollected > 0) {
            return $assignmentCollected;
        }

        return (float) ProjectInstallmentDue::forTenant($tenantId)
            ->where('project_id', $projectId)
            ->where('family_id', $familyId)
            ->sum('amount_paid');
    }

    private function findActiveAssignment(DonationProject $project, string $familyId, string $asOfDate): ?ProjectFamilyAssignment
    {
        return ProjectFamilyAssignment::forTenant($project->tenant_id)
            ->where('project_id', $project->id)
            ->where('family_id', $familyId)
            ->where('status', 'active')
            ->whereDate('effective_from', '<=', $asOfDate)
            ->where(function ($query) use ($asOfDate): void {
                $query->whereNull('effective_to')
                    ->orWhereDate('effective_to', '>=', $asOfDate);
            })
            ->orderByDesc('effective_from')
            ->first();
    }
}
