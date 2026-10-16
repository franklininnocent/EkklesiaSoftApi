<?php

namespace Modules\Donations\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Modules\Donations\Models\DonationSetting;
use Modules\Tenants\Models\Tenant;

class FinancialLlmAdapterService
{
    /**
     * @param array<string, mixed> $baseline
     * @return array<string, mixed>
     */
    public function enrich(int $tenantId, string $prompt, array $baseline): array
    {
        if (!$this->isEnabledForTenant($tenantId)) {
            return $baseline;
        }

        $apiKey = (string) config('financial_ai.llm.api_key', '');
        if ($apiKey === '') {
            return $baseline;
        }

        try {
            $tenantName = Tenant::query()->whereKey($tenantId)->value('name') ?? 'Parish';
            $systemPrompt = implode("\n", [
                'You are a church financial assistant for parish staff.',
                'Rewrite the baseline answer in warm, plain language suitable for clergy and secretaries.',
                'Keep numbers exact. Do not invent families, amounts, or dates.',
                'Return only the rewritten answer text.',
            ]);

            $userPrompt = implode("\n\n", [
                "Parish: {$tenantName}",
                "User question: {$prompt}",
                'Baseline intent: ' . ($baseline['intent'] ?? 'help'),
                'Baseline answer: ' . ($baseline['answer'] ?? ''),
                'Recommended actions: ' . implode('; ', $baseline['recommended_actions'] ?? []),
            ]);

            $response = Http::withToken($apiKey)
                ->timeout((int) config('financial_ai.llm.timeout', 20))
                ->acceptJson()
                ->post(rtrim((string) config('financial_ai.llm.base_url'), '/') . '/chat/completions', [
                    'model' => config('financial_ai.llm.model', 'gpt-4o-mini'),
                    'temperature' => 0.2,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                ]);

            if (!$response->successful()) {
                Log::warning('Financial LLM adapter request failed', [
                    'tenant_id' => $tenantId,
                    'status' => $response->status(),
                ]);

                return $baseline;
            }

            $answer = trim((string) data_get($response->json(), 'choices.0.message.content', ''));
            if ($answer === '') {
                return $baseline;
            }

            $baseline['answer'] = $answer;
            $baseline['engine'] = 'llm_v1';
            $baseline['llm'] = [
                'provider' => config('financial_ai.llm.provider', 'openai'),
                'model' => config('financial_ai.llm.model', 'gpt-4o-mini'),
            ];

            return $baseline;
        } catch (\Throwable $exception) {
            Log::warning('Financial LLM adapter exception', [
                'tenant_id' => $tenantId,
                'message' => $exception->getMessage(),
            ]);

            return $baseline;
        }
    }

    public function isEnabledForTenant(int $tenantId): bool
    {
        if (!(bool) config('financial_ai.llm.enabled', false)) {
            return false;
        }

        $settings = DonationSetting::forTenant($tenantId)->first();
        $metadata = $settings?->metadata ?? [];

        if (array_key_exists('financial_ai_llm_enabled', $metadata)) {
            return (bool) $metadata['financial_ai_llm_enabled'];
        }

        return true;
    }
}
