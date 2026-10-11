<?php

namespace Modules\MassIntentions\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Authentication\Models\User;
use Modules\MassIntentions\Models\MassDayOverride;
use Modules\MassIntentions\Models\MassDayOverrideSlot;
use Modules\MassIntentions\Support\MassDayOverrideFingerprint;
use Modules\MassIntentions\Support\MassSchedulePlaceKey;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class MassDayOverrideService
{
    public function __construct(
        private readonly MassScheduleExpectedBuilder $expectedBuilder,
        private readonly MassOccurrenceReconciler $reconciler,
        private readonly MassIntentionAuditService $audits,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForRange(int $tenantId, string $from, string $to): array
    {
        return MassDayOverride::query()
            ->with('slots')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->whereBetween('override_on', [$from, $to])
            ->orderBy('override_on')
            ->get()
            ->map(fn (MassDayOverride $row) => $this->toArray($row))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function show(int $tenantId, string $id): array
    {
        $override = $this->findActiveOrAny($tenantId, $id);

        return $this->toArray($override);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function save(int $tenantId, User $actor, array $payload): array
    {
        $overrideOn = (string) $payload['override_on'];
        $mode = (string) $payload['mode'];
        if (! in_array($mode, ['replace', 'supplement'], true)) {
            throw ValidationException::withMessages([
                'mode' => 'Choose whether this replaces or adds to the regular schedule.',
            ]);
        }

        $closes = (bool) ($payload['closes_regular_masses'] ?? false);
        $slots = $this->normalizeSlots($payload['slots'] ?? []);
        $this->assertSlotsValid($mode, $closes, $slots);

        return DB::transaction(function () use ($tenantId, $actor, $overrideOn, $mode, $closes, $slots, $payload): array {
            $existing = MassDayOverride::query()
                ->where('tenant_id', $tenantId)
                ->where('status', 'active')
                ->whereDate('override_on', $overrideOn)
                ->first();

            if ($existing !== null && isset($payload['id']) && $payload['id'] !== $existing->id) {
                throw ValidationException::withMessages([
                    'override_on' => 'Another special schedule already exists for this date.',
                ]);
            }

            if ($existing === null) {
                $existing = MassDayOverride::query()->create([
                    'tenant_id' => $tenantId,
                    'override_on' => $overrideOn,
                    'mode' => $mode,
                    'closes_regular_masses' => $closes,
                    'label' => $payload['label'] ?? null,
                    'status' => 'active',
                    'created_by_user_id' => $actor->id,
                ]);
            } else {
                $existing->mode = $mode;
                $existing->closes_regular_masses = $closes;
                $existing->label = $payload['label'] ?? null;
                $existing->save();
                MassDayOverrideSlot::query()
                    ->where('tenant_id', $tenantId)
                    ->where('day_override_id', $existing->id)
                    ->delete();
            }

            $sort = 0;
            foreach ($slots as $slot) {
                MassDayOverrideSlot::query()->create([
                    'tenant_id' => $tenantId,
                    'day_override_id' => $existing->id,
                    'slot_id' => $slot['slot_id'] ?? (string) Str::uuid(),
                    'celebrated_at' => $slot['celebrated_at'],
                    'place' => $slot['place'],
                    'celebrant_name' => $slot['celebrant_name'],
                    'place_key' => MassSchedulePlaceKey::fromPlace($slot['place']),
                    'sort_order' => $sort++,
                ]);
            }

            if ($mode === 'supplement') {
                $this->assertNoSupplementDuplicates($tenantId, $overrideOn, $existing->fresh(['slots']));
            }

            $this->audits->record($tenantId, 'day_override.saved', $actor, null, null, [
                'day_override_id' => $existing->id,
                'override_on' => $overrideOn,
                'mode' => $mode,
            ]);

            return $this->toArray($existing->fresh(['slots']));
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function preview(int $tenantId, string $overrideId): array
    {
        $override = $this->findActiveOrAny($tenantId, $overrideId);
        $date = Carbon::parse($override->override_on)->startOfDay();
        $expected = $this->expectedBuilder->buildForTenantDate($tenantId, $date);
        $reconcile = $this->reconciler->previewWithExpected($tenantId, $date, $date, $expected);
        $fingerprint = MassDayOverrideFingerprint::forOverride($override);

        return [
            'fingerprint' => $fingerprint,
            'override_on' => $override->override_on?->format('Y-m-d'),
            'counts' => $this->countsOnly($reconcile),
            'conflicts' => $reconcile['conflicts'] ?? [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function apply(int $tenantId, User $actor, string $overrideId, string $fingerprint): array
    {
        return DB::transaction(function () use ($tenantId, $actor, $overrideId, $fingerprint): array {
            $override = MassDayOverride::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $overrideId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->firstOrFail();

            $expectedFingerprint = MassDayOverrideFingerprint::forOverride($override);
            if (! hash_equals($expectedFingerprint, $fingerprint)) {
                throw new HttpException(409, 'The special schedule changed. Preview again.');
            }

            $override->loadMissing('slots');
            $date = Carbon::parse($override->override_on)->startOfDay();
            $expected = $this->expectedBuilder->buildForTenantDate($tenantId, $date);
            $reconcile = $this->reconciler->applyWithExpected($tenantId, $date, $date, $actor, $fingerprint, $expected);

            $this->audits->record($tenantId, 'day_override.applied', $actor, null, null, [
                'day_override_id' => $override->id,
                'override_on' => $override->override_on?->format('Y-m-d'),
                'counts' => $this->countsOnly($reconcile),
            ]);

            return [
                'counts' => $this->countsOnly($reconcile),
                'conflicts' => $reconcile['conflicts'] ?? [],
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function inactivate(int $tenantId, User $actor, string $overrideId): array
    {
        return DB::transaction(function () use ($tenantId, $actor, $overrideId): array {
            $override = MassDayOverride::query()
                ->where('tenant_id', $tenantId)
                ->where('id', $overrideId)
                ->where('status', 'active')
                ->lockForUpdate()
                ->firstOrFail();

            $override->status = 'inactive';
            $override->save();

            $date = Carbon::parse($override->override_on)->startOfDay();
            $expected = $this->expectedBuilder->buildForTenantDate($tenantId, $date);
            $fingerprint = hash('sha256', 'inactive|'.$override->id.'|'.now()->timestamp);
            $reconcile = $this->reconciler->applyWithExpected($tenantId, $date, $date, $actor, $fingerprint, $expected);

            $this->audits->record($tenantId, 'day_override.inactivated', $actor, null, null, [
                'day_override_id' => $override->id,
                'override_on' => $override->override_on?->format('Y-m-d'),
            ]);

            return [
                'counts' => $this->countsOnly($reconcile),
                'conflicts' => $reconcile['conflicts'] ?? [],
            ];
        });
    }

    private function findActiveOrAny(int $tenantId, string $id): MassDayOverride
    {
        return MassDayOverride::query()
            ->with('slots')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->firstOrFail();
    }

    /**
     * @param  list<array<string, mixed>>  $slots
     * @return list<array{celebrated_at: string, place: ?string, celebrant_name: ?string, slot_id?: string}>
     */
    private function normalizeSlots(array $slots): array
    {
        $normalized = [];
        foreach ($slots as $slot) {
            $time = $this->normalizeTime((string) ($slot['celebrated_at'] ?? ''));
            if ($time === null) {
                throw ValidationException::withMessages([
                    'slots' => 'Each Mass needs a valid time.',
                ]);
            }
            $normalized[] = [
                'slot_id' => isset($slot['slot_id']) ? (string) $slot['slot_id'] : null,
                'celebrated_at' => $time,
                'place' => $slot['place'] ?? null,
                'celebrant_name' => $slot['celebrant_name'] ?? null,
            ];
        }

        return $normalized;
    }

    /**
     * @param  list<array<string, mixed>>  $slots
     */
    private function assertSlotsValid(string $mode, bool $closes, array $slots): void
    {
        if ($slots === [] && ! ($mode === 'replace' && $closes)) {
            throw ValidationException::withMessages([
                'slots' => 'Add at least one Mass time, or mark that regular Masses are closed on this date.',
            ]);
        }
    }

    private function assertNoSupplementDuplicates(int $tenantId, string $overrideOn, MassDayOverride $override): void
    {
        $date = Carbon::parse($overrideOn)->startOfDay();
        $base = $this->expectedBuilder->buildScheduleOnlyForTenantDate($tenantId, $date);
        $baseKeys = [];
        foreach ($base as $item) {
            $time = substr($item['celebrated_at'], 0, 5);
            $baseKeys[$time.'|'.MassSchedulePlaceKey::fromPlace($item['place'])] = true;
        }

        foreach ($override->slots as $slot) {
            $time = substr((string) $slot->celebrated_at, 0, 5);
            $key = $time.'|'.$slot->place_key;
            if (isset($baseKeys[$key])) {
                throw ValidationException::withMessages([
                    'slots' => 'This Mass is already on the regular schedule.',
                ]);
            }
        }
    }

    private function normalizeTime(string $time): ?string
    {
        $time = trim($time);
        if (preg_match('/^\d{2}:\d{2}$/', $time)) {
            return $time.':00';
        }
        if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
            return $time;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $reconcile
     * @return array<string, int>
     */
    private function countsOnly(array $reconcile): array
    {
        unset($reconcile['conflicts']);

        return array_map('intval', $reconcile);
    }

    /**
     * @return array<string, mixed>
     */
    private function toArray(MassDayOverride $override): array
    {
        return [
            'id' => $override->id,
            'override_on' => $override->override_on?->format('Y-m-d'),
            'mode' => $override->mode,
            'closes_regular_masses' => (bool) $override->closes_regular_masses,
            'label' => $override->label,
            'status' => $override->status,
            'slots' => $override->slots->sortBy('sort_order')->map(fn (MassDayOverrideSlot $slot) => [
                'slot_id' => $slot->slot_id,
                'celebrated_at' => substr((string) $slot->celebrated_at, 0, 5),
                'place' => $slot->place,
                'celebrant_name' => $slot->celebrant_name,
            ])->values()->all(),
        ];
    }
}
