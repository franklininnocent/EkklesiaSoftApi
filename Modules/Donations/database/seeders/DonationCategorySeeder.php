<?php

namespace Modules\Donations\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Donations\Models\DonationCategory;

class DonationCategorySeeder extends Seeder
{
    /**
     * Seed default voluntary donation categories for a tenant.
     */
    public function run(?int $tenantId = null, ?int $userId = null): void
    {
        if (!$tenantId) {
            return;
        }

        $defaults = [
            ['name' => 'General Church Donation', 'code' => 'GENERAL', 'description' => 'General voluntary offering', 'is_tax_deductible' => true],
            ['name' => 'Thanksgiving Offering', 'code' => 'THANKSGIVING', 'description' => 'Seasonal thanksgiving offering', 'is_tax_deductible' => true],
            ['name' => 'Memorial Donation', 'code' => 'MEMORIAL', 'description' => 'Donation in memory of a loved one', 'is_tax_deductible' => false],
            ['name' => 'Feast Contribution', 'code' => 'FEAST', 'description' => 'Parish feast or celebration contribution', 'is_tax_deductible' => false],
            ['name' => 'Charity Donation', 'code' => 'CHARITY', 'description' => 'Charitable outreach donation', 'is_tax_deductible' => true],
            ['name' => 'Building Fund Donation', 'code' => 'BUILDING', 'description' => 'Building or renovation fund', 'is_tax_deductible' => true],
            ['name' => 'Anonymous Donation', 'code' => 'ANONYMOUS', 'description' => 'Anonymous voluntary gift', 'is_tax_deductible' => false],
        ];

        foreach ($defaults as $category) {
            DonationCategory::updateOrCreate(
                ['tenant_id' => $tenantId, 'code' => $category['code']],
                [
                    'name' => $category['name'],
                    'description' => $category['description'],
                    'is_tax_deductible' => $category['is_tax_deductible'],
                    'active' => true,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]
            );
        }
    }
}
