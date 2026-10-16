<?php

namespace Modules\EcclesiasticalData\Services;

use Illuminate\Support\Collection;
use Modules\EcclesiasticalData\Models\BishopManagement;
use Modules\EcclesiasticalData\Support\BishopNameNormalizer;

class BishopDuplicateDetectionService
{
    public function __construct(
        private readonly BishopNameNormalizer $nameNormalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $bishopData
     * @return Collection<int, BishopManagement>
     */
    public function findPotentialDuplicates(array $bishopData, ?int $excludeBishopId = null): Collection
    {
        $normalizedName = $this->nameNormalizer->normalize(
            $bishopData['full_name'] ?? $bishopData['normalized_name'] ?? null
        );

        if (! $normalizedName) {
            return collect();
        }

        $query = BishopManagement::query()
            ->where('normalized_name', $normalizedName);

        if ($excludeBishopId) {
            $query->where('id', '!=', $excludeBishopId);
        }

        $candidates = $query->get();

        return $candidates->filter(function (BishopManagement $bishop) use ($bishopData) {
            return $this->scoreMatch($bishop, $bishopData) >= 1;
        })->values();
    }

    /**
     * @param  array<string, mixed>  $bishopData
     * @return Collection<int, BishopManagement>
     */
    public function findHighConfidenceDuplicates(array $bishopData, ?int $excludeBishopId = null): Collection
    {
        return $this->findPotentialDuplicates($bishopData, $excludeBishopId)
            ->filter(fn (BishopManagement $bishop) => $this->scoreMatch($bishop, $bishopData) >= 3)
            ->values();
    }

    /**
     * @param  array<string, mixed>  $bishopData
     */
    public function hasHighConfidenceDuplicate(array $bishopData, ?int $excludeBishopId = null): bool
    {
        return $this->findHighConfidenceDuplicates($bishopData, $excludeBishopId)->isNotEmpty();
    }

    /**
     * @param  array<string, mixed>  $bishopData
     */
    private function scoreMatch(BishopManagement $bishop, array $bishopData): int
    {
        $score = 1;

        if (! empty($bishopData['date_of_birth'])
            && $this->formatDate($bishop->date_of_birth) === $this->formatDate($bishopData['date_of_birth'])) {
            $score += 2;
        }

        if (! empty($bishopData['ordained_priest_date'])
            && $this->formatDate($bishop->ordained_priest_date) === $this->formatDate($bishopData['ordained_priest_date'])) {
            $score++;
        }

        if (! empty($bishopData['ordained_bishop_date'])
            && $this->formatDate($bishop->ordained_bishop_date) === $this->formatDate($bishopData['ordained_bishop_date'])) {
            $score++;
        }

        return $score;
    }

    private function formatDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return (string) $value;
    }
}
