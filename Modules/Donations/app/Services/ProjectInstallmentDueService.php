<?php

namespace Modules\Donations\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Donations\Exceptions\ScheduleGenerationBusyException;
use Modules\Donations\Models\DonationProject;
use Modules\Donations\Models\ProjectInstallmentDue;
use Modules\Donations\Support\ContributionBalance;
use Modules\Donations\Support\MoneyMath;

class ProjectInstallmentDueService
{
    /**
     * Marker kept on installments cancelled only because a later regeneration shortened the schedule.
     * Pastoral cancellations do not carry this marker and are never revived.
     */
    public const SUPERSEDED_MARKER = '[schedule_superseded]';

    public function __construct(
        private readonly DonationAuditService $auditService,
        private readonly DonationProjectService $projectService
    ) {}

    /**
     * Create missing installment schedules, or replace unpaid schedules after an explicit confirmation.
     *
     * Generate is create-only and idempotent: a family that already has any installment row is skipped,
     * so a second click cannot duplicate dues or silently rewrite amounts.
     *
     * Regenerate replaces pending, zero-paid rows for families with no recorded payment. A family with
     * any paid or partially paid installment is locked as a whole so receipt history and the remaining
     * schedule stay consistent. Waived and pastorally cancelled rows are never rewritten. Surplus unpaid
     * rows are cancelled and kept, not deleted.
     *
     * @param  array<int, string>|null  $familyIds
     * @return array<string, int|string>
     */
    public function generateForProject(
        int $tenantId,
        int $userId,
        DonationProject $project,
        ?array $familyIds = null,
        string $mode = 'generate',
        ?string $reason = null
    ): array {
        $mode = $mode === 'regenerate' ? 'regenerate' : 'generate';
        if ($mode === 'regenerate' && ($reason === null || mb_strlen(trim($reason)) < 10)) {
            throw new InvalidArgumentException('A reason of at least 10 characters is required before unpaid installments can be replaced.');
        }

        $lock = Cache::lock($this->generationLockKey($tenantId, $project->id), 120);
        if (! $lock->get()) {
            throw new ScheduleGenerationBusyException(
                'Installment generation is already running for this project. Wait for it to finish, then try again.'
            );
        }

        try {
            return DB::transaction(function () use ($tenantId, $userId, $project, $familyIds, $mode, $reason): array {
                $this->lockProjectRow($tenantId, $project->id);

                $asOfDate = $project->start_date instanceof \DateTimeInterface
                    ? $project->start_date->format('Y-m-d')
                    : now()->toDateString();
                $enrolled = $this->projectService->getEnrolledFamilyIds($project, $asOfDate);
                if ($familyIds !== null) {
                    $familyIds = array_values(array_intersect($familyIds, $enrolled));
                } else {
                    $familyIds = $enrolled;
                }

                $installmentCount = max(1, (int) $project->installment_count);
                $targets = $this->projectService->positiveFamilyTargets($project, $familyIds, $asOfDate);
                $summary = $this->emptyGenerationSummary(count($targets), $mode);

                if ($targets === []) {
                    $summary['outcome'] = 'empty';
                    $this->auditGeneration($tenantId, $project->id, $mode, $reason, $summary);

                    return $summary;
                }

                $dueDates = [];
                $existingByFamily = $this->loadExistingInstallments($tenantId, $project->id, array_keys($targets));
                $now = now()->toDateTimeString();
                $inserts = [];
                $batches = ['update' => [], 'restore' => [], 'cancel' => []];

                foreach ($targets as $familyId => $familyTarget) {
                    $rows = $existingByFamily[$familyId] ?? [];
                    $amounts = $mode === 'regenerate'
                        ? $this->regenerationAmounts($familyTarget, $installmentCount, $rows)
                        : $this->splitInstallmentAmounts($familyTarget, $installmentCount);

                    if ($this->familyScheduleIsLocked($rows)) {
                        $summary['families_locked']++;
                        $summary['skipped'] += count($rows);
                        $summary['skipped_locked'] += count($rows);

                        continue;
                    }

                    if ($mode === 'generate' && $rows !== []) {
                        $summary['unchanged'] += count($rows);

                        continue;
                    }

                    for ($installmentNumber = 1; $installmentNumber <= $installmentCount; $installmentNumber++) {
                        $dueDates[$installmentNumber] ??= $this->resolveInstallmentDueDate($project, $installmentNumber);
                        $label = "{$installmentNumber}/{$installmentCount}";
                        $dueDate = $dueDates[$installmentNumber];
                        $existing = $rows[$installmentNumber] ?? null;
                        $amount = $amounts[$installmentNumber]
                            ?? ($existing !== null ? MoneyMath::normalize($existing->amount_due ?? 0) : '0.00');

                        if ($existing === null) {
                            $inserts[] = $this->newInstallmentRow(
                                $tenantId,
                                $userId,
                                $project->id,
                                $familyId,
                                $installmentNumber,
                                $label,
                                $dueDate,
                                $amount,
                                $now
                            );
                            $summary['created']++;

                            continue;
                        }

                        if ($mode !== 'regenerate') {
                            $summary['unchanged']++;

                            continue;
                        }

                        $this->queueRegenerateExistingRow(
                            $existing,
                            $label,
                            $dueDate,
                            $amount,
                            $summary,
                            $batches
                        );
                    }

                    if ($mode === 'regenerate') {
                        $this->queueSurplusCancellations($rows, $installmentCount, $summary, $batches);
                    }
                }

                $this->flushScheduleWrites($tenantId, $project->id, $userId, $now, $batches, $summary);

                foreach (array_chunk($inserts, 500) as $chunk) {
                    DB::table('project_installment_dues')->insert($chunk);
                }

                $summary['installments'] = $summary['created'] + $summary['updated'] + $summary['reactivated'];
                $summary['outcome'] = $this->resolveGenerationOutcome($summary, $mode);
                $this->auditGeneration($tenantId, $project->id, $mode, $reason, $summary);

                return $summary;
            });
        } finally {
            $lock->release();
        }
    }

    /**
     * List-screen schedule state. Derived from dues; nothing is written.
     *
     * @param  array<int, string>  $projectIds
     * @param  array<string, int>  $enrolledByProject
     * @return array<string, array<string, int|string|bool>>
     */
    public function summarizeSchedules(int $tenantId, array $projectIds, array $enrolledByProject = []): array
    {
        $schedules = [];
        foreach ($projectIds as $projectId) {
            $schedules[$projectId] = $this->emptySchedule((int) ($enrolledByProject[$projectId] ?? 0));
        }

        if ($projectIds === []) {
            return $schedules;
        }

        $today = now()->toDateString();
        $counts = DB::table('project_installment_dues')
            ->where('tenant_id', $tenantId)
            ->whereIn('project_id', $projectIds)
            ->whereNull('deleted_at')
            ->groupBy('project_id')
            ->selectRaw(
                "project_id,
                SUM(CASE WHEN status != 'cancelled' THEN 1 ELSE 0 END) as active_count,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_count,
                SUM(CASE WHEN status = 'partially_paid' THEN 1 ELSE 0 END) as partial_count,
                SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid_count,
                SUM(CASE WHEN status = 'waived' THEN 1 ELSE 0 END) as waived_count,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_count,
                SUM(CASE WHEN status IN ('pending', 'partially_paid') AND due_date < ? THEN 1 ELSE 0 END) as overdue_count,
                COUNT(DISTINCT family_id) as families_with_rows,
                COUNT(DISTINCT CASE WHEN amount_paid > 0 OR status IN ('paid', 'partially_paid') THEN family_id END) as families_with_payments,
                COUNT(DISTINCT CASE WHEN status != 'cancelled' THEN family_id END) as families_with_schedule",
                [$today]
            )
            ->get()
            ->keyBy('project_id');

        $regenerable = DB::table('project_installment_dues as d')
            ->where('d.tenant_id', $tenantId)
            ->whereIn('d.project_id', $projectIds)
            ->whereNull('d.deleted_at')
            ->where('d.status', 'pending')
            ->where('d.amount_paid', 0)
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('project_installment_dues as p')
                    ->whereColumn('p.tenant_id', 'd.tenant_id')
                    ->whereColumn('p.project_id', 'd.project_id')
                    ->whereColumn('p.family_id', 'd.family_id')
                    ->whereNull('p.deleted_at')
                    ->where(function ($locked): void {
                        $locked->where('p.amount_paid', '>', 0)
                            ->orWhereIn('p.status', ['paid', 'partially_paid']);
                    });
            })
            ->groupBy('d.project_id')
            ->selectRaw('d.project_id, COUNT(DISTINCT d.family_id) as families_regenerable')
            ->get()
            ->keyBy('project_id');

        foreach ($projectIds as $projectId) {
            $row = $counts->get($projectId);
            $enrolled = (int) ($enrolledByProject[$projectId] ?? 0);
            $familiesWithRows = (int) ($row->families_with_rows ?? 0);
            $familiesWithPayments = (int) ($row->families_with_payments ?? 0);
            $activeCount = (int) ($row->active_count ?? 0);
            $familiesMissing = max(0, $enrolled - $familiesWithRows);
            $familiesRegenerable = (int) ($regenerable->get($projectId)->families_regenerable ?? 0);

            $state = 'not_generated';
            if ($familiesWithPayments > 0) {
                $state = 'payments_recorded';
            } elseif ($familiesWithRows > 0) {
                $state = 'generated';
            }

            $schedules[$projectId] = [
                'state' => $state,
                'active_count' => $activeCount,
                'pending_count' => (int) ($row->pending_count ?? 0),
                'partial_count' => (int) ($row->partial_count ?? 0),
                'paid_count' => (int) ($row->paid_count ?? 0),
                'waived_count' => (int) ($row->waived_count ?? 0),
                'cancelled_count' => (int) ($row->cancelled_count ?? 0),
                'overdue_count' => (int) ($row->overdue_count ?? 0),
                'families_with_schedule' => (int) ($row->families_with_schedule ?? 0),
                'families_with_payments' => $familiesWithPayments,
                'families_regenerable' => $familiesRegenerable,
                'families_missing' => $familiesMissing,
                'can_generate' => $familiesWithRows === 0 || $familiesMissing > 0,
                'can_regenerate' => $familiesRegenerable > 0,
            ];
        }

        return $schedules;
    }

    public function waiveDue(int $tenantId, int $userId, ProjectInstallmentDue $due, ?string $reason = null): ProjectInstallmentDue
    {
        $oldStatus = $due->status;
        $due->status = 'waived';
        $due->status_changed_at = now();
        $due->notes = trim(($due->notes ? $due->notes.' ' : '').($reason ?? 'Waived'));
        $due->updated_by = $userId;
        $due->save();

        $this->auditService->log(
            $tenantId,
            'project_installment.waived',
            'project_installment',
            $due->id,
            ['status' => $oldStatus],
            ['status' => 'waived']
        );

        return $due->fresh(['family', 'project']);
    }

    public function cancelDue(int $tenantId, int $userId, ProjectInstallmentDue $due, ?string $reason = null): ProjectInstallmentDue
    {
        $oldStatus = $due->status;
        $due->status = 'cancelled';
        $due->status_changed_at = now();
        $due->notes = trim(($due->notes ? $due->notes.' ' : '').($reason ?? 'Cancelled'));
        $due->updated_by = $userId;
        $due->save();

        $this->auditService->log(
            $tenantId,
            'project_installment.cancelled',
            'project_installment',
            $due->id,
            ['status' => $oldStatus],
            ['status' => 'cancelled']
        );

        return $due->fresh(['family', 'project']);
    }

    public function applyPayment(int $tenantId, int $userId, ProjectInstallmentDue $due, string|float|int $amount): void
    {
        ContributionBalance::applyPaid($due, $amount);
        $due->status = ContributionBalance::statusFromPaid($due);
        $due->updated_by = $userId;
        $due->save();

        $project = DonationProject::forTenant($tenantId)->find($due->project_id);
        if ($project) {
            $this->projectService->recordCollection($tenantId, $project, $due->family_id, $amount);
        }
    }

    public function reversePayment(int $tenantId, int $userId, ProjectInstallmentDue $due, string|float|int $amount): void
    {
        ContributionBalance::unwindPaid($due, $amount);
        $due->status = ContributionBalance::statusFromPaid($due);
        $due->updated_by = $userId;
        $due->save();

        $project = DonationProject::forTenant($tenantId)->find($due->project_id);
        if ($project) {
            $this->projectService->reverseCollection($tenantId, $project, $due->family_id, $amount);
        }
    }

    private function generationLockKey(int $tenantId, string $projectId): string
    {
        return "donations:project-installments:{$tenantId}:{$projectId}";
    }

    private function lockProjectRow(int $tenantId, string $projectId): void
    {
        $query = DonationProject::forTenant($tenantId)->whereKey($projectId);
        if (DB::getDriverName() === 'pgsql') {
            $query->lockForUpdate();
        }
        $query->first();
    }

    /**
     * @param  array<int, string>  $familyIds
     * @return array<string, array<int, object>>
     */
    private function loadExistingInstallments(int $tenantId, string $projectId, array $familyIds): array
    {
        if ($familyIds === []) {
            return [];
        }

        $query = DB::table('project_installment_dues')
            ->where('tenant_id', $tenantId)
            ->where('project_id', $projectId)
            ->whereIn('family_id', $familyIds);
        if (DB::getDriverName() === 'pgsql') {
            $query->lockForUpdate();
        }

        $grouped = [];
        foreach ($query->get([
            'id',
            'family_id',
            'installment_number',
            'installment_label',
            'due_date',
            'amount_due',
            'amount_paid',
            'status',
            'notes',
            'deleted_at',
        ]) as $row) {
            $grouped[$row->family_id][(int) $row->installment_number] = $row;
        }

        return $grouped;
    }

    /**
     * @param  array<int, object>  $rows
     */
    private function familyScheduleIsLocked(array $rows): bool
    {
        foreach ($rows as $row) {
            if (in_array($row->status, ['paid', 'partially_paid'], true) || MoneyMath::isPositive($row->amount_paid ?? 0)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function splitInstallmentAmounts(string|float|int $familyTarget, int $count): array
    {
        $total = MoneyMath::normalize($familyTarget);
        $count = max(1, $count);
        $base = bcdiv($total, (string) $count, 2);
        $amounts = [];
        $allocated = MoneyMath::normalize('0');

        for ($installmentNumber = 1; $installmentNumber < $count; $installmentNumber++) {
            $amounts[$installmentNumber] = $base;
            $allocated = MoneyMath::add($allocated, $base);
        }

        $amounts[$count] = MoneyMath::subtract($total, $allocated);

        return $amounts;
    }

    /**
     * Spread only the pledge that is still adjustable. Waived and pastorally cancelled
     * amounts stay forgiven and are not charged again on the other installments.
     *
     * @param  array<int, object>  $rows
     * @return array<int, string>
     */
    private function regenerationAmounts(string|float|int $familyTarget, int $count, array $rows): array
    {
        $immutable = MoneyMath::normalize('0');
        $openNumbers = [];

        for ($installmentNumber = 1; $installmentNumber <= $count; $installmentNumber++) {
            $existing = $rows[$installmentNumber] ?? null;
            if ($this->rowIsImmutableOnRegenerate($existing)) {
                $immutable = MoneyMath::add($immutable, MoneyMath::normalize($existing->amount_due ?? 0));

                continue;
            }
            $openNumbers[] = $installmentNumber;
        }

        if ($openNumbers === []) {
            return [];
        }

        $remainder = MoneyMath::subtract(MoneyMath::normalize($familyTarget), $immutable);
        if (MoneyMath::compare($remainder, '0') < 0) {
            $remainder = MoneyMath::normalize('0');
        }

        $split = $this->splitInstallmentAmounts($remainder, count($openNumbers));
        $amounts = [];
        foreach ($openNumbers as $index => $installmentNumber) {
            $amounts[$installmentNumber] = $split[$index + 1];
        }

        return $amounts;
    }

    private function rowIsImmutableOnRegenerate(?object $existing): bool
    {
        if ($existing === null) {
            return false;
        }

        if (in_array($existing->status, ['paid', 'partially_paid', 'waived'], true) || MoneyMath::isPositive($existing->amount_paid ?? 0)) {
            return true;
        }

        $superseded = is_string($existing->notes) && str_contains($existing->notes, self::SUPERSEDED_MARKER);

        return $existing->status === 'cancelled' && ! $superseded && $existing->deleted_at === null;
    }

    /**
     * @return array<string, mixed>
     */
    private function newInstallmentRow(
        int $tenantId,
        int $userId,
        string $projectId,
        string $familyId,
        int $installmentNumber,
        string $label,
        string $dueDate,
        string $amount,
        string $now
    ): array {
        return [
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'project_id' => $projectId,
            'family_id' => $familyId,
            'installment_number' => $installmentNumber,
            'installment_label' => $label,
            'due_date' => $dueDate,
            'amount_due' => $amount,
            'amount_paid' => '0.00',
            'status' => 'pending',
            'notes' => null,
            'created_by' => $userId,
            'updated_by' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
            'deleted_at' => null,
        ];
    }

    /**
     * Queue a guarded write. The database predicate still requires amount_paid = 0 and an
     * editable status, so a row that received a payment after it was read is left unchanged.
     *
     * @param  array<string, int|string>  $summary
     * @param  array{update: array<string, array<int, string>>, restore: array<string, array<int, string>>, cancel: array<string, array<int, string>>}  $batches
     */
    private function queueRegenerateExistingRow(
        object $existing,
        string $label,
        string $dueDate,
        string $amount,
        array &$summary,
        array &$batches
    ): void {
        if (in_array($existing->status, ['paid', 'partially_paid', 'waived'], true) || MoneyMath::isPositive($existing->amount_paid ?? 0)) {
            $summary['unchanged']++;

            return;
        }

        $superseded = is_string($existing->notes) && str_contains($existing->notes, self::SUPERSEDED_MARKER);
        $canRestore = $existing->deleted_at !== null
            || ($existing->status === 'cancelled' && $superseded);

        if ($existing->status === 'cancelled' && ! $superseded && $existing->deleted_at === null) {
            $summary['unchanged']++;

            return;
        }

        if (! $canRestore && $existing->status !== 'pending') {
            $summary['unchanged']++;

            return;
        }

        $sameAmount = MoneyMath::equals($existing->amount_due ?? 0, $amount);
        $sameDate = Carbon::parse($existing->due_date)->toDateString() === $dueDate;
        $sameLabel = (string) $existing->installment_label === $label;
        if (! $canRestore && $sameAmount && $sameDate && $sameLabel) {
            $summary['unchanged']++;

            return;
        }

        $group = $label."\x1e".$dueDate."\x1e".$amount;
        $batches[$canRestore ? 'restore' : 'update'][$group][] = (string) $existing->id;
    }

    /**
     * @param  array<int, object>  $rows
     * @param  array<string, int|string>  $summary
     * @param  array{update: array<string, array<int, string>>, restore: array<string, array<int, string>>, cancel: array<string, array<int, string>>}  $batches
     */
    private function queueSurplusCancellations(array $rows, int $installmentCount, array &$summary, array &$batches): void
    {
        foreach ($rows as $number => $row) {
            if ((int) $number <= $installmentCount) {
                continue;
            }
            if ($row->deleted_at !== null || $row->status !== 'pending' || MoneyMath::isPositive($row->amount_paid ?? 0)) {
                continue;
            }

            $note = trim((string) ($row->notes ?? ''));
            if (! str_contains($note, self::SUPERSEDED_MARKER)) {
                $note = trim($note.' '.self::SUPERSEDED_MARKER.' Unpaid installment cancelled because the project schedule was regenerated.');
            }

            $batches['cancel'][$note][] = (string) $row->id;
        }
    }

    /**
     * Apply queued schedule changes in chunks. Each statement repeats the payment guard.
     *
     * @param  array{update: array<string, array<int, string>>, restore: array<string, array<int, string>>, cancel: array<string, array<int, string>>}  $batches
     * @param  array<string, int|string>  $summary
     */
    private function flushScheduleWrites(int $tenantId, string $projectId, int $userId, string $now, array $batches, array &$summary): void
    {
        foreach ($batches['update'] as $group => $ids) {
            [$label, $dueDate, $amount] = explode("\x1e", $group, 3);
            $this->flushGuardedChunks($tenantId, $projectId, $ids, function ($query) use ($label, $dueDate, $amount, $userId, $now): int {
                return $query
                    ->where('status', 'pending')
                    ->whereNull('deleted_at')
                    ->update([
                        'installment_label' => $label,
                        'due_date' => $dueDate,
                        'amount_due' => $amount,
                        'updated_by' => $userId,
                        'updated_at' => $now,
                    ]);
            }, $summary, 'updated');
        }

        foreach ($batches['restore'] as $group => $ids) {
            [$label, $dueDate, $amount] = explode("\x1e", $group, 3);
            $this->flushGuardedChunks($tenantId, $projectId, $ids, function ($query) use ($label, $dueDate, $amount, $userId, $now): int {
                return $query
                    ->where(function ($editable): void {
                        $editable->where('status', 'pending')->orWhere('status', 'cancelled');
                    })
                    ->update([
                        'installment_label' => $label,
                        'due_date' => $dueDate,
                        'amount_due' => $amount,
                        'status' => 'pending',
                        'status_changed_at' => $now,
                        'notes' => null,
                        'deleted_at' => null,
                        'updated_by' => $userId,
                        'updated_at' => $now,
                    ]);
            }, $summary, 'reactivated');
        }

        foreach ($batches['cancel'] as $note => $ids) {
            $this->flushGuardedChunks($tenantId, $projectId, $ids, function ($query) use ($note, $userId, $now): int {
                return $query
                    ->where('status', 'pending')
                    ->whereNull('deleted_at')
                    ->update([
                        'status' => 'cancelled',
                        'status_changed_at' => $now,
                        'notes' => $note,
                        'updated_by' => $userId,
                        'updated_at' => $now,
                    ]);
            }, $summary, 'cancelled');
        }
    }

    /**
     * @param  array<int, string>  $ids
     * @param  callable(\Illuminate\Database\Query\Builder): int  $apply
     * @param  array<string, int|string>  $summary
     */
    private function flushGuardedChunks(int $tenantId, string $projectId, array $ids, callable $apply, array &$summary, string $counter): void
    {
        foreach (array_chunk($ids, 400) as $chunk) {
            $query = DB::table('project_installment_dues')
                ->where('tenant_id', $tenantId)
                ->where('project_id', $projectId)
                ->whereIn('id', $chunk)
                ->where('amount_paid', 0);

            $affected = $apply($query);
            $summary[$counter] += $affected;
            $summary['unchanged'] += count($chunk) - $affected;
        }
    }

    /**
     * @param  array<string, int|string>  $summary
     */
    private function resolveGenerationOutcome(array $summary, string $mode): string
    {
        $written = (int) $summary['created'] + (int) $summary['updated'] + (int) $summary['reactivated'] + (int) $summary['cancelled'];
        if ($mode === 'regenerate' && $written === 0 && (int) $summary['families_locked'] > 0 && (int) $summary['families_locked'] === (int) $summary['families']) {
            return 'blocked';
        }
        if ($written > 0) {
            return $mode === 'regenerate' ? 'regenerated' : 'generated';
        }
        if ((int) $summary['families'] > 0) {
            return $mode === 'regenerate' ? 'unchanged' : 'already_generated';
        }

        return 'empty';
    }

    /**
     * @return array<string, int|string>
     */
    private function emptyGenerationSummary(int $families, string $mode): array
    {
        return [
            'outcome' => 'empty',
            'mode' => $mode,
            'created' => 0,
            'updated' => 0,
            'cancelled' => 0,
            'reactivated' => 0,
            'unchanged' => 0,
            'skipped' => 0,
            'skipped_locked' => 0,
            'families' => $families,
            'families_locked' => 0,
            'installments' => 0,
        ];
    }

    /**
     * @param  array<string, int|string>  $summary
     */
    private function auditGeneration(int $tenantId, string $projectId, string $mode, ?string $reason, array $summary): void
    {
        $this->auditService->log(
            $tenantId,
            $mode === 'regenerate' ? 'project.installments_regenerated' : 'project.installments_generated',
            'project',
            $projectId,
            null,
            $summary,
            array_filter([
                'mode' => $mode,
                'reason' => $reason !== null ? trim($reason) : null,
            ])
        );
    }

    /**
     * @return array<string, int|string|bool>
     */
    private function emptySchedule(int $enrolled): array
    {
        return [
            'state' => 'not_generated',
            'active_count' => 0,
            'pending_count' => 0,
            'partial_count' => 0,
            'paid_count' => 0,
            'waived_count' => 0,
            'cancelled_count' => 0,
            'overdue_count' => 0,
            'families_with_schedule' => 0,
            'families_with_payments' => 0,
            'families_regenerable' => 0,
            'families_missing' => $enrolled,
            'can_generate' => true,
            'can_regenerate' => false,
        ];
    }

    private function resolveInstallmentDueDate(DonationProject $project, int $installmentNumber): string
    {
        $start = Carbon::parse($project->start_date ?? now());

        if (! $project->installment_frequency || $installmentNumber <= 1) {
            return $start->toDateString();
        }

        $frequency = $project->installment_frequency;
        if ($frequency === 'custom') {
            $days = max(1, (int) ($project->installment_interval_days ?? 30));

            return $start->copy()->addDays(($installmentNumber - 1) * $days)->toDateString();
        }

        return match ($frequency) {
            'weekly' => $start->copy()->addWeeks($installmentNumber - 1)->endOfWeek()->toDateString(),
            'monthly' => $start->copy()->addMonths($installmentNumber - 1)->endOfMonth()->toDateString(),
            'quarterly' => $start->copy()->addMonths(($installmentNumber - 1) * 3)->endOfMonth()->toDateString(),
            'half_yearly' => $start->copy()->addMonths(($installmentNumber - 1) * 6)->endOfMonth()->toDateString(),
            'yearly' => $start->copy()->addYears($installmentNumber - 1)->endOfYear()->toDateString(),
            default => $start->copy()->addMonths($installmentNumber - 1)->endOfMonth()->toDateString(),
        };
    }
}
