<?php

namespace Modules\Family\Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\BCC\Models\BCC;
use Modules\BCC\Models\BccFamilyMembership;
use Modules\Family\app\Services\FamilyService;
use Modules\Family\Database\Seeders\Support\RealisticParishHouseholdBuilder;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Subscriptions\Models\Feature;
use Modules\Subscriptions\Models\TenantEntitlementOverride;
use Modules\Subscriptions\Services\Entitlements\EntitlementResolver;
use Modules\Tenants\Contracts\TenantLimitGuard;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\TenantContext;

/**
 * Seeds 40–50 realistic households per BCC using FamilyService (Person + sacraments + BCC membership).
 *
 * Run:
 * BCC_DUMMY_TENANT_ID=<id> php artisan db:seed --class=Modules\\Family\\Database\\Seeders\\BccDummyFamiliesSeeder
 *
 * Optional env:
 * - BCC_DUMMY_FAMILIES_MIN (default 40)
 * - BCC_DUMMY_FAMILIES_MAX (default 50)
 * - BCC_DUMMY_DRY_RUN=1
 */
class BccDummyFamiliesSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = $this->resolveTenant();
        if (! $tenant) {
            $this->command?->error('Tenant not found. Set BCC_DUMMY_TENANT_ID or ensure a Sacred Heart tenant exists.');

            return;
        }

        $actor = $this->resolveActor((int) $tenant->id);
        if (! $actor) {
            $this->command?->error('No user found for tenant #'.$tenant->id.'. Create a parish admin user first.');

            return;
        }

        $this->purgeEmptyDummyFamilies((int) $tenant->id);

        Auth::login($actor);
        app()->instance(TenantContext::class, new TenantContext(
            (int) $actor->id,
            $actor->tenant_id ? (int) $actor->tenant_id : (int) $tenant->id,
            (int) $tenant->id,
            null,
            null,
        ));

        $minPerBcc = max(1, (int) env('BCC_DUMMY_FAMILIES_MIN', 40));
        $maxPerBcc = max($minPerBcc, (int) env('BCC_DUMMY_FAMILIES_MAX', 50));
        $dryRun = filter_var(env('BCC_DUMMY_DRY_RUN', false), FILTER_VALIDATE_BOOL);

        $bccs = BCC::query()
            ->where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')
            ->orderBy('name')
            ->get();

        if ($bccs->isEmpty()) {
            $this->command?->error('No BCCs found for tenant '.$tenant->name);

            return;
        }

        $parishName = $tenant->name;
        $official = $tenant->officialAddress()->with(['country', 'state'])->first();
        $countryId = $official?->country_id;
        $stateId = $official?->state_id;
        $defaultCity = (string) ($official?->city ?? 'Kochi');

        $familyService = app(FamilyService::class);
        $builder = new RealisticParishHouseholdBuilder($parishName, (int) $tenant->id);
        $this->loadExistingContacts($builder, (int) $tenant->id);

        $plannedFamilies = 0;
        $plannedMembers = 0;
        foreach ($bccs as $bcc) {
            $target = $this->targetCountForBcc((string) $bcc->id, $minPerBcc, $maxPerBcc);
            $existing = $this->countDummyFamiliesForBcc((int) $tenant->id, (string) $bcc->id);
            $toCreate = max(0, $target - $existing);
            $plannedFamilies += $toCreate;
            $plannedMembers += $toCreate * 5; // average estimate for limit headroom
        }

        if ($plannedFamilies === 0) {
            $this->command?->info('All BCCs already have dummy families at target counts. Running verification.');
            $this->reportVerification((int) $tenant->id, $bccs);

            return;
        }

        $this->command?->info(sprintf(
            'Tenant #%d (%s): planning %d families (~%d people) across %d BCCs.',
            $tenant->id,
            $tenant->name,
            $plannedFamilies,
            $plannedMembers,
            $bccs->count()
        ));

        if ($dryRun) {
            $this->command?->warn('Dry run only — no records created.');

            return;
        }

        $this->ensurePeopleLimit((int) $tenant->id, $actor, $plannedMembers);

        $createdFamilies = 0;
        $createdMembers = 0;

        foreach ($bccs as $bcc) {
            $target = $this->targetCountForBcc((string) $bcc->id, $minPerBcc, $maxPerBcc);
            $existing = $this->countDummyFamiliesForBcc((int) $tenant->id, (string) $bcc->id);
            $toCreate = max(0, $target - $existing);
            $slug = $this->bccSlug((string) $bcc->name);

            $this->command?->info(sprintf('BCC "%s": %d existing, creating %d (target %d).', $bcc->name, $existing, $toCreate, $target));

            for ($i = $existing; $i < $existing + $toCreate; $i++) {
                $blueprint = $builder->build(
                    (string) $bcc->id,
                    $slug,
                    $i,
                    $countryId ? (int) $countryId : null,
                    $stateId ? (int) $stateId : null,
                    $defaultCity,
                );

                $family = $familyService->createFamily(
                    $blueprint['family'],
                    (string) $tenant->id,
                    (string) $actor->id,
                );

                $createdFamilies++;

                $pendingChildren = [];

                foreach ($blueprint['members'] as $memberPayload) {
                    $rel = (string) ($memberPayload['relationship_to_head'] ?? '');
                    if (in_array($rel, ['son', 'daughter', 'brother', 'sister'], true)) {
                        $pendingChildren[] = $memberPayload;

                        continue;
                    }

                    $familyService->addMember(
                        (string) $family->id,
                        $memberPayload,
                        (string) $tenant->id,
                        (string) $actor->id,
                    );
                    $createdMembers++;
                }

                $family = $familyService->getFamilyById((string) $family->id, (string) $tenant->id) ?? $family;
                $headMember = $family->members->firstWhere('relationship_to_head', 'self');
                $spouseMember = $family->members->firstWhere('relationship_to_head', 'spouse');

                $children = $this->linkChildrenToParents(
                    $pendingChildren,
                    $headMember,
                    $spouseMember,
                );

                foreach ($children as $childPayload) {
                    $familyService->addMember(
                        (string) $family->id,
                        $childPayload,
                        (string) $tenant->id,
                        (string) $actor->id,
                    );
                    $createdMembers++;
                }
            }
        }

        $this->command?->info(sprintf('Created %d families and %d members.', $createdFamilies, $createdMembers));

        $this->reportVerification((int) $tenant->id, $bccs);
    }

    /**
     * @param  Collection<int, BCC>  $bccs
     */
    private function reportVerification(int $tenantId, $bccs): void
    {
        $errors = $this->verify($tenantId, $bccs);
        if ($errors === []) {
            $this->command?->info('Verification passed.');
        } else {
            foreach ($errors as $error) {
                $this->command?->error($error);
            }
        }
    }

    private function purgeEmptyDummyFamilies(int $tenantId): void
    {
        $marker = RealisticParishHouseholdBuilder::MARKER;

        Family::query()
            ->where('tenant_id', $tenantId)
            ->where('notes', 'like', '%'.$marker.'%')
            ->whereDoesntHave('members', fn ($query) => $query->withoutGlobalScopes())
            ->each(fn (Family $family) => $family->delete());
    }

    private function resolveTenant(): ?Tenant
    {
        $tenantId = env('BCC_DUMMY_TENANT_ID');
        if ($tenantId) {
            return Tenant::query()->find($tenantId);
        }

        foreach (['Sacred Heart Church', 'Sacred Heart Parish'] as $name) {
            $tenant = Tenant::query()->where('name', $name)->first();
            if ($tenant) {
                return $tenant;
            }
        }

        return Tenant::query()->where('name', 'ILIKE', '%Sacred Heart%')->orderBy('id')->first()
            ?? Tenant::query()->orderBy('id')->first();
    }

    private function resolveActor(int $tenantId): ?User
    {
        return User::query()
            ->where('tenant_id', $tenantId)
            ->orderBy('id')
            ->first()
            ?? User::query()->orderBy('id')->first();
    }

    private function targetCountForBcc(string $bccId, int $min, int $max): int
    {
        $span = $max - $min + 1;

        return $min + (int) (hexdec(substr(md5($bccId), 0, 8)) % $span);
    }

    private function countDummyFamiliesForBcc(int $tenantId, string $bccId): int
    {
        return Family::query()
            ->where('tenant_id', $tenantId)
            ->where('bcc_id', $bccId)
            ->whereNull('deleted_at')
            ->where('notes', 'like', '%'.RealisticParishHouseholdBuilder::MARKER.'%')
            ->count();
    }

    private function bccSlug(string $name): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name) ?? 'bcc');

        return trim($slug, '-') ?: 'bcc';
    }

    private function loadExistingContacts(RealisticParishHouseholdBuilder $builder, int $tenantId): void
    {
        FamilyMember::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->select(['phone', 'email'])
            ->chunk(1000, function ($rows) use ($builder): void {
                foreach ($rows as $row) {
                    if ($row->phone) {
                        $builder->registerExistingPhone((string) $row->phone);
                    }
                    if ($row->email) {
                        $builder->registerExistingEmail((string) $row->email);
                    }
                }
            });
    }

    /**
     * @param  list<array<string, mixed>>  $children
     * @return list<array<string, mixed>>
     */
    private function linkChildrenToParents(array $children, ?FamilyMember $head, ?FamilyMember $spouse): array
    {
        if ($head === null) {
            return $children;
        }

        foreach ($children as $index => $child) {
            $rel = (string) ($child['relationship_to_head'] ?? '');
            if (! in_array($rel, ['son', 'daughter', 'brother', 'sister'], true)) {
                continue;
            }

            if ($spouse !== null) {
                $child['father_person_id'] = $head->gender === 'male' ? $head->person_id : $spouse->person_id;
                $child['mother_person_id'] = $head->gender === 'female' ? $head->person_id : $spouse->person_id;
                $child['father_name'] = $head->gender === 'male'
                    ? trim($head->first_name.' '.$head->last_name)
                    : trim($spouse->first_name.' '.$spouse->last_name);
                $child['mother_name'] = $head->gender === 'female'
                    ? trim($head->first_name.' '.$head->last_name)
                    : trim($spouse->first_name.' '.$spouse->last_name);
            } elseif ($head->gender === 'female') {
                $child['mother_person_id'] = $head->person_id;
                $child['mother_name'] = trim($head->first_name.' '.$head->last_name);
                $child['father_name'] = $child['father_name'] ?? 'Mr. '.$head->last_name;
            } else {
                $child['father_person_id'] = $head->person_id;
                $child['father_name'] = trim($head->first_name.' '.$head->last_name);
                $child['mother_name'] = $child['mother_name'] ?? 'Mrs. '.$head->last_name;
            }

            $children[$index] = $child;
        }

        return $children;
    }

    private function ensurePeopleLimit(int $tenantId, User $actor, int $addingPeople): void
    {
        if (! app()->bound(TenantLimitGuard::class)) {
            return;
        }

        $resolver = app(EntitlementResolver::class);
        $currentPeople = FamilyMember::query()->where('tenant_id', $tenantId)->whereNull('deleted_at')->count();
        $needed = $currentPeople + $addingPeople + 500;
        $limit = $resolver->resolve(Tenant::query()->findOrFail($tenantId))->limit('PEOPLE_LIMIT');

        if ($limit !== null && $needed <= $limit) {
            return;
        }

        $featureId = Feature::query()->where('code', 'PEOPLE_LIMIT')->value('id');
        if (! $featureId) {
            return;
        }

        TenantEntitlementOverride::query()
            ->where('tenant_id', $tenantId)
            ->where('feature_id', $featureId)
            ->open()
            ->update([
                'revoked_at' => now(),
                'revoked_by' => $actor->id,
                'revoke_reason' => 'Replaced for BCC dummy family seeding',
            ]);

        TenantEntitlementOverride::query()->create([
            'tenant_id' => $tenantId,
            'feature_id' => $featureId,
            'mode' => TenantEntitlementOverride::MODE_SET_LIMIT,
            'numeric_value' => $needed,
            'reason' => 'Temporary headroom for BCC dummy family seeding',
            'created_by' => $actor->id,
        ]);

        $resolver->forget($tenantId);

        $this->command?->warn(sprintf('Raised PEOPLE_LIMIT override to %d for seeding.', $needed));
    }

    /**
     * @param  Collection<int, BCC>  $bccs
     * @return list<string>
     */
    private function verify(int $tenantId, $bccs): array
    {
        $errors = [];
        $minPerBcc = max(1, (int) env('BCC_DUMMY_FAMILIES_MIN', 40));
        $maxPerBcc = max($minPerBcc, (int) env('BCC_DUMMY_FAMILIES_MAX', 50));

        foreach ($bccs as $bcc) {
            $count = $this->countDummyFamiliesForBcc($tenantId, (string) $bcc->id);
            $target = $this->targetCountForBcc((string) $bcc->id, $minPerBcc, $maxPerBcc);
            if ($count < $target) {
                $errors[] = sprintf('BCC "%s" has %d dummy families; expected at least %d.', $bcc->name, $count, $target);
            }
        }

        $families = Family::query()
            ->where('tenant_id', $tenantId)
            ->where('notes', 'like', '%'.RealisticParishHouseholdBuilder::MARKER.'%')
            ->withCount('members')
            ->get();

        foreach ($families as $family) {
            if ($family->members_count < 4 || $family->members_count > 6) {
                $errors[] = sprintf('Family %s has %d members (expected 4–6).', $family->family_code, $family->members_count);
            }
            if (empty($family->bcc_id)) {
                $errors[] = sprintf('Family %s missing bcc_id.', $family->family_code);
            }
        }

        $duplicateEmails = FamilyMember::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->whereNotNull('email')
            ->where('email', 'like', '%@dummy.sacredheart.local')
            ->select('email')
            ->groupBy('email')
            ->havingRaw('count(*) > 1')
            ->pluck('email');

        if ($duplicateEmails->isNotEmpty()) {
            $errors[] = 'Duplicate dummy emails: '.$duplicateEmails->take(5)->implode(', ');
        }

        $invalidSacraments = FamilyMember::query()
            ->where('tenant_id', $tenantId)
            ->whereHas('family', fn ($q) => $q->where('notes', 'like', '%'.RealisticParishHouseholdBuilder::MARKER.'%'))
            ->where(function ($q): void {
                $q->whereNotNull('first_communion_date')->whereNull('baptism_date')
                    ->orWhereNotNull('confirmation_date')->whereNull('baptism_date')
                    ->orWhereNotNull('marriage_date')->whereNull('baptism_date');
            })
            ->count();

        if ($invalidSacraments > 0) {
            $errors[] = sprintf('%d members violate sacrament prerequisite order.', $invalidSacraments);
        }

        $dummyFamilyIds = Family::query()
            ->where('tenant_id', $tenantId)
            ->where('notes', 'like', '%'.RealisticParishHouseholdBuilder::MARKER.'%')
            ->pluck('id');

        $membershipFamilyIds = BccFamilyMembership::query()
            ->where('tenant_id', $tenantId)
            ->where('is_current', true)
            ->whereIn('family_id', $dummyFamilyIds)
            ->pluck('family_id');

        $missingMembership = $dummyFamilyIds->diff($membershipFamilyIds)->count();

        if ($missingMembership > 0) {
            $errors[] = sprintf('%d dummy families lack current BccFamilyMembership rows.', $missingMembership);
        }

        $childrenWithoutParents = FamilyMember::query()
            ->where('tenant_id', $tenantId)
            ->whereIn('relationship_to_head', ['son', 'daughter'])
            ->whereHas('family', fn ($q) => $q->where('notes', 'like', '%'.RealisticParishHouseholdBuilder::MARKER.'%'))
            ->whereHas('person', fn ($q) => $q->whereNull('father_person_id')->whereNull('mother_person_id'))
            ->count();

        if ($childrenWithoutParents > 0) {
            $errors[] = sprintf('%d children missing canonical parent person links.', $childrenWithoutParents);
        }

        return $errors;
    }
}
