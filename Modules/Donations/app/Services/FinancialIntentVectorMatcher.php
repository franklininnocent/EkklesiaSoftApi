<?php

namespace Modules\Donations\Services;

class FinancialIntentVectorMatcher
{
    /**
     * @var array<string, array<string, float>>
     */
    private const INTENT_VECTORS = [
        'overdue_families' => [
            'overdue' => 3.0,
            'outstanding' => 2.5,
            'due' => 2.0,
            'pending' => 1.5,
            'behind' => 2.0,
            'lagging' => 1.5,
            'attention' => 1.5,
            'family' => 1.0,
            'families' => 1.0,
            'mandatory' => 1.0,
            'contribution' => 1.0,
        ],
        'project_lagging' => [
            'project' => 3.0,
            'projects' => 3.0,
            'campaign' => 2.5,
            'building' => 2.0,
            'fund' => 1.5,
            'lag' => 2.5,
            'lagging' => 2.5,
            'behind' => 2.0,
            'target' => 1.5,
            'funding' => 2.0,
        ],
        'financial_summary' => [
            'summary' => 3.0,
            'health' => 2.5,
            'overview' => 2.5,
            'status' => 2.0,
            'how' => 1.0,
            'financial' => 2.0,
            'church' => 1.0,
            'parish' => 1.0,
        ],
        'collection_forecast' => [
            'forecast' => 3.5,
            'predict' => 3.0,
            'projection' => 3.0,
            'next' => 1.5,
            'month' => 2.0,
            'future' => 2.0,
            'trend' => 2.0,
            'expect' => 2.0,
            'estimate' => 2.5,
            'collection' => 1.5,
            'collections' => 1.5,
        ],
        'top_contributors' => [
            'top' => 2.5,
            'highest' => 2.5,
            'best' => 2.0,
            'contributor' => 3.0,
            'contributors' => 3.0,
            'donor' => 2.5,
            'donors' => 2.5,
            'paid' => 2.0,
            'giving' => 2.0,
            'family' => 1.0,
            'families' => 1.0,
        ],
        'compare_collections' => [
            'compare' => 3.0,
            'comparison' => 3.0,
            'versus' => 2.5,
            'vs' => 2.5,
            'growth' => 2.5,
            'month' => 2.0,
            'months' => 2.0,
            'change' => 2.0,
            'increase' => 2.0,
            'decrease' => 2.0,
            'collection' => 1.5,
        ],
        'whatsapp_outreach' => [
            'whatsapp' => 3.5,
            'whats' => 2.0,
            'message' => 2.0,
            'remind' => 2.5,
            'reminder' => 2.5,
            'outreach' => 3.0,
            'follow' => 2.0,
            'contact' => 2.0,
            'notify' => 2.0,
            'overdue' => 1.5,
            'families' => 1.0,
        ],
    ];

    /**
     * @return array{type: string, score: float, params: array<string, mixed>}
     */
    public function match(string $prompt): array
    {
        $promptVector = $this->vectorize($prompt);
        $bestType = 'help';
        $bestScore = 0.0;

        foreach (self::INTENT_VECTORS as $type => $intentVector) {
            $score = $this->cosineSimilarity($promptVector, $intentVector);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestType = $type;
            }
        }

        if ($bestScore < 0.18) {
            return ['type' => 'help', 'score' => $bestScore, 'params' => []];
        }

        return [
            'type' => $bestType,
            'score' => round($bestScore, 4),
            'params' => $this->extractParams($prompt, $bestType),
        ];
    }

    /**
     * @return array<string, float>
     */
    private function vectorize(string $prompt): array
    {
        $tokens = $this->tokenize($prompt);
        $vector = [];

        foreach ($tokens as $token) {
            $vector[$token] = ($vector[$token] ?? 0) + 1.0;
        }

        return $vector;
    }

    /**
     * @return array<int, string>
     */
    private function tokenize(string $prompt): array
    {
        $text = strtolower(trim($prompt));
        $text = preg_replace('/[^a-z0-9\s]/', ' ', $text) ?? $text;
        $parts = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter($parts, fn (string $part): bool => strlen($part) > 1));
    }

    /**
     * @param array<string, float> $left
     * @param array<string, float> $right
     */
    private function cosineSimilarity(array $left, array $right): float
    {
        $dot = 0.0;
        $leftNorm = 0.0;
        $rightNorm = 0.0;

        foreach ($left as $term => $weight) {
            $leftNorm += $weight * $weight;
            $dot += $weight * ($right[$term] ?? 0.0);
        }

        foreach ($right as $weight) {
            $rightNorm += $weight * $weight;
        }

        if ($leftNorm <= 0 || $rightNorm <= 0) {
            return 0.0;
        }

        return $dot / (sqrt($leftNorm) * sqrt($rightNorm));
    }

    /**
     * @return array<string, mixed>
     */
    private function extractParams(string $prompt, string $type): array
    {
        $params = [];

        if (preg_match('/(\d+)\s*(day|days)/', strtolower($prompt), $matches)) {
            $params['days_overdue'] = (int) $matches[1];
        }

        if (preg_match('/(\d+)\s*(month|months)/', strtolower($prompt), $matches)) {
            $params['months'] = (int) $matches[1];
        }

        if ($type === 'project_lagging' && !isset($params['days_overdue'])) {
            $params['days_overdue'] = 30;
        }

        if ($type === 'top_contributors' && preg_match('/top\s+(\d+)/', strtolower($prompt), $matches)) {
            $params['limit'] = (int) $matches[1];
        }

        return $params;
    }
}
