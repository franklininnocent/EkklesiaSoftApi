<?php

namespace Modules\EcclesiasticalData\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\EcclesiasticalData\Models\EcclesiasticalOffice;
use Modules\EcclesiasticalData\Support\LeadershipOfficeCode;

class EcclesiasticalOfficesSeeder extends Seeder
{
    public function run(): void
    {
        $offices = [
            [
                'office_code' => LeadershipOfficeCode::Pope->value,
                'title' => 'Pope',
                'scope_type' => 'global',
                'adapter_key' => 'pope',
                'allows_concurrent' => false,
                'requires_platform_approval' => true,
                'sort_order' => 1,
            ],
            [
                'office_code' => LeadershipOfficeCode::DiocesanBishop->value,
                'title' => 'Diocesan Bishop',
                'scope_type' => 'diocese',
                'adapter_key' => 'bishop_appointment',
                'allows_concurrent' => false,
                'requires_platform_approval' => true,
                'sort_order' => 2,
            ],
            [
                'office_code' => LeadershipOfficeCode::AuxiliaryBishop->value,
                'title' => 'Auxiliary Bishop',
                'scope_type' => 'diocese',
                'adapter_key' => 'bishop_appointment',
                'allows_concurrent' => true,
                'requires_platform_approval' => true,
                'sort_order' => 3,
            ],
            [
                'office_code' => LeadershipOfficeCode::CoadjutorBishop->value,
                'title' => 'Coadjutor Bishop',
                'scope_type' => 'diocese',
                'adapter_key' => 'bishop_appointment',
                'allows_concurrent' => true,
                'requires_platform_approval' => true,
                'sort_order' => 4,
            ],
            [
                'office_code' => LeadershipOfficeCode::ParishPriest->value,
                'title' => 'Parish Priest',
                'scope_type' => 'parish',
                'adapter_key' => 'parish_leadership',
                'allows_concurrent' => false,
                'requires_platform_approval' => false,
                'sort_order' => 5,
            ],
            [
                'office_code' => LeadershipOfficeCode::AssociateParishPriest->value,
                'title' => 'Associate Parish Priest',
                'scope_type' => 'parish',
                'adapter_key' => 'parish_leadership',
                'allows_concurrent' => true,
                'requires_platform_approval' => false,
                'sort_order' => 6,
            ],
            [
                'office_code' => LeadershipOfficeCode::ParishAdministrator->value,
                'title' => 'Parish Administrator',
                'scope_type' => 'parish',
                'adapter_key' => 'parish_leadership',
                'allows_concurrent' => false,
                'requires_platform_approval' => false,
                'sort_order' => 7,
            ],
        ];

        foreach ($offices as $office) {
            EcclesiasticalOffice::query()->updateOrCreate(
                ['office_code' => $office['office_code']],
                array_merge($office, [
                    'id' => EcclesiasticalOffice::query()
                        ->where('office_code', $office['office_code'])
                        ->value('id') ?? (string) Str::uuid(),
                    'is_active' => true,
                ]),
            );
        }
    }
}
