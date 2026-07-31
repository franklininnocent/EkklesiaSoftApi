<?php

namespace Modules\Donations\Services;

use Modules\Donations\Models\DonationAuditLog;
use Modules\Donations\Models\DonationPayment;
use Modules\Donations\Models\DonationProject;
use Modules\Donations\Models\ProjectInstallmentDue;
use Modules\Family\Models\Family;

class FinancialActivityTimelineService
{
    public function __construct(private readonly FamilyFinancialProfileService $profileService)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $tenantId, string $subjectType, string $subjectId, int $limit = 30): array
    {
        $events = match ($subjectType) {
            'family' => $this->familyTimeline($tenantId, $subjectId, $limit),
            'payment' => $this->paymentTimeline($tenantId, $subjectId, $limit),
            'project', 'campaign' => $this->projectTimeline($tenantId, $subjectId, $limit),
            default => [],
        };

        return [
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'count' => count($events),
            'events' => $events,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function familyTimeline(int $tenantId, string $familyId, int $limit): array
    {
        Family::query()
            ->where('tenant_id', $tenantId)
            ->where('id', $familyId)
            ->firstOrFail();

        $profile = $this->profileService->build($tenantId, $familyId);

        return array_slice($profile['financial_timeline'] ?? [], 0, $limit);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function paymentTimeline(int $tenantId, string $paymentId, int $limit): array
    {
        $payment = DonationPayment::forTenant($tenantId)
            ->with(['receipt', 'family:id,family_name,family_code', 'allocations'])
            ->findOrFail($paymentId);

        $events = [[
            'type' => 'payment',
            'id' => $payment->id,
            'date' => $payment->payment_date?->toDateString(),
            'title' => 'Payment recorded',
            'subtitle' => $payment->payer_name,
            'amount' => (float) $payment->amount,
            'reference' => $payment->payment_number,
            'status' => $payment->status,
        ]];

        if ($payment->receipt) {
            $events[] = [
                'type' => 'receipt',
                'id' => $payment->receipt->id,
                'date' => $payment->receipt->issued_on?->toDateString(),
                'title' => 'Receipt issued',
                'subtitle' => $payment->receipt->receipt_number,
                'amount' => (float) $payment->amount,
                'reference' => $payment->receipt->receipt_number,
                'status' => $payment->receipt->is_void ? 'void' : 'issued',
            ];
        }

        $auditEvents = DonationAuditLog::query()
            ->where('tenant_id', $tenantId)
            ->where('target_type', 'payment')
            ->where('target_id', $paymentId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (DonationAuditLog $log) => [
                'type' => 'audit',
                'id' => (string) $log->id,
                'date' => $log->created_at?->toDateString(),
                'title' => str_replace('.', ' ', ucfirst($log->event ?? 'Updated')),
                'subtitle' => $log->event,
                'amount' => null,
                'reference' => null,
                'status' => 'logged',
            ])
            ->all();

        return array_slice(array_merge($events, $auditEvents), 0, $limit);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function projectTimeline(int $tenantId, string $projectId, int $limit): array
    {
        $project = DonationProject::forTenant($tenantId)->findOrFail($projectId);

        $events = [[
            'type' => 'project',
            'id' => $project->id,
            'date' => $project->start_date?->toDateString() ?? $project->created_at?->toDateString(),
            'title' => $project->entity_kind === 'campaign' ? 'Campaign started' : 'Project started',
            'subtitle' => $project->name,
            'amount' => (float) $project->target_amount,
            'reference' => $project->code,
            'status' => $project->status,
        ]];

        $installmentIds = ProjectInstallmentDue::query()
            ->where('tenant_id', $tenantId)
            ->where('project_id', $projectId)
            ->pluck('id');

        $payments = collect();
        if ($installmentIds->isNotEmpty()) {
            $payments = DonationPayment::forTenant($tenantId)
                ->with('family:id,family_name')
                ->where('status', 'succeeded')
                ->whereHas('allocations', function ($builder) use ($installmentIds): void {
                    $builder->where('allocatable_type', 'project_installment')
                        ->whereIn('allocatable_id', $installmentIds);
                })
                ->orderByDesc('payment_date')
                ->limit($limit)
                ->get()
                ->map(fn (DonationPayment $payment) => [
                    'type' => 'payment',
                    'id' => $payment->id,
                    'date' => $payment->payment_date?->toDateString(),
                    'title' => 'Contribution received',
                    'subtitle' => $payment->family?->family_name ?? $payment->payer_name,
                    'amount' => (float) $payment->amount,
                    'reference' => $payment->payment_number,
                    'status' => $payment->status,
                ]);
        }

        $auditEvents = DonationAuditLog::query()
            ->where('tenant_id', $tenantId)
            ->where('target_type', 'project')
            ->where('target_id', $projectId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (DonationAuditLog $log) => [
                'type' => 'audit',
                'id' => (string) $log->id,
                'date' => $log->created_at?->toDateString(),
                'title' => str_replace('.', ' ', ucfirst($log->event ?? 'Updated')),
                'subtitle' => $log->event,
                'amount' => null,
                'reference' => null,
                'status' => 'logged',
            ])
            ->all();

        $merged = array_merge($events, $payments->all(), $auditEvents);
        usort($merged, fn (array $a, array $b): int => strcmp((string) ($b['date'] ?? ''), (string) ($a['date'] ?? '')));

        return array_slice($merged, 0, $limit);
    }
}
