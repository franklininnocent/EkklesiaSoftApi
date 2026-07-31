<?php

namespace Modules\Tenants\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Tenants\Models\SubscriptionDurationOption;
use Illuminate\Support\Facades\DB;

class SubscriptionDurationOptionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $options = [
            ['months' => 1, 'label' => '1 Month', 'display_order' => 1, 'active' => 1],
            ['months' => 3, 'label' => '3 Months', 'display_order' => 2, 'active' => 1],
            ['months' => 6, 'label' => '6 Months', 'display_order' => 3, 'active' => 1],
            ['months' => 12, 'label' => '1 Year', 'display_order' => 4, 'active' => 1],
            ['months' => 36, 'label' => '3 Years', 'display_order' => 5, 'active' => 1],
            ['months' => 60, 'label' => '5 Years', 'display_order' => 6, 'active' => 1],
        ];

        foreach ($options as $option) {
            SubscriptionDurationOption::updateOrCreate(
                ['months' => $option['months']],
                $option
            );
        }
    }
}

