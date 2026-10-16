<?php

namespace Modules\Tenants\Database\Seeders;

use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Modules\Family\Models\FamilyMember;
use Modules\MassIntentions\Models\MassCelebration;
use Modules\MassIntentions\Models\MassIntentionCategory;
use Modules\MassIntentions\Models\MassIntentionRequest;
use Modules\MassIntentions\Services\MassIntentionCategoryService;
use Modules\MassIntentions\Services\MassIntentionRequestService;
use Modules\MassIntentions\Support\MassGenerationStatus;
use Modules\Tenants\Database\Seeders\Support\CompactDemoMarkers;
use Modules\Tenants\Database\Seeders\Support\DemoSeedGuard;
use Modules\Tenants\Database\Seeders\Support\TenantDemoResolver;

/**
 * Compact Mass intention demo fixtures (open requests via domain service).
 */
class TenantMassIntentionsDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! DemoSeedGuard::assertEnvironmentAllowed($this->command)) {
            return;
        }

        $tenant = TenantDemoResolver::resolveTenant();
        if (! $tenant) {
            $this->command?->error('Tenant not found for Mass intentions demo.');

            return;
        }

        $tenantId = (int) $tenant->id;
        $features = is_array($tenant->features) ? $tenant->features : [];
        if (! in_array('mass_intentions', $features, true)) {
            $this->command?->warn('Tenant does not have mass_intentions feature; skipping Mass intentions demo.');

            return;
        }

        $actor = TenantDemoResolver::resolveActor($tenantId);
        if (! $actor) {
            $this->command?->warn('Skipping Mass intentions demo: no parish user.');

            return;
        }

        TenantDemoResolver::bindTenantContext($actor, $tenantId);

        $existing = MassIntentionRequest::query()
            ->where('tenant_id', $tenantId)
            ->where('notes', 'like', '%'.CompactDemoMarkers::MARKER.'%')
            ->count();

        if ($existing >= CompactDemoMarkers::MASS_INTENTION_TARGET) {
            $this->command?->info('Mass intentions demo already present (idempotent skip).');

            return;
        }

        $categoryService = app(MassIntentionCategoryService::class);
        $categoryService->ensureDefaultsForTenant($tenantId, (int) $actor->id);
        $category = MassIntentionCategory::query()
            ->where('tenant_id', $tenantId)
            ->where('active', true)
            ->orderBy('sort_order')
            ->first();

        if ($category === null) {
            $this->command?->warn('No Mass intention categories available; skipping demo.');

            return;
        }

        $celebration = MassCelebration::query()
            ->where('tenant_id', $tenantId)
            ->where('notes', 'like', '%'.CompactDemoMarkers::MARKER.'%')
            ->where('status', 'scheduled')
            ->orderBy('celebrated_on')
            ->first();

        if ($celebration === null) {
            $celebration = MassCelebration::query()->create([
                'tenant_id' => $tenantId,
                'celebrated_on' => Carbon::now()->addWeeks(2)->toDateString(),
                'celebrated_at' => '07:00:00',
                'place' => 'Main church',
                'status' => 'scheduled',
                'generation_status' => MassGenerationStatus::ACTIVE,
                'notes' => CompactDemoMarkers::MARKER,
                'created_by_user_id' => $actor->id,
            ]);
        }

        $members = FamilyMember::query()
            ->where('tenant_id', $tenantId)
            ->whereHas('family', fn ($q) => $q->where('notes', 'like', '%'.CompactDemoMarkers::MARKER.'%'))
            ->orderBy('id')
            ->limit(CompactDemoMarkers::MASS_INTENTION_TARGET)
            ->get(['id', 'first_name', 'last_name', 'person_id']);

        if ($members->isEmpty()) {
            $this->command?->warn('No compact demo members for Mass intentions; skipping.');

            return;
        }

        $requestService = app(MassIntentionRequestService::class);
        $toCreate = CompactDemoMarkers::MASS_INTENTION_TARGET - $existing;
        $created = 0;

        foreach ($members as $member) {
            if ($created >= $toCreate) {
                break;
            }

            $beneficiaryName = trim($member->first_name.' '.$member->last_name);
            $requestService->create($tenantId, $actor, [
                'celebration_id' => $celebration->id,
                'mass_intention_category_id' => $category->id,
                'beneficiary_name' => $beneficiaryName,
                'beneficiary_person_id' => $member->person_id,
                'beneficiary_place' => 'Parish',
                'intention_description' => 'Demo intention for '.$beneficiaryName,
                'notes' => CompactDemoMarkers::MARKER,
                'requester_name' => 'Parish Office',
            ]);
            $created++;
        }

        $this->command?->info(sprintf('Mass intentions demo: created %d request(s).', $created));
    }
}
