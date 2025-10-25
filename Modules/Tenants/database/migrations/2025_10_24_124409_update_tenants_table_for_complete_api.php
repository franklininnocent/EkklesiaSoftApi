<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Add new columns for primary user (if they don't exist) - initially nullable
            if (!Schema::hasColumn('tenants', 'primary_user_name')) {
                $table->string('primary_user_name')->nullable()->after('domain')->comment('Primary contact name');
                $table->string('primary_user_email')->nullable()->after('primary_user_name')->comment('Primary contact email');
                $table->string('primary_contact_number')->nullable()->after('primary_user_email')->comment('Primary contact phone');
            }
            
            // Add secondary user columns
            if (!Schema::hasColumn('tenants', 'secondary_user_name')) {
                $table->string('secondary_user_name')->nullable()->after('primary_contact_number')->comment('Secondary contact name');
                $table->string('secondary_user_email')->nullable()->after('secondary_user_name')->comment('Secondary contact email');
                $table->string('secondary_contact_number')->nullable()->after('secondary_user_email')->comment('Secondary contact phone');
            }
            
            // Add address columns as JSON
            if (!Schema::hasColumn('tenants', 'official_address')) {
                $table->json('official_address')->nullable()->after('secondary_contact_number')->comment('Primary address details');
                $table->json('official_address2')->nullable()->after('official_address')->comment('Secondary address details');
            }
        });
        
        // Migrate existing data
        $tenants = DB::table('tenants')->get();
        foreach ($tenants as $tenant) {
            $officialAddress = [
                'line1' => $tenant->address ?? '',
                'line2' => '',
                'district' => $tenant->city ?? '',
                'state_province' => $tenant->state ?? '',
                'country' => $tenant->country ?? 'USA',
                'pin_zip_code' => $tenant->postal_code ?? '',
            ];
            
            DB::table('tenants')->where('id', $tenant->id)->update([
                'primary_user_name' => $tenant->name ?? 'Admin',
                'primary_user_email' => $tenant->email ?? 'admin@' . ($tenant->slug ?? 'example.com'),
                'primary_contact_number' => $tenant->phone ?? '0000000000',
                'official_address' => json_encode($officialAddress),
            ]);
        }
        
        // Now drop old columns
        Schema::table('tenants', function (Blueprint $table) {
            if (Schema::hasColumn('tenants', 'email')) {
                $table->dropColumn(['email', 'phone', 'address', 'city', 'state', 'country', 'postal_code']);
            }
        });
        
        // Add index for primary user email if it doesn't exist
        if (!DB::select("SELECT 1 FROM pg_indexes WHERE indexname = 'tenants_primary_user_email_index'")) {
            Schema::table('tenants', function (Blueprint $table) {
                $table->index('primary_user_email');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Drop new columns
            $table->dropColumn([
                'primary_user_name',
                'primary_user_email',
                'primary_contact_number',
                'secondary_user_name',
                'secondary_user_email',
                'secondary_contact_number',
                'official_address',
                'official_address2',
            ]);
            
            // Drop index
            $table->dropIndex(['primary_user_email']);
            
            // Restore old columns
            $table->string('email')->nullable()->comment('Primary contact email');
            $table->string('phone')->nullable()->comment('Primary contact phone');
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable()->default('USA');
            $table->string('postal_code')->nullable();
        });
    }
};
