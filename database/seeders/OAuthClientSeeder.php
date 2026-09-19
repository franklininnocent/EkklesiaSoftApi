<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OAuthClientSeeder extends Seeder
{
    public function run(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('oauth_clients')) {
            $this->command?->warn('Skipping OAuth client seeding: oauth_clients table missing.');

            return;
        }

        $hasPasswordClient = DB::table('oauth_clients')
            ->where(function ($query) {
                $query->where('revoked', false)->orWhereNull('revoked');
            })
            ->get()
            ->contains(function ($client) {
                $grantTypes = json_decode($client->grant_types, true);

                return is_array($grantTypes) && in_array('password', $grantTypes, true);
            });

        if ($hasPasswordClient) {
            $updated = DB::table('oauth_clients')
                ->where('provider', 'users')
                ->update([
                    'provider' => 'module_users',
                    'updated_at' => now(),
                ]);

            if ($updated > 0) {
                $this->command?->info('Updated OAuth password client provider to module_users.');
            } else {
                $this->command?->info('OAuth password grant client already present.');
            }

            return;
        }

        DB::table('oauth_clients')->insert([
            'id' => (string) Str::uuid(),
            'owner_type' => null,
            'owner_id' => null,
            'name' => 'EkklesiaSoft Password Grant Client',
            'secret' => null,
            'provider' => 'module_users',
            'redirect_uris' => json_encode([]),
            'grant_types' => json_encode(['password']),
            'revoked' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->command?->info('OAuth password grant client created.');
    }
}
