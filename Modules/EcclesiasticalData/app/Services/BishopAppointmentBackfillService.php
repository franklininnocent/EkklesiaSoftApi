<?php

namespace Modules\EcclesiasticalData\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\EcclesiasticalData\Support\AppointmentEndReason;
use Modules\EcclesiasticalData\Support\AppointmentStatus;
use Modules\EcclesiasticalData\Support\BishopNameNormalizer;
use Modules\EcclesiasticalData\Support\CanonicalRole;
use Modules\EcclesiasticalData\Support\DioceseLeadershipState;

class BishopAppointmentBackfillService
{
    public function __construct(
        private readonly BishopNameNormalizer $nameNormalizer,
    ) {}
    public function backfillMissingAppointments(): int
    {
        if (! DB::getSchemaBuilder()->hasTable('bishop_appointments')) {
            return 0;
        }

        $titleMap = DB::table('ecclesiastical_titles')->pluck('title', 'id');
        $created = 0;

        $bishops = DB::table('bishops')
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();

        foreach ($bishops as $bishop) {
            $existing = DB::table('bishop_appointments')
                ->where('bishop_id', $bishop->id)
                ->where('diocese_id', $bishop->archdiocese_id)
                ->whereNull('deleted_at')
                ->exists();

            if ($existing) {
                continue;
            }

            $titleName = $bishop->ecclesiastical_title_id
                ? ($titleMap[$bishop->ecclesiastical_title_id] ?? null)
                : null;

            $canonicalRole = CanonicalRole::fromEcclesiasticalTitle($titleName)->value;
            $appointedDate = $bishop->appointed_date ?? $bishop->ordained_bishop_date ?? now()->toDateString();
            $isCurrent = (bool) $bishop->is_current;

            DB::table('bishop_appointments')->insert([
                'id' => (string) Str::uuid(),
                'bishop_id' => $bishop->id,
                'diocese_id' => $bishop->archdiocese_id,
                'ecclesiastical_title_id' => $bishop->ecclesiastical_title_id,
                'canonical_role' => $canonicalRole,
                'appointed_date' => $appointedDate,
                'announced_date' => $bishop->appointed_date,
                'effective_date' => $appointedDate,
                'ordained_date' => $bishop->ordained_bishop_date,
                'installed_date' => $bishop->appointed_date,
                'ended_date' => $bishop->retired_date,
                'end_reason' => $this->resolveEndReason($bishop->status),
                'is_current' => $isCurrent,
                'appointment_status' => $this->resolveAppointmentStatus($bishop, $isCurrent),
                'appointment_details' => null,
                'metadata' => null,
                'version' => 1,
                'source_type' => 'migration_backfill',
                'source_reference' => 'backfill_bishop_appointments_from_bishops',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($bishop->full_name && ! $bishop->normalized_name) {
                DB::table('bishops')
                    ->where('id', $bishop->id)
                    ->update(['normalized_name' => $this->nameNormalizer->normalize($bishop->full_name)]);
            }

            $created++;
        }

        $this->refreshDioceseLeadershipStates();

        return $created;
    }

    public function refreshDioceseLeadershipStates(): void
    {
        if (! DB::getSchemaBuilder()->hasColumn('archdioceses', 'leadership_state')) {
            return;
        }

        foreach (DB::table('archdioceses')->pluck('id') as $dioceseId) {
            $this->refreshDioceseLeadershipState((int) $dioceseId);
        }
    }

    public function refreshDioceseLeadershipState(int $dioceseId): void
    {
        if (! DB::getSchemaBuilder()->hasColumn('archdioceses', 'leadership_state')) {
            return;
        }

        $hasCurrentOrdinary = DB::table('bishop_appointments')
            ->where('diocese_id', $dioceseId)
            ->where('is_current', true)
            ->whereNull('deleted_at')
            ->whereIn('canonical_role', CanonicalRole::ordinaryRoles())
            ->exists();

        $hasAdministrator = DB::table('bishop_appointments')
            ->where('diocese_id', $dioceseId)
            ->where('is_current', true)
            ->whereNull('deleted_at')
            ->whereIn('canonical_role', [
                CanonicalRole::ApostolicAdministrator->value,
                CanonicalRole::DiocesanAdministrator->value,
            ])
            ->exists();

        $state = match (true) {
            $hasCurrentOrdinary => DioceseLeadershipState::Occupied->value,
            $hasAdministrator => DioceseLeadershipState::Administered->value,
            default => DioceseLeadershipState::Vacant->value,
        };

        DB::table('archdioceses')
            ->where('id', $dioceseId)
            ->update(['leadership_state' => $state]);
    }

    private function resolveAppointmentStatus(object $bishop, bool $isCurrent): string
    {
        if ($bishop->retired_date || in_array($bishop->status, ['retired', 'deceased', 'transferred'], true)) {
            return AppointmentStatus::Ended->value;
        }

        if ($isCurrent) {
            return AppointmentStatus::Current->value;
        }

        return AppointmentStatus::Ended->value;
    }

    private function resolveEndReason(?string $status): ?string
    {
        return match ($status) {
            'retired', 'emeritus' => AppointmentEndReason::Retirement->value,
            'deceased' => AppointmentEndReason::Death->value,
            'transferred' => AppointmentEndReason::Transfer->value,
            default => null,
        };
    }
}

