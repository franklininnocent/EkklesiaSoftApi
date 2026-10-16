<?php

namespace Modules\Sacraments\Services\Dashboard;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Modules\Sacraments\Models\Sacrament;
use Modules\Sacraments\Models\SacramentParticipant;

final class SacramentDashboardRecipientResolver
{
    private const MAX_REALISTIC_AGE = 120;

    public function resolveBirthDate(Sacrament $sacrament): ?CarbonInterface
    {
        if ($sacrament->recipient_birth_date !== null) {
            return $sacrament->recipient_birth_date->copy();
        }

        $sacrament->loadMissing([
            'person',
            'participants.familyMember.person',
            'participants.person',
        ]);

        if ($sacrament->person?->date_of_birth !== null) {
            return $sacrament->person->date_of_birth->copy();
        }

        $recipient = $this->recipientParticipant($sacrament);
        if ($recipient === null) {
            return null;
        }

        return $this->resolveParticipantBirthDate($recipient);
    }

    public function resolveGender(Sacrament $sacrament): string
    {
        if ($sacrament->recipient_gender !== null && $sacrament->recipient_gender !== '') {
            return $this->normalizeGender((string) $sacrament->recipient_gender);
        }

        $sacrament->loadMissing(['person', 'participants.familyMember', 'participants.person']);

        if ($sacrament->person?->gender) {
            return $this->normalizeGender((string) $sacrament->person->gender);
        }

        $recipient = $this->recipientParticipant($sacrament);
        if ($recipient?->external_gender) {
            return $this->normalizeGender((string) $recipient->external_gender);
        }

        if ($recipient?->familyMember?->gender) {
            return $this->normalizeGender((string) $recipient->familyMember->gender);
        }

        if ($recipient?->person?->gender) {
            return $this->normalizeGender((string) $recipient->person->gender);
        }

        return 'unknown';
    }

    public function resolveParticipantBirthDate(SacramentParticipant $participant): ?CarbonInterface
    {
        if ($participant->external_date_of_birth !== null) {
            return $participant->external_date_of_birth->copy();
        }

        $participant->loadMissing(['familyMember.person', 'person']);

        if ($participant->familyMember?->date_of_birth !== null) {
            return $participant->familyMember->date_of_birth->copy();
        }

        if ($participant->familyMember?->person?->date_of_birth !== null) {
            return $participant->familyMember->person->date_of_birth->copy();
        }

        if ($participant->person?->date_of_birth !== null) {
            return $participant->person->date_of_birth->copy();
        }

        $snapshot = is_array($participant->snapshot_json) ? $participant->snapshot_json : [];

        return $this->parseDate($snapshot['date_of_birth'] ?? null);
    }

    public function ageAtSacrament(?CarbonInterface $birthDate, ?CarbonInterface $administeredDate): ?float
    {
        if ($birthDate === null || $administeredDate === null) {
            return null;
        }

        $birth = $birthDate->copy()->startOfDay();
        $administered = $administeredDate->copy()->startOfDay();

        if ($birth->greaterThan($administered) || $birth->greaterThan(Carbon::today())) {
            return null;
        }

        $completedYears = $birth->diff($administered)->y;

        if ($completedYears < 0 || $completedYears > self::MAX_REALISTIC_AGE) {
            return null;
        }

        return round((float) $completedYears, 1);
    }

    public function normalizeGender(string $gender): string
    {
        $value = strtolower(trim($gender));

        return match ($value) {
            'm', 'male', 'man', 'boy' => 'male',
            'f', 'female', 'woman', 'girl' => 'female',
            'other', 'non_binary', 'non-binary', 'nb' => 'other',
            default => $value === '' ? 'unknown' : 'unknown',
        };
    }

    private function parseDate(mixed $value): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->copy();
        }

        if (! is_string($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    private function recipientParticipant(Sacrament $sacrament): ?SacramentParticipant
    {
        foreach ($sacrament->participants as $participant) {
            if (in_array($participant->role, ['recipient', 'bride', 'groom', 'candidate'], true)) {
                return $participant;
            }
        }

        return $sacrament->participants->first();
    }
}
