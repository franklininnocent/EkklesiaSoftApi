<?php

namespace Modules\Donations\Services;

use Modules\Donations\Models\ContributionDue;
use Modules\Donations\Models\DonationProject;
use Modules\Donations\Models\ProjectInstallmentDue;
use Modules\Donations\Support\ContributionBalance;
use Modules\Donations\Support\DonationBusinessDate;
use Modules\Donations\Support\MoneyMath;
use Modules\Family\Models\Family;
use Modules\Tenants\Services\ChurchCurrencyResolver;

class CollectPaymentContextService
{
    /**
     * Authoritative collect-payment prefill. Never trust table-cell amounts.
     *
     * @param  array{family_id?: string, project_id?: string, campaign_id?: string, due_id?: string, installment_id?: string}  $query
     * @return array<string, mixed>
     */
    public function resolve(int $tenantId, array $query): array
    {
        $familyId = $this->nullableId($query['family_id'] ?? null);
        $dueId = $this->nullableId($query['due_id'] ?? null);
        $installmentId = $this->nullableId($query['installment_id'] ?? null);
        $projectId = $this->nullableId($query['project_id'] ?? null)
            ?? $this->nullableId($query['campaign_id'] ?? null);

        $family = $familyId ? $this->requireFamily($tenantId, $familyId) : null;
        $project = $projectId ? $this->requireProject($tenantId, $projectId) : null;

        $suggested = null;
        $message = null;
        $canCollect = true;

        if ($dueId) {
            [$suggested, $message, $canCollect, $family] = $this->resolveDue($tenantId, $dueId, $family);
        } elseif ($installmentId) {
            [$suggested, $message, $canCollect, $family, $project] = $this->resolveInstallment(
                $tenantId,
                $installmentId,
                $family,
                $project
            );
        } elseif ($project && $family) {
            [$suggested, $message, $canCollect] = $this->resolveFamilyProject($tenantId, $family, $project);
        } elseif ($family) {
            [$suggested, $message, $canCollect] = $this->resolveFamilyDefault($tenantId, $family);
        }

        $collectible = $suggested['collectible_amount'] ?? '0.00';

        return [
            'family' => $family ? $this->mapFamily($family) : null,
            'project' => $project ? $this->mapProject($project) : null,
            'suggested_allocation' => $suggested,
            'collectible_amount' => (float) $collectible,
            'payment_date' => DonationBusinessDate::today($tenantId),
            'currency_code' => app(ChurchCurrencyResolver::class)->currencyCodeForTenantId($tenantId),
            'can_collect' => $canCollect,
            'overpayment_becomes_credit' => true,
            'message' => $message,
        ];
    }

    /**
     * @return array{0: ?array<string, mixed>, 1: ?string, 2: bool, 3: Family}
     */
    private function resolveDue(int $tenantId, string $dueId, ?Family $family): array
    {
        $due = ContributionDue::forTenant($tenantId)->with('plan:id,name,code')->find($dueId);
        if (! $due) {
            throw new \RuntimeException('Contribution due was not found for this church.');
        }

        $dueFamily = $this->requireFamily($tenantId, (string) $due->family_id);
        if ($family && $family->id !== $dueFamily->id) {
            throw new \RuntimeException('This contribution does not belong to the selected family.');
        }

        if (in_array($due->status, ['waived', 'cancelled'], true)) {
            return [null, 'This contribution cannot be collected because it is '.$due->status.'.', false, $dueFamily];
        }

        $outstanding = ContributionBalance::outstandingString($due);
        if (! MoneyMath::isPositive($outstanding)) {
            return [null, 'This contribution has no remaining balance to collect.', false, $dueFamily];
        }

        $label = $due->plan?->name ?: ($due->period_label ?: 'Mandatory due');

        return [
            $this->allocation('due', (string) $due->id, $label, $outstanding, $due->amount_due, $due->amount_paid, $due->status),
            null,
            true,
            $dueFamily,
        ];
    }

    /**
     * @return array{0: ?array<string, mixed>, 1: ?string, 2: bool, 3: Family, 4: ?DonationProject}
     */
    private function resolveInstallment(
        int $tenantId,
        string $installmentId,
        ?Family $family,
        ?DonationProject $project
    ): array {
        $installment = ProjectInstallmentDue::forTenant($tenantId)->with('project:id,name,code,fund_id')->find($installmentId);
        if (! $installment) {
            throw new \RuntimeException('Project installment was not found for this church.');
        }

        $installmentFamily = $this->requireFamily($tenantId, (string) $installment->family_id);
        if ($family && $family->id !== $installmentFamily->id) {
            throw new \RuntimeException('This installment does not belong to the selected family.');
        }

        $installmentProject = $installment->project
            ?? DonationProject::forTenant($tenantId)->find($installment->project_id);
        if ($project && $installmentProject && $project->id !== $installmentProject->id) {
            throw new \RuntimeException('This installment does not belong to the selected project.');
        }

        if (in_array($installment->status, ['waived', 'cancelled'], true)) {
            return [null, 'This installment cannot be collected because it is '.$installment->status.'.', false, $installmentFamily, $installmentProject];
        }

        $outstanding = ContributionBalance::outstandingString($installment);
        if (! MoneyMath::isPositive($outstanding)) {
            return [null, 'This installment has no remaining balance to collect.', false, $installmentFamily, $installmentProject];
        }

        $label = trim(($installmentProject?->name ?: 'Project').' · '.($installment->installment_label ?: 'Installment'));

        return [
            $this->allocation(
                'project_installment',
                (string) $installment->id,
                $label,
                $outstanding,
                $installment->amount_due,
                $installment->amount_paid,
                $installment->status
            ),
            null,
            true,
            $installmentFamily,
            $installmentProject,
        ];
    }

    /**
     * @return array{0: ?array<string, mixed>, 1: ?string, 2: bool}
     */
    private function resolveFamilyProject(int $tenantId, Family $family, DonationProject $project): array
    {
        $installment = ProjectInstallmentDue::forTenant($tenantId)
            ->where('family_id', $family->id)
            ->where('project_id', $project->id)
            ->whereIn('status', ['pending', 'partially_paid'])
            ->orderBy('due_date')
            ->orderBy('installment_number')
            ->get()
            ->first(fn (ProjectInstallmentDue $row) => MoneyMath::isPositive(ContributionBalance::outstandingString($row)));

        if ($installment) {
            $outstanding = ContributionBalance::outstandingString($installment);
            $label = trim($project->name.' · '.($installment->installment_label ?: 'Installment'));

            return [
                $this->allocation(
                    'project_installment',
                    (string) $installment->id,
                    $label,
                    $outstanding,
                    $installment->amount_due,
                    $installment->amount_paid,
                    $installment->status
                ),
                null,
                true,
            ];
        }

        $summary = app(DonationProjectService::class)->getFamilyProjectSummary($tenantId, $family->id);
        $row = collect($summary['projects'] ?? [])->firstWhere('project_id', $project->id);
        $outstanding = MoneyMath::normalize($row['outstanding_amount'] ?? 0);
        if (! MoneyMath::isPositive($outstanding)) {
            return [null, 'This family has no remaining balance on this project.', false];
        }

        return [
            $this->allocation('project', (string) $project->id, $project->name, $outstanding, $row['target_amount'] ?? null, $row['amount_collected'] ?? null, $project->status),
            null,
            true,
        ];
    }

    /**
     * @return array{0: ?array<string, mixed>, 1: ?string, 2: bool}
     */
    private function resolveFamilyDefault(int $tenantId, Family $family): array
    {
        $asOfDate = DonationBusinessDate::today($tenantId);
        $due = ContributionDue::forTenant($tenantId)
            ->where('family_id', $family->id)
            ->with('plan:id,name,code')
            ->orderBy('due_date')
            ->get()
            ->first(fn (ContributionDue $row) => ContributionBalance::isCollectable($row, $asOfDate));

        if ($due) {
            $outstanding = ContributionBalance::outstandingString($due);
            $label = $due->plan?->name ?: ($due->period_label ?: 'Mandatory due');

            return [
                $this->allocation('due', (string) $due->id, $label, $outstanding, $due->amount_due, $due->amount_paid, $due->status),
                null,
                true,
            ];
        }

        $installment = ProjectInstallmentDue::forTenant($tenantId)
            ->where('family_id', $family->id)
            ->whereIn('status', ['pending', 'partially_paid'])
            ->with('project:id,name,code')
            ->orderBy('due_date')
            ->get()
            ->first(fn (ProjectInstallmentDue $row) => MoneyMath::isPositive(ContributionBalance::outstandingString($row)));

        if ($installment) {
            $outstanding = ContributionBalance::outstandingString($installment);
            $label = trim(($installment->project?->name ?: 'Project').' · '.($installment->installment_label ?: 'Installment'));

            return [
                $this->allocation(
                    'project_installment',
                    (string) $installment->id,
                    $label,
                    $outstanding,
                    $installment->amount_due,
                    $installment->amount_paid,
                    $installment->status
                ),
                null,
                true,
            ];
        }

        return [null, null, true];
    }

    private function requireFamily(int $tenantId, string $familyId): Family
    {
        $family = Family::query()->where('id', $familyId)->where('tenant_id', $tenantId)->first();
        if (! $family) {
            throw new \RuntimeException('Family does not belong to the tenant.');
        }

        return $family;
    }

    private function requireProject(int $tenantId, string $projectId): DonationProject
    {
        $project = DonationProject::forTenant($tenantId)->find($projectId);
        if (! $project) {
            throw new \RuntimeException('Project was not found for this church.');
        }

        return $project;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapFamily(Family $family): array
    {
        return [
            'id' => $family->id,
            'family_name' => $family->family_name,
            'family_code' => $family->family_code,
            'head_of_family' => $family->head_of_family,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapProject(DonationProject $project): array
    {
        return [
            'id' => $project->id,
            'name' => $project->name,
            'code' => $project->code,
            'fund_id' => $project->fund_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function allocation(
        string $type,
        string $id,
        string $label,
        string $collectible,
        mixed $amountDue,
        mixed $amountPaid,
        mixed $status
    ): array {
        return [
            'allocatable_type' => $type,
            'allocatable_id' => $id,
            'label' => $label,
            'collectible_amount' => (float) $collectible,
            'amount_due' => $amountDue !== null ? (float) $amountDue : null,
            'amount_paid' => $amountPaid !== null ? (float) $amountPaid : null,
            'status' => $status,
        ];
    }

    private function nullableId(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return $value;
    }
}
