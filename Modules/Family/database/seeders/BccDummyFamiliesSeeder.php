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
use Modules\Tenants\Database\Seeders\Support\CompactDemoMarkers;
use Modules\Tenants\Database\Seeders\Support\DemoSeedGuard;
use Modules\Tenants\Database\Seeders\Support\TenantDemoMarkers;
use Modules\Tenants\Database\Seeders\Support\TenantDemoResolver;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\TenantContext;

/**
 * Seeds realistic households per BCC using FamilyService (Person + sacraments + BCC membership).
 * Compact demo (orchestrator): 5 families per compact BCC, 2–5 members each.
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
        if (! DemoSeedGuard::assertEnvironmentAllowed($this->command)) {
            return;
        }

        $tenant = $this->resolveTenant();
        if (! $tenant) {
            $this->command?->error('Tenant not found. Set BCC_DUMMY_TENANT_ID or TENANT_DEMO_TENANT_ID.');

            return;
        }

        $actor = DemoSeedGuard::resolveActorForTenant((int) $tenant->id);
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

        $compact = $this->isCompactMode();
        $minPerBcc = max(1, (int) env('BCC_DUMMY_FAMILIES_MIN', $compact ? 5 : 40));
        $maxPerBcc = max($minPerBcc, (int) env('BCC_DUMMY_FAMILIES_MAX', $compact ? 5 : 50));
        $dryRun = filter_var(env('BCC_DUMMY_DRY_RUN', false), FILTER_VALIDATE_BOOL);

        $bccs = $this->resolveBccs((int) $tenant->id, $compact);

        if ($bccs->isEmpty()) {
            $this->command?->error('No BCCs found for tenant '.$tenant->name);

            return;
        }

        $parishName = $tenant->name;
        $official = $tenant->officialAddress()->with(['country', 'state'])->first();
        $countryId = $official?->country_id;
        $stateId = $official?->state_id;
        $defaultCity = (string) ($official?->city ?? 'Kochi');

        $plannedFamilies = 0;
        $plannedMembers = 0;
        foreach ($bccs as $bcc) {
            $toCreate = $this->familiesToCreateForBcc((int) $tenant->id, $bcc, $compact, $minPerBcc, $maxPerBcc);
            $plannedFamilies += $toCreate;
            $plannedMembers += $toCreate * ($compact ? 4 : 5);
        }

        if ($plannedFamilies === 0) {
            $this->command?->info('All BCCs already have dummy families at target counts. Running verification.');
            $this->reportVerification((int) $tenant->id, $bccs, $compact);

            return;
        }

        $familyService = app(FamilyService::class);
        $builder = new RealisticParishHouseholdBuilder($parishName, (int) $tenant->id);
        $this->loadExistingContacts($builder, (int) $tenant->id);
        if ($compact) {
            $this->loadExistingIdentities($builder, (int) $tenant->id);
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

        $createdFamilies = 0;
        $createdMembers = 0;

        foreach ($bccs as $bcc) {
            $compactCount = $this->countCompactFamiliesForBcc((int) $tenant->id, (string) $bcc->id);
            $totalDummy = $this->countDummyFamiliesForBcc((int) $tenant->id, (string) $bcc->id);
            $toCreate = $this->familiesToCreateForBcc((int) $tenant->id, $bcc, $compact, $minPerBcc, $maxPerBcc);
            $slug = $this->bccSlug((string) $bcc->name);
            $target = $compact
                ? CompactDemoMarkers::FAMILIES_PER_BCC
                : $this->targetCountForBcc((string) $bcc->id, $minPerBcc, $maxPerBcc);

            $this->command?->info(sprintf(
                'BCC "%s": %d compact / %d dummy total, creating %d (target %d).',
                $bcc->name,
                $compactCount,
                $totalDummy,
                $toCreate,
                $target
            ));

            for ($slot = $compactCount; $slot < $compactCount + $toCreate; $slot++) {
                $memberOverride = $compact
                    ? CompactDemoMarkers::HOUSEHOLD_SIZES[$slot % count(CompactDemoMarkers::HOUSEHOLD_SIZES)]
                    : null;

                DB::transaction(function () use (
                    $builder,
                    $bcc,
                    $slug,
                    $slot,
                    $countryId,
                    $stateId,
                    $defaultCity,
                    $memberOverride,
                    $compact,
                    $familyService,
                    $tenant,
                    $actor,
                    &$createdFamilies,
                    &$createdMembers,
                ): void {
                    $blueprint = $builder->build(
                        (string) $bcc->id,
                        $slug,
                        $slot,
                        $countryId ? (int) $countryId : null,
                        $stateId ? (int) $stateId : null,
                        $defaultCity,
                        $memberOverride,
                        $compact,
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
                });
            }
        }

        $this->command?->info(sprintf('Created %d families and %d members.', $createdFamilies, $createdMembers));

        $this->reportVerification((int) $tenant->id, $bccs, $compact);
    }

    private function isCompactMode(): bool
    {
        if (TenantDemoResolver::flagIsTrue(CompactDemoMarkers::ENV_COMPACT)) {
            return true;
        }

        $min = (int) env('BCC_DUMMY_FAMILIES_MIN', 40);
        $max = (int) env('BCC_DUMMY_FAMILIES_MAX', 50);

        return $min === 5
            && $max === 5
            && TenantDemoResolver::flagIsTrue(CompactDemoMarkers::ENV_ONLY_DEMO_BCCS);
    }

    /**
     * @return Collection<int, BCC>
     */
    private function resolveBccs(int $tenantId, bool $compact): Collection
    {
        $query = BCC::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->orderBy('bcc_code');

        if ($compact || filter_var(env(CompactDemoMarkers::ENV_ONLY_DEMO_BCCS, false), FILTER_VALIDATE_BOOL)) {
            $query->whereIn('bcc_code', CompactDemoMarkers::BCC_CODES)
                ->where('notes', 'like', '%'.TenantDemoMarkers::MARKER.'%');
        }

        return $query->get();
    }

    private function familiesToCreateForBcc(
        int $tenantId,
        BCC $bcc,
        bool $compact,
        int $minPerBcc,
        int $maxPerBcc,
    ): int {
        $totalDummy = $this->countDummyFamiliesForBcc($tenantId, (string) $bcc->id);
        $compactCount = $this->countCompactFamiliesForBcc($tenantId, (string) $bcc->id);

        if ($compact) {
            if ($totalDummy > CompactDemoMarkers::FAMILIES_PER_BCC && $compactCount < CompactDemoMarkers::FAMILIES_PER_BCC) {
                return 0;
            }

            return max(0, CompactDemoMarkers::FAMILIES_PER_BCC - $compactCount);
        }

        $target = $this->targetCountForBcc((string) $bcc->id, $minPerBcc, $maxPerBcc);
        $existing = $totalDummy;

        return max(0, $target - $existing);
    }

    private function countCompactFamiliesForBcc(int $tenantId, string $bccId): int
    {
        return Family::query()
            ->where('tenant_id', $tenantId)
            ->where('bcc_id', $bccId)
            ->whereNull('deleted_at')
            ->where('notes', 'like', '%'.CompactDemoMarkers::MARKER.'%')
            ->count();
    }

    private function loadExistingIdentities(RealisticParishHouseholdBuilder $builder, int $tenantId): void
    {
        FamilyMember::query()
            ->where('tenant_id', $tenantId)
            ->whereNull('deleted_at')
            ->select(['first_name', 'last_name', 'date_of_birth'])
            ->chunk(500, function ($rows) use ($builder): void {
                foreach ($rows as $row) {
                    $builder->registerExistingIdentity(
                        (string) $row->first_name,
                        (string) $row->last_name,
                        $row->date_of_birth ? (string) $row->date_of_birth : null,
                    );
                }
            });
    }

    /**
     * @param  Collection<int, BCC>  $bccs
     */
    private function reportVerification(int $tenantId, $bccs, bool $compact = false): void
    {
        $errors = $this->verify($tenantId, $bccs, $compact);
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

        return DemoSeedGuard::resolveTenant(TenantDemoResolver::readEnvTenantId());
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

    /**
     * @param  Collection<int, BCC>  $bccs
     * @return list<string>
     */
    private function verify(int $tenantId, $bccs, bool $compact = false): array
    {
        $errors = [];
        $minPerBcc = max(1, (int) env('BCC_DUMMY_FAMILIES_MIN', $compact ? 5 : 40));
        $maxPerBcc = max($minPerBcc, (int) env('BCC_DUMMY_FAMILIES_MAX', $compact ? 5 : 50));

        foreach ($bccs as $bcc) {
            if ($compact) {
                $compactCount = $this->countCompactFamiliesForBcc($tenantId, (string) $bcc->id);
                $totalDummy = $this->countDummyFamiliesForBcc($tenantId, (string) $bcc->id);
                if ($totalDummy > CompactDemoMarkers::FAMILIES_PER_BCC && $compactCount < CompactDemoMarkers::FAMILIES_PER_BCC) {
                    continue;
                }
                if ($compactCount < CompactDemoMarkers::FAMILIES_PER_BCC) {
                    $errors[] = sprintf(
                        'BCC "%s" has %d compact families; expected %d.',
                        $bcc->name,
                        $compactCount,
                        CompactDemoMarkers::FAMILIES_PER_BCC
                    );
                }

                continue;
            }

            $count = $this->countDummyFamiliesForBcc($tenantId, (string) $bcc->id);
            $target = $this->targetCountForBcc((string) $bcc->id, $minPerBcc, $maxPerBcc);
            if ($count < $target) {
                $errors[] = sprintf('BCC "%s" has %d dummy families; expected at least %d.', $bcc->name, $count, $target);
            }
        }

        $familyNotesFilter = $compact
            ? '%'.CompactDemoMarkers::MARKER.'%'
            : '%'.RealisticParishHouseholdBuilder::MARKER.'%';

        $families = Family::query()
            ->where('tenant_id', $tenantId)
            ->where('notes', 'like', $familyNotesFilter)
            ->withCount('members')
            ->get();

        $minMembers = $compact ? 2 : 4;
        $maxMembers = $compact ? 5 : 6;

        foreach ($families as $family) {
            if ($family->members_count < $minMembers || $family->members_count > $maxMembers) {
                $errors[] = sprintf(
                    'Family %s has %d members (expected %d–%d).',
                    $family->family_code,
                    $family->members_count,
                    $minMembers,
                    $maxMembers
                );
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
