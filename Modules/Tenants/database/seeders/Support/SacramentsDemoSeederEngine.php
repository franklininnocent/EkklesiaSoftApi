<?php

namespace Modules\Tenants\Database\Seeders\Support;

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Modules\BCC\Models\BCC;
use Modules\Family\app\Services\FamilyMemberParentNameResolver;
use Modules\Family\Database\Seeders\Support\RealisticParishHouseholdBuilder;
use Modules\Family\Models\Family;
use Modules\Family\Models\FamilyMember;
use Modules\Family\Models\Person;
use Modules\Sacraments\Exceptions\SacramentBusinessRuleException;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentType;
use Modules\Sacraments\Services\Certificates\MarriageCertificateRequirementsValidator;
use Modules\Sacraments\Services\Certificates\MarriageRegisterCertificateEnricher;
use Modules\Sacraments\Services\SacramentService;
use Modules\Sacraments\Services\TenantSacramentSettingsService;
use Modules\Sacraments\Support\MarriageCanonicalClassification;
use Modules\Sacraments\Support\SacramentDispensationType;
use Modules\Sacraments\Support\SacramentOrdinationType;
use Modules\Sacraments\Support\SacramentPrivacyAccess;
use Modules\Sacraments\Support\SacramentStatus;
use Modules\Sacraments\Support\SacramentTypeCode;
use Modules\Tenants\Models\Tenant;

/**
 * Builds idempotent sacrament register rows for dashboard, list, and report QA.
 */
final class SacramentsDemoSeederEngine
{
    /** @var array<string, SacramentType> */
    private array $typesByCode = [];

    public function __construct(
        private readonly int $tenantId,
        private readonly int $actorId,
        private readonly SacramentService $sacramentService,
        private readonly TenantSacramentSettingsService $settingsService,
        private readonly SacramentPrivacyAccess $privacyAccess,
        private readonly FamilyMemberParentNameResolver $parentNameResolver,
        private readonly MarriageRegisterCertificateEnricher $marriageCertificateEnricher,
        private readonly string $parishName,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(?int $targetOverride = null): array
    {
        $this->settingsService->ensureDefaults($this->tenantId, $this->actorId);
        $this->loadTypes();

        $this->ensureHouseholdAddresses();
        $this->ensureHouseholdParentMembersForCertificates();

        $existing = $this->countDemoRows();
        $target = $targetOverride ?? $this->resolveTarget();

        if ($existing >= $target) {
            return $this->buildStats($existing, 0, 0, 'already_satisfied');
        }

        $members = $this->loadMemberPool();
        $pairs = $this->loadMarriagePairs($members);
        $bccIds = BCC::query()->where('tenant_id', $this->tenantId)->whereNull('deleted_at')->pluck('id');

        $created = 0;
        $skipped = 0;
        $sequence = $existing;
        /** @var array<string, int> */
        $memberCursor = [];

        foreach (SacramentsDemoMarkers::TYPE_QUOTAS as $code => $quota) {
            $type = $this->typesByCode[$code] ?? null;
            if (! $type) {
                continue;
            }
            if ($code === SacramentTypeCode::RECONCILIATION && ! $this->canSeedRestricted()) {
                continue;
            }

            $typeExisting = $this->countDemoRowsForType($type->id);
            $typeTarget = min($quota, $target);
            $toCreate = max(0, $typeTarget - $typeExisting);

            for ($i = 0; $i < $toCreate; $i++) {
                if ($this->countDemoRows() >= $target) {
                    break 2;
                }

                $sequence++;
                $cert = $this->certificateNumber($sequence);
                if (Sacrament::query()->where('tenant_id', $this->tenantId)->where('certificate_number', $cert)->exists()) {
                    $skipped++;

                    continue;
                }

                $payload = match ($code) {
                    SacramentTypeCode::MATRIMONY => $this->matrimonyPayload($sequence, $cert, $pairs, $bccIds, $i),
                    SacramentTypeCode::HOLY_ORDERS => $this->holyOrdersPayload($sequence, $cert, $members, $memberCursor, $code),
                    SacramentTypeCode::RECONCILIATION => $this->reconciliationPayload($sequence, $cert),
                    default => $this->standardRecipientPayload($code, $sequence, $cert, $members, $bccIds, $memberCursor),
                };

                if ($payload === null) {
                    $skipped++;

                    continue;
                }

                if ($this->persist($payload, $cert)) {
                    $created++;
                } else {
                    $skipped++;
                }
            }
        }

        return $this->buildStats($this->countDemoRows(), $created, $skipped, 'seeded');
    }

    public function countDemoRows(): int
    {
        return Sacrament::query()
            ->where('tenant_id', $this->tenantId)
            ->where('notes', 'like', '%'.SacramentsDemoMarkers::MARKER.'%')
            ->count();
    }

    private function countDemoRowsForType(int $typeId): int
    {
        return Sacrament::query()
            ->where('tenant_id', $this->tenantId)
            ->where('sacrament_type_id', $typeId)
            ->where('notes', 'like', '%'.SacramentsDemoMarkers::MARKER.'%')
            ->count();
    }

    private function resolveTarget(): int
    {
        $raw = env(SacramentsDemoMarkers::ENV_TARGET);
        if ($raw !== null && $raw !== '' && is_numeric($raw)) {
            return max(1, (int) $raw);
        }

        return SacramentsDemoMarkers::DEFAULT_TARGET;
    }

    private function loadTypes(): void
    {
        $this->typesByCode = [];
        foreach (SacramentType::query()->where('active', true)->get() as $type) {
            $canonical = SacramentTypeCode::normalize($type->code);
            if ($canonical) {
                $this->typesByCode[$canonical] = $type;
            }
        }
    }

    /**
     * @return Collection<int, FamilyMember>
     */
    private function loadMemberPool(): Collection
    {
        $marker = RealisticParishHouseholdBuilder::MARKER;

        $members = FamilyMember::query()
            ->whereHas('family', function ($q) use ($marker) {
                $q->where('tenant_id', $this->tenantId)
                    ->where('status', 'active')
                    ->where(function ($inner) use ($marker) {
                        $inner->where('notes', 'like', '%'.$marker.'%')
                            ->orWhere('notes', 'like', '%'.TenantDemoMarkers::MARKER.'%');
                    });
            })
            ->where('status', 'active')
            ->whereNotNull('date_of_birth')
            ->orderBy('id')
            ->limit(600)
            ->get();

        if ($members->count() >= 40) {
            return $members;
        }

        return FamilyMember::query()
            ->whereHas('family', fn ($q) => $q->where('tenant_id', $this->tenantId)->where('status', 'active'))
            ->where('status', 'active')
            ->whereNotNull('date_of_birth')
            ->orderBy('id')
            ->limit(600)
            ->get();
    }

    /**
     * @param  Collection<int, FamilyMember>  $members
     * @return list<array{bride: FamilyMember, groom: FamilyMember, family_id: string, bcc_id: ?string}>
     */
    private function loadMarriagePairs(Collection $members): array
    {
        $pairs = [];
        $families = Family::query()
            ->where('tenant_id', $this->tenantId)
            ->where('status', 'active')
            ->with(['members' => fn ($q) => $q->where('status', 'active')])
            ->orderBy('id')
            ->limit(3000)
            ->get();

        foreach ($families as $family) {
            $pair = $this->resolveSpousePairFromFamily($family);
            if ($pair === null) {
                continue;
            }
            $brideCtx = $this->memberCertificateContext($pair['bride']);
            $groomCtx = $this->memberCertificateContext($pair['groom']);
            if ($brideCtx === null || $groomCtx === null) {
                continue;
            }
            $pairs[] = $pair;
        }

        if ($pairs !== []) {
            return $pairs;
        }

        $females = $members->filter(fn (FamilyMember $m) => $m->gender === 'female' && $m->date_of_birth)->values();
        $males = $members->filter(fn (FamilyMember $m) => $m->gender === 'male' && $m->date_of_birth)->values();
        $limit = min($females->count(), $males->count(), 50);
        for ($i = 0; $i < $limit; $i++) {
            $bride = $females[$i];
            $groom = $males[$i];
            if ($bride->family_id !== $groom->family_id) {
                continue;
            }
            $pairs[] = [
                'bride' => $bride,
                'groom' => $groom,
                'family_id' => (string) $bride->family_id,
                'bcc_id' => null,
            ];
        }

        return $pairs;
    }

    /**
     * @return array{bride: FamilyMember, groom: FamilyMember, family_id: string, bcc_id: ?string}|null
     */
    private function resolveSpousePairFromFamily(Family $family): ?array
    {
        $head = $family->members->first(fn (FamilyMember $m) => $m->relationship_to_head === 'self' && $m->date_of_birth);
        $spouse = $family->members->first(fn (FamilyMember $m) => $m->relationship_to_head === 'spouse' && $m->date_of_birth);

        if ($head && $spouse) {
            return $this->brideGroomPair($head, $spouse, $family);
        }

        $married = $family->members->filter(fn (FamilyMember $m) => ! empty($m->marriage_date) && $m->date_of_birth)->values();
        if ($married->count() >= 2) {
            $female = $married->first(fn (FamilyMember $m) => $m->gender === 'female');
            $male = $married->first(fn (FamilyMember $m) => $m->gender === 'male');
            if ($female && $male && $female->id !== $male->id) {
                return [
                    'bride' => $female,
                    'groom' => $male,
                    'family_id' => (string) $family->id,
                    'bcc_id' => $family->bcc_id ? (string) $family->bcc_id : null,
                ];
            }
        }

        return null;
    }

    /**
     * @return array{bride: FamilyMember, groom: FamilyMember, family_id: string, bcc_id: ?string}
     */
    private function brideGroomPair(FamilyMember $head, FamilyMember $spouse, Family $family): array
    {
        if ($head->gender === 'male' && $spouse->gender === 'female') {
            $bride = $spouse;
            $groom = $head;
        } elseif ($head->gender === 'female' && $spouse->gender === 'male') {
            $bride = $head;
            $groom = $spouse;
        } else {
            $bride = $head->gender === 'female' ? $head : $spouse;
            $groom = $head->gender === 'male' ? $head : $spouse;
        }

        return [
            'bride' => $bride,
            'groom' => $groom,
            'family_id' => (string) $family->id,
            'bcc_id' => $family->bcc_id ? (string) $family->bcc_id : null,
        ];
    }

    /**
     * @param  Collection<int, FamilyMember>  $members
     * @param  Collection<int, mixed>  $bccIds
     * @return array<string, mixed>|null
     */
    private function standardRecipientPayload(
        string $code,
        int $sequence,
        string $cert,
        Collection $members,
        Collection $bccIds,
        array &$memberCursor,
    ): ?array {
        $member = $this->pickMemberForType($code, $members, $memberCursor);
        if (! $member) {
            return null;
        }

        $member->loadMissing('family');
        $memberCtx = $this->memberCertificateContext($member);
        if ($memberCtx === null && in_array($code, [SacramentTypeCode::BAPTISM], true)) {
            return null;
        }
        $type = $this->typesByCode[$code];
        $dob = Carbon::parse((string) $member->date_of_birth);
        $eventDate = $this->eventDateForType($code, $dob, $sequence);
        $status = $this->statusForSequence($sequence);
        $bccId = $member->family?->bcc_id ?? $bccIds->get($sequence % max(1, $bccIds->count()));

        $payload = [
            'sacrament_type_id' => $type->id,
            'date_administered' => $eventDate,
            'place_administered' => $this->parishName,
            'certificate_number' => $cert,
            'book_number' => sprintf('D-%02d', ($sequence % 12) + 1),
            'page_number' => (string) (($sequence % 450) + 1),
            'status' => $status,
            'family_id' => $member->family_id,
            'bcc_id' => $bccId,
            'person_id' => $member->person_id,
            'recipient_birth_date' => $dob->toDateString(),
            'recipient_birth_place' => $this->parishName,
            'recipient_gender' => $member->gender ?? 'male',
            'father_name' => $memberCtx['father_name'] ?? $member->first_name.' Sr',
            'mother_name' => $memberCtx['mother_name'] ?? 'Mrs. '.$member->last_name,
            'notes' => SacramentsDemoMarkers::MARKER.' sequence '.$sequence,
            'participants' => [
                [
                    'role' => 'recipient',
                    'source' => 'member',
                    'family_member_id' => $member->id,
                    'sort_order' => 0,
                ],
                [
                    'role' => 'minister',
                    'source' => 'external',
                    'external_full_name' => 'Fr. '.$this->ministerSurname($sequence),
                    'external_title' => 'Fr.',
                    'external_minister_role' => 'priest',
                    'sort_order' => 0,
                ],
            ],
        ];

        if ($code === SacramentTypeCode::BAPTISM) {
            $payload['godparent1_name'] = 'Godparent '.$sequence;
            if ($sequence % 3 !== 0) {
                $payload['godparent2_name'] = 'Godparent B '.$sequence;
            }
            $payload['participants'][] = [
                'role' => 'godfather',
                'source' => 'external',
                'external_full_name' => 'Godparent '.$sequence,
                'sort_order' => 0,
            ];
        }

        if ($status === SacramentStatus::CONDITIONAL) {
            $payload['conditional_date'] = $eventDate;
            $payload['conditional_reason'] = 'Demo conditional — pending documentary verification.';
        }

        if ($sequence % 11 === 0) {
            unset($payload['book_number'], $payload['page_number']);
        }

        return $payload;
    }

    /**
     * @param  list<array{bride: FamilyMember, groom: FamilyMember, family_id: string, bcc_id: ?string}>  $pairs
     * @return array<string, mixed>|null
     */
    private function matrimonyPayload(int $sequence, string $cert, array $pairs, Collection $bccIds, int $pairIndex): ?array
    {
        if ($pairs === []) {
            return null;
        }

        $pair = null;
        $brideCtx = null;
        $groomCtx = null;
        for ($attempt = 0; $attempt < count($pairs); $attempt++) {
            $candidate = $pairs[($pairIndex + $attempt) % count($pairs)];
            $brideContext = $this->memberCertificateContext($candidate['bride']);
            $groomContext = $this->memberCertificateContext($candidate['groom']);
            if ($brideContext !== null && $groomContext !== null) {
                $pair = $candidate;
                $brideCtx = $brideContext;
                $groomCtx = $groomContext;
                break;
            }
        }
        if ($pair === null || $brideCtx === null || $groomCtx === null) {
            return null;
        }

        $type = $this->typesByCode[SacramentTypeCode::MATRIMONY];
        $bride = $pair['bride'];
        $groom = $pair['groom'];
        $bride->loadMissing('family');
        $groom->loadMissing('family');
        $eventDate = $this->marriageDate($bride, $groom, $sequence);
        $classifications = MarriageCanonicalClassification::all();
        $classification = $classifications[$sequence % count($classifications)];
        $brideAffiliation = $this->marriageAffiliationForMember($bride, $sequence, true);
        $groomAffiliation = $this->marriageAffiliationForMember($groom, $sequence, false);

        $payload = [
            'sacrament_type_id' => $type->id,
            'date_administered' => $eventDate,
            'place_administered' => $bride->marriage_place ?: $this->parishName,
            'place_classification' => 'parish',
            'certificate_number' => $cert,
            'book_number' => 'M-'.sprintf('%02d', ($sequence % 8) + 1),
            'page_number' => (string) (($sequence % 300) + 1),
            'registry_entry' => sprintf('DEMO-MAT-%05d', $sequence),
            'status' => $sequence % 23 === 0 ? SacramentStatus::CONDITIONAL : SacramentStatus::REGISTERED,
            'family_id' => $pair['family_id'],
            'bcc_id' => $pair['bcc_id'] ?? $bccIds->get($sequence % max(1, $bccIds->count())),
            'marriage_canonical_classification' => $classification,
            'notes' => SacramentsDemoMarkers::MARKER.' matrimony '.$sequence,
            'marriage_bride_full_name' => $brideCtx['full_name'],
            'marriage_bride_father_name' => $brideCtx['father_name'],
            'marriage_bride_mother_name' => $brideCtx['mother_name'],
            'marriage_bride_address' => $brideCtx['address'],
            'marriage_groom_full_name' => $groomCtx['full_name'],
            'marriage_groom_father_name' => $groomCtx['father_name'],
            'marriage_groom_mother_name' => $groomCtx['mother_name'],
            'marriage_groom_address' => $groomCtx['address'],
            'participants' => [
                array_merge(
                    [
                        'role' => 'bride',
                        'source' => 'member',
                        'family_member_id' => $bride->id,
                        'sort_order' => 0,
                        'father_name' => $brideCtx['father_name'],
                        'mother_name' => $brideCtx['mother_name'],
                    ],
                    $brideAffiliation
                ),
                array_merge(
                    [
                        'role' => 'groom',
                        'source' => 'member',
                        'family_member_id' => $groom->id,
                        'sort_order' => 0,
                        'father_name' => $groomCtx['father_name'],
                        'mother_name' => $groomCtx['mother_name'],
                    ],
                    $groomAffiliation
                ),
                $this->marriageWitnessParticipant($sequence, 0, 'female'),
                $this->marriageWitnessParticipant($sequence, 1, 'male'),
                [
                    'role' => 'minister',
                    'source' => 'external',
                    'external_full_name' => 'Fr. '.$this->ministerSurname($sequence),
                    'external_title' => 'Fr.',
                    'external_minister_role' => 'priest',
                    'sort_order' => 0,
                ],
            ],
            'dispensations' => $this->dispensationsForClassification($classification, $eventDate),
        ];

        if ($payload['status'] === SacramentStatus::CONDITIONAL) {
            $payload['conditional_date'] = $eventDate;
            $payload['conditional_reason'] = 'Demo matrimony — documentary follow-up for canonical form.';
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function marriageAffiliationForMember(FamilyMember $member, int $sequence, bool $isBride): array
    {
        $churchType = (string) ($isBride ? $member->marriage_bride_church_type : $member->marriage_groom_church_type);
        $useOther = $sequence % 11 === 0 || $churchType === 'other';

        if ($useOther) {
            return [
                'affiliation_type' => 'other',
                'affiliation_parish_name' => $isBride
                    ? ($member->marriage_bride_church_name ?: 'St. Joseph Parish')
                    : ($member->marriage_groom_church_name ?: 'St. Peter Parish'),
                'affiliation_parish_address' => $this->parishName.' — guest parish',
                'affiliation_diocese_name' => 'Demo Archdiocese',
                'affiliation_diocese_region' => 'Kerala',
                'affiliation_diocese_country' => 'India',
            ];
        }

        return [
            'affiliation_type' => 'home_parish',
            'affiliation_parish_name' => $this->parishName,
            'affiliation_parish_address' => $this->parishName.' parish campus',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function marriageWitnessParticipant(int $sequence, int $witnessIndex, string $gender): array
    {
        $street = 10 + (($sequence + $witnessIndex) % 200);

        return [
            'role' => 'witness',
            'source' => 'external',
            'external_full_name' => sprintf('Witness %s %d', $gender === 'female' ? 'Mary' : 'John', $sequence),
            'external_address' => sprintf('%d Parish Avenue, Kochi', $street),
            'external_gender' => $gender,
            'external_contact_number' => sprintf('+91 98%08d', 100000 + $sequence * 3 + $witnessIndex),
            'sort_order' => $witnessIndex,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function dispensationsForClassification(string $classification, string $eventDate): array
    {
        if (! MarriageCanonicalClassification::requiresDispensation($classification)) {
            return [];
        }

        $type = $classification === MarriageCanonicalClassification::DISPARITY_OF_CULT
            ? SacramentDispensationType::DISPARITY_OF_CULT
            : SacramentDispensationType::MIXED_MARRIAGE_PERMISSION;

        return [[
            'dispensation_type' => $type,
            'granting_authority' => 'Demo Archdiocese — Tribunal',
            'protocol_number' => 'DEMO-PROT-'.substr(md5($classification.$eventDate), 0, 8),
            'date_granted' => $eventDate,
        ]];
    }

    /**
     * @param  Collection<int, FamilyMember>  $members
     * @return array<string, mixed>|null
     */
    private function holyOrdersPayload(int $sequence, string $cert, Collection $members, array &$memberCursor, string $code): ?array
    {
        $type = $this->typesByCode[SacramentTypeCode::HOLY_ORDERS];
        $candidates = $members->filter(fn (FamilyMember $m) => $m->gender === 'male' && $this->ageYears($m) >= 25)->values();
        $cursor = $memberCursor[$code] ?? 0;
        $candidate = $candidates->get($cursor);
        $memberCursor[$code] = $cursor + 1;
        if (! $candidate) {
            return null;
        }

        $ordinationTypes = SacramentOrdinationType::all();
        $ordination = $ordinationTypes[$sequence % count($ordinationTypes)];

        return [
            'sacrament_type_id' => $type->id,
            'date_administered' => $this->eventDateForType(SacramentTypeCode::HOLY_ORDERS, Carbon::parse((string) $candidate->date_of_birth), $sequence),
            'place_administered' => $this->parishName,
            'certificate_number' => $cert,
            'status' => SacramentStatus::REGISTERED,
            'family_id' => $candidate->family_id,
            'person_id' => $candidate->person_id,
            'typed_attributes' => [
                'ordination_type' => $ordination,
                'diocese_name' => 'Demo Diocese',
            ],
            'notes' => SacramentsDemoMarkers::MARKER.' holy_orders '.$sequence,
            'participants' => [
                [
                    'role' => 'candidate',
                    'source' => 'member',
                    'family_member_id' => $candidate->id,
                    'sort_order' => 0,
                ],
                [
                    'role' => 'minister',
                    'source' => 'external',
                    'external_full_name' => 'Bishop '.$this->ministerSurname($sequence),
                    'external_title' => 'Bishop',
                    'external_minister_role' => 'bishop',
                    'sort_order' => 0,
                ],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function reconciliationPayload(int $sequence, string $cert): array
    {
        $type = $this->typesByCode[SacramentTypeCode::RECONCILIATION];
        $eventDate = now()->subMonths($sequence % 30)->subDays($sequence % 28)->toDateString();

        return [
            'sacrament_type_id' => $type->id,
            'date_administered' => $eventDate,
            'place_administered' => $this->parishName.' — confessional',
            'certificate_number' => $cert,
            'status' => SacramentStatus::REGISTERED,
            'notes' => SacramentsDemoMarkers::MARKER.' reconciliation '.$sequence,
            'participants' => [
                [
                    'role' => 'recipient',
                    'source' => 'external',
                    'external_full_name' => 'Penitent '.$sequence,
                    'sort_order' => 0,
                ],
                [
                    'role' => 'minister',
                    'source' => 'external',
                    'external_full_name' => 'Fr. '.$this->ministerSurname($sequence),
                    'external_title' => 'Fr.',
                    'external_minister_role' => 'priest',
                    'sort_order' => 0,
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function persist(array $payload, string $idempotencyKey): bool
    {
        $payload['tenant_id'] = $this->tenantId;
        $payload['created_by'] = $this->actorId;
        $payload['updated_by'] = $this->actorId;
        $payload['acknowledge_duplicate_warning'] = true;
        $payload['notes'] = trim((string) ($payload['notes'] ?? '').' '.SacramentsDemoMarkers::MARKER);

        try {
            $created = $this->sacramentService->create($payload, 'demo_'.$idempotencyKey);
            $sacrament = $created['sacrament'];
            $typeCode = SacramentTypeCode::normalize($sacrament->sacramentType?->code);
            if ($typeCode === SacramentTypeCode::MATRIMONY) {
                $this->marriageCertificateEnricher->enrich($sacrament, $this->tenantId);
                $sacrament->refresh();
                if ((new MarriageCertificateRequirementsValidator)->missingFields($sacrament) !== []) {
                    $sacrament->delete();

                    return false;
                }
            }

            return true;
        } catch (SacramentBusinessRuleException $e) {
            Log::warning('Sacraments demo row skipped', [
                'tenant_id' => $this->tenantId,
                'certificate' => $idempotencyKey,
                'code' => $e->getCode(),
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function canSeedRestricted(): bool
    {
        $user = auth()->user();

        return $user && $this->privacyAccess->canViewRestricted($user);
    }

    private function certificateNumber(int $sequence): string
    {
        return SacramentsDemoMarkers::CERT_PREFIX.$this->tenantId.'-'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }

    private function ministerSurname(int $sequence): string
    {
        $names = ['Thomas', 'Joseph', 'Antony', 'Xavier', 'Mathew', 'George', 'Paul', 'Sebastian'];

        return $names[$sequence % count($names)];
    }

    private function statusForSequence(int $sequence): string
    {
        if ($sequence % 47 === 0) {
            return SacramentStatus::VOIDED;
        }
        if ($sequence % 19 === 0) {
            return SacramentStatus::CONDITIONAL;
        }

        return SacramentStatus::REGISTERED;
    }

    private function eventDateForType(string $code, Carbon $dob, int $sequence): string
    {
        $monthsAgo = ($sequence % 36) + 1;
        $anchor = match ($code) {
            SacramentTypeCode::BAPTISM => $dob->copy()->addDays(30),
            SacramentTypeCode::EUCHARIST => $dob->copy()->addYears(8),
            SacramentTypeCode::CONFIRMATION => $dob->copy()->addYears(15),
            SacramentTypeCode::ANOINTING => $dob->copy()->addYears(max(18, min(70, 40 + ($sequence % 25)))),
            SacramentTypeCode::HOLY_ORDERS => $dob->copy()->addYears(28),
            default => $dob->copy()->addYears(20),
        };

        if ($anchor->isFuture()) {
            $anchor = now()->subMonths($monthsAgo);
        }

        return $anchor->copy()->subMonths($monthsAgo % 12)->toDateString();
    }

    private function marriageDate(FamilyMember $bride, FamilyMember $groom, int $sequence): string
    {
        $base = null;
        foreach ([$bride, $groom] as $party) {
            if (! empty($party->marriage_date)) {
                $base = Carbon::parse((string) $party->marriage_date);
                break;
            }
        }

        if ($base === null) {
            $older = min($this->ageYears($bride), $this->ageYears($groom));
            $base = now()->subYears(max(1, $older - 22))->subMonths($sequence % 18);
        }

        // Unique administered date per demo row (duplicate policy is parties + date).
        return $base->copy()->addDays($sequence % 21)->toDateString();
    }

    /**
     * @param  Collection<int, FamilyMember>  $members
     */
    /**
     * @param  array<string, int>  $memberCursor
     */
    private function pickMemberForType(string $code, Collection $members, array &$memberCursor): ?FamilyMember
    {
        $filtered = $members->filter(function (FamilyMember $m) use ($code) {
            $age = $this->ageYears($m);

            return match ($code) {
                SacramentTypeCode::BAPTISM => $age <= 12,
                SacramentTypeCode::EUCHARIST => $age >= 7 && $age <= 16,
                SacramentTypeCode::CONFIRMATION => $age >= 12 && $age <= 22,
                SacramentTypeCode::ANOINTING => $age >= 55,
                default => $age >= 8,
            };
        })->values();

        if ($filtered->isEmpty()) {
            $filtered = $members->values();
        }

        $cursor = $memberCursor[$code] ?? 0;
        $member = $filtered->get($cursor);
        $memberCursor[$code] = $cursor + 1;

        return $member;
    }

    private function ageYears(FamilyMember $member): int
    {
        if (! $member->date_of_birth) {
            return 30;
        }

        return (int) Carbon::parse((string) $member->date_of_birth)->diffInYears(now());
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStats(int $total, int $created, int $skipped, string $phase): array
    {
        $byType = Sacrament::query()
            ->where('tenant_id', $this->tenantId)
            ->whereNull('sacraments.deleted_at')
            ->where('notes', 'like', '%'.SacramentsDemoMarkers::MARKER.'%')
            ->join('sacrament_types', 'sacrament_types.id', '=', 'sacraments.sacrament_type_id')
            ->selectRaw('UPPER(sacrament_types.code) as code, COUNT(*) as aggregate')
            ->groupBy('code')
            ->pluck('aggregate', 'code')
            ->all();

        $byStatus = Sacrament::query()
            ->where('tenant_id', $this->tenantId)
            ->where('notes', 'like', '%'.SacramentsDemoMarkers::MARKER.'%')
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status')
            ->all();

        return [
            'phase' => $phase,
            'total_demo_rows' => $total,
            'created_this_run' => $created,
            'skipped_this_run' => $skipped,
            'by_type' => $byType,
            'by_status' => $byStatus,
        ];
    }

    private function ensureHouseholdAddresses(): void
    {
        Family::query()
            ->where('tenant_id', $this->tenantId)
            ->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('address_line_1')->orWhere('address_line_1', '');
            })
            ->orderBy('id')
            ->limit(5000)
            ->each(function (Family $family): void {
                $family->update([
                    'address_line_1' => sprintf('%d Parish Road', 10 + ((int) $family->id % 200)),
                    'city' => 'Kochi',
                    'postal_code' => sprintf('%06d', 682000 + ((int) $family->id % 500)),
                ]);
            });
    }

    /**
     * Adds father/mother household rows when missing so marriage/baptism certificates can resolve parents.
     */
    private function ensureHouseholdParentMembersForCertificates(): void
    {
        $supportMarker = SacramentsDemoMarkers::MARKER.'_parent_support';

        Family::query()
            ->where('tenant_id', $this->tenantId)
            ->where('status', 'active')
            ->with(['members' => fn ($q) => $q->whereNull('deleted_at')])
            ->orderBy('id')
            ->limit(3000)
            ->each(function (Family $family) use ($supportMarker): void {
                $members = $family->members;
                if ($members->isEmpty()) {
                    return;
                }

                $hasFather = $members->contains(fn (FamilyMember $m) => $m->relationship_to_head === 'father');
                $hasMother = $members->contains(fn (FamilyMember $m) => $m->relationship_to_head === 'mother');
                if ($hasFather && $hasMother) {
                    return;
                }

                $surname = $members->first(fn (FamilyMember $m) => filled($m->last_name))?->last_name
                    ?? 'Family';
                $head = $members->first(fn (FamilyMember $m) => $m->relationship_to_head === 'self');

                if (! $hasFather) {
                    $this->createSupportParentMember($family, 'Joseph', 'male', 'father', $surname, $supportMarker);
                }

                if (! $hasMother) {
                    $this->createSupportParentMember($family, 'Mary', 'female', 'mother', $surname, $supportMarker);
                }

                if ($head?->person_id) {
                    $person = $head->person;
                    if ($person && (! filled($person->father_name) || ! filled($person->mother_name))) {
                        $person->update([
                            'father_name' => $person->father_name ?: 'Joseph '.$surname,
                            'mother_name' => $person->mother_name ?: 'Mary '.$surname,
                        ]);
                    }
                }
            });
    }

    /**
     * @return array{full_name: string, father_name: string, mother_name: string, address: string}|null
     */
    private function memberCertificateContext(FamilyMember $member): ?array
    {
        $member->loadMissing(['family', 'person']);
        $family = $member->family;
        if (! $family) {
            return null;
        }

        $address = $this->formatFamilyAddress($family);
        if ($address === null) {
            return null;
        }

        $familyMembers = FamilyMember::query()
            ->where('family_id', $family->id)
            ->whereNull('deleted_at')
            ->get(['id', 'family_id', 'first_name', 'middle_name', 'last_name', 'relationship_to_head', 'gender', 'person_id']);

        $parents = $this->parentNameResolver->resolveForMember($member, $familyMembers);
        $father = $this->filledText($parents['father_name'] ?? null);
        $mother = $this->filledText($parents['mother_name'] ?? null);

        if ($father === null || $mother === null) {
            $fatherMember = $familyMembers->first(fn (FamilyMember $m) => $m->relationship_to_head === 'father');
            $motherMember = $familyMembers->first(fn (FamilyMember $m) => $m->relationship_to_head === 'mother');
            $father = $father ?? ($fatherMember ? $this->memberDisplayName($fatherMember) : null);
            $mother = $mother ?? ($motherMember ? $this->memberDisplayName($motherMember) : null);
        }

        if ($father === null || $mother === null) {
            $father = $father ?? $this->filledText($member->person?->father_name);
            $mother = $mother ?? $this->filledText($member->person?->mother_name);
        }

        if ($father === null || $mother === null) {
            return null;
        }

        $fullName = $this->filledText($member->person?->full_name_display)
            ?? $this->filledText(trim(implode(' ', array_filter([
                $member->first_name,
                $member->middle_name,
                $member->last_name,
            ]))));

        if ($fullName === null) {
            return null;
        }

        return [
            'full_name' => $fullName,
            'father_name' => $father,
            'mother_name' => $mother,
            'address' => $address,
        ];
    }

    private function formatFamilyAddress(Family $family): ?string
    {
        $parts = array_filter([
            $family->address_line_1,
            $family->address_line_2,
            $family->city,
            $family->postal_code,
        ], fn ($v) => $v !== null && trim((string) $v) !== '');

        return $parts !== [] ? implode(', ', $parts) : null;
    }

    private function createSupportParentMember(
        Family $family,
        string $firstName,
        string $gender,
        string $relationship,
        string $surname,
        string $notes,
    ): void {
        $person = Person::query()->create([
            'tenant_id' => $this->tenantId,
            'first_name' => $firstName,
            'last_name' => $surname,
            'gender' => $gender,
            'date_of_birth' => now()->subYears($gender === 'male' ? 65 : 62)->toDateString(),
            'status' => 'active',
            'created_by' => $this->actorId,
            'updated_by' => $this->actorId,
        ]);

        FamilyMember::query()->create([
            'tenant_id' => $this->tenantId,
            'family_id' => $family->id,
            'person_id' => $person->id,
            'first_name' => $firstName,
            'middle_name' => null,
            'last_name' => $surname,
            'gender' => $gender,
            'relationship_to_head' => $relationship,
            'date_of_birth' => $person->date_of_birth,
            'status' => 'active',
            'notes' => $notes,
            'created_by' => $this->actorId,
            'updated_by' => $this->actorId,
        ]);
    }

    private function memberDisplayName(FamilyMember $member): string
    {
        return trim(implode(' ', array_filter([
            $member->first_name,
            $member->middle_name,
            $member->last_name,
        ])));
    }

    private function filledText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    public static function forTenant(Tenant $tenant, int $actorId): self
    {
        return new self(
            (int) $tenant->id,
            $actorId,
            app(SacramentService::class),
            app(TenantSacramentSettingsService::class),
            app(SacramentPrivacyAccess::class),
            app(FamilyMemberParentNameResolver::class),
            app(MarriageRegisterCertificateEnricher::class),
            (string) $tenant->name,
        );
    }
}
