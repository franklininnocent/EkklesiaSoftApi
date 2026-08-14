<?php

namespace Modules\Tenants\Export\Contributors;

use Modules\Sacraments\Models\Sacrament;
use Modules\Tenants\Contracts\ExportWriterFactory;
use Modules\Tenants\Contracts\TenantDataExportContributor;

class SacramentsDataExportContributor implements TenantDataExportContributor
{
    use WritesChunkedExportCsv;

    public function key(): string
    {
        return 'sacraments';
    }

    public function label(): string
    {
        return 'Sacraments';
    }

    public function defaultSelected(): bool
    {
        return true;
    }

    public function estimateCount(int $tenantId): int
    {
        return Sacrament::forTenant($tenantId)->count();
    }

    public function export(
        int $tenantId,
        ExportWriterFactory $writers,
        int $chunkSize,
        callable $onProgress
    ): array {
        return [
            'files' => [
                'data/sacraments.csv' => $this->writeChunkedQuery(
                    $writers,
                    'data/sacraments.csv',
                    [
                        'sacrament_id', 'family_id', 'family_name', 'bcc_id', 'bcc_name',
                        'sacrament_type_id', 'sacrament_type_name', 'sacrament_type_code',
                        'recipient_name', 'date_administered', 'place_administered',
                        'minister_name', 'minister_title', 'certificate_number',
                        'status', 'notes', 'created_at',
                    ],
                    Sacrament::forTenant($tenantId)->with([
                        'sacramentType:id,name,code',
                        'family:id,family_name',
                        'bcc:id,name',
                    ]),
                    $chunkSize,
                    $onProgress,
                    fn ($row) => [
                        $row->id,
                        $row->family_id,
                        $row->family?->family_name,
                        $row->bcc_id,
                        $row->bcc?->name,
                        $row->sacrament_type_id,
                        $row->sacramentType?->name,
                        $row->sacramentType?->code,
                        $row->recipient_name,
                        $row->date_administered,
                        $row->place_administered,
                        $row->minister_name,
                        $row->minister_title,
                        $row->certificate_number,
                        $row->status,
                        $row->notes,
                        $row->created_at,
                    ]
                ),
            ],
        ];
    }
}
