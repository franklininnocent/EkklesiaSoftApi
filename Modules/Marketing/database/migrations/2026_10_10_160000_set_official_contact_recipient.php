<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('web_settings')) {
            return;
        }

        DB::table('web_settings')
            ->where('contact_recipient_email', 'franklininnocent.fs@gmail.com')
            ->update([
                'contact_recipient_email' => 'contact@ekklesianexus.com',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // Official contact address stays in place. Do not restore a personal mailbox.
    }
};
