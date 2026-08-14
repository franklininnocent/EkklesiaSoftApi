<?php

namespace Modules\Tenants\Export\Contributors;

use Modules\BCC\Models\BCC;
use Modules\BCC\Models\BCCLeader;
use Modules\Tenants\Contracts\ExportWriterFactory;
use Modules\Tenants\Contracts\TenantDataExportContributor;

class BccDataExportContributor implements TenantDataExportContributor
{
    use WritesChunkedExportCsv;

    public function key(): string
    {
        return 'bcc';
    }

    public function label(): string
    {
        return 'BCCs & leaders';
    }

    public function defaultSelected(): bool
    {
        return true;
    }

    public function estimateCount(int $tenantId): int
    {
        $bccs = BCC::query()->where('tenant_id', $tenantId)->count();
        $leaders = BCCLeader::query()
            ->whereHas('bcc', fn ($q) => $q->where('tenant_id', $tenantId))
            ->count();

        return $bccs + $leaders;
    }

    public function export(
        int $tenantId,
        ExportWriterFactory $writers,
        int $chunkSize,
        callable $onProgress
    ): array {
        return [
            'files' => [
                'data/bccs.csv' => $this->exportBccs($tenantId, $writers, $chunkSize, $onProgress),
                'data/bcc_leaders.csv' => $this->exportLeaders($tenantId, $writers, $chunkSize, $onProgress),
            ],
        ];
    }

    private function exportBccs(int $tenantId, ExportWriterFactory $writers, int $chunkSize, callable $onProgress): int
    {
        return $this->writeChunkedQuery(
            $writers,
            'data/bccs.csv',
            [
                'bcc_id', 'bcc_code', 'name', 'description', 'location', 'meeting_place',
                'meeting_day', 'meeting_time', 'meeting_frequency', 'status', 'established_date',
                'notes', 'created_at',
            ],
            BCC::query()->where('tenant_id', $tenantId),
            $chunkSize,
            $onProgress,
            fn ($bcc) => [
                $bcc->id,
                $bcc->bcc_code,
                $bcc->name,
                $bcc->description,
                $bcc->location,
                $bcc->meeting_place,
                $bcc->meeting_day,
                $bcc->meeting_time,
                $bcc->meeting_frequency,
                $bcc->status,
                $bcc->established_date,
                $bcc->notes,
                $bcc->created_at,
            ]
        );
    }

    private function exportLeaders(int $tenantId, ExportWriterFactory $writers, int $chunkSize, callable $onProgress): int
    {
        return $this->writeChunkedQuery(
            $writers,
            'data/bcc_leaders.csv',
            [
                'leader_id', 'bcc_id', 'bcc_name', 'family_member_id', 'member_name',
                'role', 'role_description', 'appointed_date', 'term_start_date', 'term_end_date',
                'is_active', 'leader_phone', 'leader_email', 'created_at',
            ],
            BCCLeader::query()
                ->whereHas('bcc', fn ($q) => $q->where('tenant_id', $tenantId))
                ->with(['bcc:id,name', 'member:id,first_name,last_name']),
            $chunkSize,
            $onProgress,
            function ($leader) {
                $memberName = trim(($leader->member?->first_name ?? '').' '.($leader->member?->last_name ?? ''));

                return [
                    $leader->id,
                    $leader->bcc_id,
                    $leader->bcc?->name,
                    $leader->family_member_id,
                    $memberName !== '' ? $memberName : null,
                    $leader->role,
                    $leader->role_description,
                    $leader->appointed_date,
                    $leader->term_start_date,
                    $leader->term_end_date,
                    $this->boolLabel($leader->is_active),
                    $leader->leader_phone,
                    $leader->leader_email,
                    $leader->created_at,
                ];
            }
        );
    }
}
