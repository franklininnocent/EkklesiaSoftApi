<?php

namespace Modules\Tenants\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Tenants\Models\PopeAssignment;
use Modules\Tenants\Models\PopeDetails;
use Modules\Tenants\Services\PopeLeadershipService;

class PopeDetailsSeeder extends Seeder
{
    private const POPE_NAME = 'Pope Leo XIV';

    private const POPE_TITLE = 'His Holiness';

    private const EFFECTIVE_FROM = '2025-05-08';

    private const APPOINTMENT_REFERENCE = 'papal_conclave_2025_05_08';

    public function run(): void
    {
        if (! Schema::hasTable('pope_details') || ! Schema::hasTable('pope_assignments')) {
            $this->command?->warn('Skipping PopeDetailsSeeder: pope tables missing.');

            return;
        }

        $payload = [
            'pope_name' => self::POPE_NAME,
            'pope_title' => self::POPE_TITLE,
            'pope_effective_from' => self::EFFECTIVE_FROM,
            'appointment_reference' => self::APPOINTMENT_REFERENCE,
        ];

        $actorId = $this->resolveSuperAdminActorId();

        if ($actorId !== null) {
            app(PopeLeadershipService::class)->succeed($payload, $actorId);
            $this->command?->info('Pope details seeded: '.self::POPE_NAME.' (effective '.self::EFFECTIVE_FROM.')');

            return;
        }

        $this->seedWithoutActor($payload);
        $this->command?->info('Pope details seeded: '.self::POPE_NAME.' (effective '.self::EFFECTIVE_FROM.')');
    }

    private function resolveSuperAdminActorId(): ?int
    {
        $superAdminRoleId = Role::query()
            ->where('name', Role::SUPER_ADMIN)
            ->whereNull('tenant_id')
            ->value('id');

        if (! $superAdminRoleId) {
            return null;
        }

        $actorId = User::query()
            ->where('role_id', $superAdminRoleId)
            ->value('id');

        return $actorId ? (int) $actorId : null;
    }

    /**
     * @param  array{pope_name: string, pope_title: string, pope_effective_from: string, appointment_reference: string}  $payload
     */
    private function seedWithoutActor(array $payload): void
    {
        $current = PopeAssignment::query()
            ->active()
            ->orderByDesc('start_date')
            ->first();

        if ($current
            && $current->pope_name === $payload['pope_name']
            && ($current->pope_title ?? null) === $payload['pope_title']
            && $current->start_date?->toDateString() === $payload['pope_effective_from']
        ) {
            $this->syncPopeDetailsFromAssignment($current);

            return;
        }

        if ($current) {
            $current->update([
                'end_date' => $payload['pope_effective_from'],
                'status' => 'ended',
                'change_reason' => 'succession',
            ]);
        }

        $assignment = PopeAssignment::query()->create([
            'id' => (string) Str::uuid(),
            'pope_name' => $payload['pope_name'],
            'pope_title' => $payload['pope_title'],
            'photo_path' => $current?->photo_path,
            'start_date' => $payload['pope_effective_from'],
            'end_date' => null,
            'status' => 'active',
            'appointment_reference' => $payload['appointment_reference'],
            'change_reason' => null,
        ]);

        $this->syncPopeDetailsFromAssignment($assignment);
    }

    private function syncPopeDetailsFromAssignment(PopeAssignment $assignment): void
    {
        $popeDetails = PopeDetails::query()->orderByDesc('updated_at')->first() ?? new PopeDetails();

        $popeDetails->fill([
            'pope_name' => $assignment->pope_name,
            'pope_title' => $assignment->pope_title,
            'pope_image_path' => $assignment->photo_path,
            'pope_effective_from' => $assignment->start_date,
        ]);
        $popeDetails->save();

        PopeDetails::clearCache();
    }
}
