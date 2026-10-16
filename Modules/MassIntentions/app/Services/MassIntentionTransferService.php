<?php

namespace Modules\MassIntentions\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Models\MassIntentionAssignment;
use Modules\MassIntentions\Models\MassIntentionOffering;
use Modules\MassIntentions\Models\MassIntentionFulfilment;
use Modules\MassIntentions\Models\MassIntentionOfferingReceipt;
use Modules\MassIntentions\Models\MassIntentionObligation;
use Modules\MassIntentions\Models\MassIntentionRequest;
use Modules\MassIntentions\Models\MassIntentionTransfer;
use Modules\MassIntentions\Support\MassIntentionStatus;
use Modules\Tenants\Models\Tenant;

class MassIntentionTransferService
{
    public function __construct(
        private readonly MassIntentionCanonService $canon,
        private readonly MassIntentionAuditService $audits,
    ) {
    }

    public function initiate(
        int $fromTenantId,
        User $actor,
        string $requestId,
        int $toTenantId,
        ?string $note = null
    ): MassIntentionTransfer {
        if ($fromTenantId === $toTenantId) {
            throw ValidationException::withMessages([
                'to_tenant_id' => 'Choose a different parish to receive this intention.',
            ]);
        }

        $request = MassIntentionRequest::query()
            ->where('tenant_id', $fromTenantId)
            ->where('id', $requestId)
            ->firstOrFail();

        if ($request->status !== MassIntentionStatus::ACCEPTED) {
            throw ValidationException::withMessages([
                'status' => 'Only accepted intentions can be transferred.',
            ]);
        }

        $this->canon->assertTransferAllowed($request);
        $this->assertSiblingParish($fromTenantId, $toTenantId);
        $this->assertTargetHasFeature($toTenantId);

        $pending = MassIntentionTransfer::query()
            ->where('request_id', $requestId)
            ->where('status', 'pending')
            ->exists();

        if ($pending) {
            throw ValidationException::withMessages([
                'transfer' => 'A transfer is already waiting for the other parish.',
            ]);
        }

        $transfer = MassIntentionTransfer::query()->create([
            'from_tenant_id' => $fromTenantId,
            'to_tenant_id' => $toTenantId,
            'request_id' => $requestId,
            'status' => 'pending',
            'note' => $note ? trim($note) : null,
            'initiated_by_user_id' => $actor->id,
        ]);

        $this->audits->record($fromTenantId, 'transfer.initiated', $actor, $requestId, null, [
            'transfer_id' => $transfer->id,
            'to_tenant_id' => $toTenantId,
        ]);

        return $transfer;
    }

    public function accept(int $toTenantId, User $actor, string $transferId): MassIntentionTransfer
    {
        return DB::transaction(function () use ($toTenantId, $actor, $transferId): MassIntentionTransfer {
            $transfer = MassIntentionTransfer::query()
                ->where('id', $transferId)
                ->where('to_tenant_id', $toTenantId)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->firstOrFail();

            $request = MassIntentionRequest::query()
                ->where('tenant_id', $transfer->from_tenant_id)
                ->where('id', $transfer->request_id)
                ->lockForUpdate()
                ->firstOrFail();

            $fromTenantId = (int) $transfer->from_tenant_id;
            $requestId = $request->id;

            $request->tenant_id = $toTenantId;
            $request->save();

            $obligationIds = MassIntentionObligation::query()
                ->where('tenant_id', $fromTenantId)
                ->where('request_id', $requestId)
                ->pluck('id');

            MassIntentionObligation::query()
                ->whereIn('id', $obligationIds)
                ->update(['tenant_id' => $toTenantId]);

            if ($obligationIds->isNotEmpty()) {
                MassIntentionAssignment::query()
                    ->where('tenant_id', $fromTenantId)
                    ->whereIn('obligation_id', $obligationIds)
                    ->update(['tenant_id' => $toTenantId]);

                MassIntentionFulfilment::query()
                    ->where('tenant_id', $fromTenantId)
                    ->whereIn('obligation_id', $obligationIds)
                    ->update(['tenant_id' => $toTenantId]);
            }

            $offering = MassIntentionOffering::query()
                ->where('tenant_id', $fromTenantId)
                ->where('request_id', $requestId)
                ->first();

            if ($offering) {
                MassIntentionOfferingReceipt::query()
                    ->where('tenant_id', $fromTenantId)
                    ->where('offering_id', $offering->id)
                    ->update(['tenant_id' => $toTenantId]);
                $offering->tenant_id = $toTenantId;
                $offering->save();
            }

            $transfer->status = 'accepted';
            $transfer->responded_by_user_id = $actor->id;
            $transfer->responded_at = now();
            $transfer->save();

            $this->audits->record($fromTenantId, 'transfer.accepted', $actor, $requestId, null, [
                'transfer_id' => $transfer->id,
                'from_tenant_id' => $fromTenantId,
                'to_tenant_id' => $toTenantId,
            ]);
            $this->audits->record($toTenantId, 'transfer.received', $actor, $requestId, null, [
                'transfer_id' => $transfer->id,
                'from_tenant_id' => $fromTenantId,
            ]);

            return $transfer->fresh();
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPendingForTenant(int $tenantId): array
    {
        $rows = MassIntentionTransfer::query()
            ->where('to_tenant_id', $tenantId)
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $fromNames = Tenant::query()
            ->whereIn('id', $rows->pluck('from_tenant_id')->unique())
            ->pluck('name', 'id');

        $requests = MassIntentionRequest::query()
            ->whereIn('id', $rows->pluck('request_id'))
            ->get()
            ->keyBy('id');

        return $rows
            ->map(fn (MassIntentionTransfer $t) => [
                'id' => $t->id,
                'request_id' => $t->request_id,
                'from_tenant_id' => $t->from_tenant_id,
                'from_tenant_name' => $fromNames[$t->from_tenant_id] ?? null,
                'beneficiary_name' => $requests[$t->request_id]?->beneficiary_name,
                'intention_text' => $requests[$t->request_id]?->intention_text,
                'note' => $t->note,
                'created_at' => $t->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * @return list<array{tenant_id: int, name: string}>
     */
    public function listTransferTargets(int $tenantId): array
    {
        $tenant = Tenant::query()->find($tenantId);
        if (! $tenant || ! $tenant->parent_tenant_id) {
            return [];
        }

        return Tenant::query()
            ->where('parent_tenant_id', $tenant->parent_tenant_id)
            ->where('id', '!=', $tenantId)
            ->orderBy('name')
            ->get()
            ->filter(fn (Tenant $peer) => in_array('mass_intentions', $peer->features ?? [], true))
            ->map(fn (Tenant $peer) => [
                'tenant_id' => (int) $peer->id,
                'name' => (string) $peer->name,
            ])
            ->values()
            ->all();
    }

    public function countPendingIncoming(int $tenantId): int
    {
        return MassIntentionTransfer::query()
            ->where('to_tenant_id', $tenantId)
            ->where('status', 'pending')
            ->count();
    }

    public function reject(int $toTenantId, User $actor, string $transferId, ?string $note = null): MassIntentionTransfer
    {
        $transfer = MassIntentionTransfer::query()
            ->where('id', $transferId)
            ->where('to_tenant_id', $toTenantId)
            ->where('status', 'pending')
            ->firstOrFail();

        $transfer->status = 'rejected';
        $transfer->responded_by_user_id = $actor->id;
        $transfer->responded_at = now();
        if ($note !== null && trim($note) !== '') {
            $transfer->note = trim($note);
        }
        $transfer->save();

        $this->audits->record($toTenantId, 'transfer.rejected', $actor, $transfer->request_id, null, [
            'transfer_id' => $transfer->id,
            'from_tenant_id' => $transfer->from_tenant_id,
        ]);
        $this->audits->record((int) $transfer->from_tenant_id, 'transfer.declined', $actor, $transfer->request_id, null, [
            'transfer_id' => $transfer->id,
            'to_tenant_id' => $toTenantId,
        ]);

        return $transfer->fresh();
    }

    private function assertSiblingParish(int $fromTenantId, int $toTenantId): void
    {
        $from = Tenant::query()->find($fromTenantId);
        $to = Tenant::query()->find($toTenantId);

        if (! $from || ! $to || ! $from->parent_tenant_id || $from->parent_tenant_id !== $to->parent_tenant_id) {
            throw ValidationException::withMessages([
                'to_tenant_id' => 'Transfers are only allowed between parishes in the same diocese.',
            ]);
        }
    }

    private function assertTargetHasFeature(int $toTenantId): void
    {
        $tenant = Tenant::query()->find($toTenantId);
        $features = $tenant?->features ?? [];
        if (! in_array('mass_intentions', $features, true)) {
            throw ValidationException::withMessages([
                'to_tenant_id' => 'The receiving parish does not have Mass intentions enabled.',
            ]);
        }
    }
}
