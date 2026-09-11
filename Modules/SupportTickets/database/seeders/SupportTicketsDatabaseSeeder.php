<?php

namespace Modules\SupportTickets\Database\Seeders;

use Illuminate\Database\Seeder;

class SupportTicketsDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SupportTicketsLookupSeeder::class,
            SupportTicketsPermissionSeeder::class,
        ]);
    }
}
