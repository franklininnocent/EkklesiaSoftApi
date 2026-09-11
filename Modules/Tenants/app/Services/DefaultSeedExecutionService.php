<?php

namespace Modules\Tenants\Services;

use Illuminate\Support\Facades\Auth;
use Modules\Authentication\Models\User;
use Modules\Tenants\DefaultSeeds\DefaultSeedRegistry;
use Modules\Tenants\DefaultSeeds\DefaultSeedStatus;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\TenantContext;
use Symfony\Component\HttpKernel\Exception\HttpException;

class DefaultSeedExecutionService
{
    public function __construct(
        private readonly DefaultSeedRegistry $registry,
        private readonly DefaultSeedAuthorizationService $authorization,
    ) {
    }

    /**
     * @param  list<string>  $ids
     * @return array{results: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function execute(array $ids, User $user, TenantContext $context): array
    {
        $tenantId = $this->authorization->assertCanExecute($user, $context);
        $tenant = Tenant::query()->findOrFail($tenantId);
        $userId = Auth::id() ? (int) Auth::id() : null;

        $ids = array_values(array_unique(array_filter($ids, fn ($id) => is_string($id) && $id !== '')));
        if ($ids === []) {
            throw new HttpException(422, 'Select at least one recommended list to add.');
        }

        $ordered = $this->resolveExecutionOrder($ids);
        $results = [];
        $summary = [
            'completed' => 0,
            'already_initialized' => 0,
            'partially_completed' => 0,
            'failed' => 0,
            'skipped' => 0,
            'unavailable' => 0,
        ];

        foreach ($ordered as $id) {
            if (! $this->registry->has($id)) {
                $results[] = $this->unavailableResult($id, 'That recommended list is not available.');
                $summary['unavailable']++;

                continue;
            }

            $definition = $this->registry->get($id);

            if (! $this->authorization->canRunDefinition($user, $tenant, $definition)) {
                $results[] = $this->unavailableResult($id, 'You cannot add this list for your church.');
                $summary['unavailable']++;

                continue;
            }

            try {
                $result = $definition->execute($tenantId, $userId);
            } catch (\Throwable $e) {
                report($e);
                $result = [
                    'id' => $id,
                    'display_name' => $definition->displayName(),
                    'result_status' => DefaultSeedStatus::RESULT_FAILED,
                    'created_count' => 0,
                    'skipped_count' => 0,
                    'failed_count' => 1,
                    'message' => 'Could not complete this operation. Please try again.',
                    'dependencies' => [],
                ];
            }

            $results[] = $result;
            $key = $result['result_status'] ?? DefaultSeedStatus::RESULT_FAILED;
            if (isset($summary[$key])) {
                $summary[$key]++;
            } else {
                $summary['failed']++;
            }
        }

        return [
            'results' => $results,
            'summary' => $summary,
        ];
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private function resolveExecutionOrder(array $ids): array
    {
        $resolved = [];
        $visiting = [];

        $visit = function (string $id) use (&$visit, &$resolved, &$visiting, $ids): void {
            if (in_array($id, $resolved, true)) {
                return;
            }

            if (isset($visiting[$id])) {
                return;
            }

            $visiting[$id] = true;

            if ($this->registry->has($id)) {
                foreach ($this->registry->get($id)->dependsOn() as $dependency) {
                    if (in_array($dependency, $ids, true)) {
                        $visit($dependency);
                    }
                }
            }

            unset($visiting[$id]);
            $resolved[] = $id;
        };

        foreach ($ids as $id) {
            $visit($id);
        }

        return $resolved;
    }

    /**
     * @return array<string, mixed>
     */
    private function unavailableResult(string $id, string $message): array
    {
        return [
            'id' => $id,
            'display_name' => $id,
            'result_status' => DefaultSeedStatus::RESULT_UNAVAILABLE,
            'created_count' => 0,
            'skipped_count' => 0,
            'failed_count' => 0,
            'message' => $message,
            'dependencies' => [],
        ];
    }
}
