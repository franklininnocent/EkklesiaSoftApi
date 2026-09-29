<?php

namespace Modules\MinistriesAssociations\DefaultSeeds\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Modules\MinistriesAssociations\Services\MinistriesAuditService;
use Modules\Tenants\Contracts\TenantDefaultSeedDefinition;
use Modules\Tenants\DefaultSeeds\DefaultSeedStatus;
use Modules\Tenants\DefaultSeeds\Support\CanonicalCodeStatusCalculator;

/**
 * Shared ADD_MISSING execution for Ministries taxonomy seed definitions.
 *
 * @template TModel of Model
 */
trait MinistriesTaxonomyDefaultSeedTrait
{
    abstract protected function modelClass(): string;

    abstract protected function auditEvent(): string;

    abstract protected function auditTargetType(): string;

    abstract protected function openRoute(): string;

    /**
     * @return list<array<string, mixed>>
     */
    abstract protected function canonicalDefaults(): array;

    /**
     * @param  array<string, mixed>  $defaults
     */
    abstract protected function createRow(int $tenantId, ?int $userId, array $defaults, int $displayOrder): Model;

    public function catalog(int $tenantId): array
    {
        $defaults = $this->canonicalDefaults();
        $status = CanonicalCodeStatusCalculator::forModel($this->modelClass(), $tenantId, $defaults);

        return $this->baseCatalog($status);
    }

    public function execute(int $tenantId, ?int $userId): array
    {
        $defaults = $this->canonicalDefaults();
        $before = CanonicalCodeStatusCalculator::forModel($this->modelClass(), $tenantId, $defaults);

        if ($before['missing_count'] === 0) {
            return $this->resultPayload(
                DefaultSeedStatus::RESULT_ALREADY_INITIALIZED,
                0,
                0,
                0,
                'Recommended '.$this->displayNameLower().' are already in place. Nothing new was added.',
            );
        }

        $created = 0;
        $skipped = 0;

        try {
            DB::transaction(function () use ($tenantId, $userId, $defaults, &$created, &$skipped) {
                $displayOrder = 0;
                foreach ($defaults as $row) {
                    if ($this->findExistingByCode($tenantId, $row['code'])) {
                        $skipped++;
                    } else {
                        $this->createRow($tenantId, $userId, $row, $displayOrder);
                        $created++;
                    }
                    $displayOrder++;
                }
            });
        } catch (\Throwable $e) {
            report($e);

            return $this->resultPayload(
                DefaultSeedStatus::RESULT_FAILED,
                0,
                0,
                1,
                'Could not add '.$this->displayNameLower().'. Please try again.',
            );
        }

        $this->auditService()->log(
            $tenantId,
            $this->auditEvent(),
            $this->auditTargetType(),
            (string) $tenantId,
            null,
            ['created' => $created, 'skipped' => $skipped],
        );

        $after = CanonicalCodeStatusCalculator::forModel($this->modelClass(), $tenantId, $defaults);
        $resultStatus = $after['missing_count'] === 0
            ? DefaultSeedStatus::RESULT_COMPLETED
            : DefaultSeedStatus::RESULT_PARTIALLY_COMPLETED;

        $message = $created === 0
            ? 'Nothing new was added.'
            : "Added {$created} recommended ".$this->displayNameLower().'. Existing parish labels were not changed.';

        return $this->resultPayload($resultStatus, $created, $skipped, 0, $message);
    }

    protected function auditService(): MinistriesAuditService
    {
        return app(MinistriesAuditService::class);
    }

    protected function displayNameLower(): string
    {
        return strtolower($this->displayName());
    }

    /**
     * @param  array<string, mixed>  $status
     * @return array<string, mixed>
     */
    protected function baseCatalog(array $status): array
    {
        return [
            'id' => $this->id(),
            'module' => $this->module(),
            'module_label' => $this->moduleLabel(),
            'display_name' => $this->displayName(),
            'description' => $this->description(),
            'sort_order' => $this->sortOrder(),
            'depends_on' => $this->dependsOn(),
            'required_permission' => $this->requiredPermission(),
            'feature_key' => $this->featureKey(),
            'execution_strategy' => 'ADD_MISSING_DEFAULTS',
            'supports_preview' => true,
            'open_route' => $this->openRoute(),
            'open_query' => $this->openQuery(),
            'expected_count' => $status['expected_count'],
            'matched_count' => $status['matched_count'],
            'missing_count' => $status['missing_count'],
            'has_other_records' => $status['has_other_records'],
            'missing_names' => $status['missing_names'],
            'status' => $status['status'],
            'impact' => $this->impactText($status),
        ];
    }

    /**
     * @return array<string, string>|null
     */
    protected function openQuery(): ?array
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $status
     */
    protected function impactText(array $status): string
    {
        $missing = (int) $status['missing_count'];
        if ($missing === 0) {
            return 'All recommended '.$this->displayNameLower().' are in place.';
        }

        $suffix = $status['has_other_records']
            ? ' Your existing parish labels stay as they are.'
            : '';

        return "Adds {$missing} recommended ".$this->displayNameLower().".{$suffix}";
    }

    /**
     * @return array<string, mixed>
     */
    protected function resultPayload(
        string $resultStatus,
        int $created,
        int $skipped,
        int $failed,
        string $message,
    ): array {
        return [
            'id' => $this->id(),
            'display_name' => $this->displayName(),
            'result_status' => $resultStatus,
            'created_count' => $created,
            'skipped_count' => $skipped,
            'failed_count' => $failed,
            'message' => $message,
            'dependencies' => [],
        ];
    }

    protected function findExistingByCode(int $tenantId, string $code): ?Model
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = $this->modelClass();

        $existing = $modelClass::withTrashed()
            ->where('tenant_id', $tenantId)
            ->where('code', $code)
            ->first();

        if ($existing && $existing->trashed()) {
            $existing->restore();
        }

        return $existing;
    }
}
