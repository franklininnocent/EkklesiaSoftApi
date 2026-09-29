<?php

namespace Modules\Subscriptions\Services;

use Illuminate\Support\Str;
use Modules\Authentication\Models\Role;
use Modules\Authentication\Models\User;
use Modules\Subscriptions\Models\SubscriptionCatalogAudit;
use Modules\Tenants\Models\TenantSubscriptionAudit;
use Modules\Tenants\Services\PlatformAuditLogger;
use Throwable;

/**
 * Writes catalog audits (subscription_catalog_audits) and tenant audits
 * (existing tenant_subscription_audits) plus the platform audit stream.
 */
class SubscriptionAuditService
{
    public const CATEGORY = 'subscriptions';

    public function __construct(private readonly PlatformAuditLogger $platformAudit) {}

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function catalog(
        string $entityType,
        ?int $entityId,
        string $operation,
        ?array $before,
        ?array $after,
        ?User $actor = null,
        ?string $reason = null
    ): void {
        $actor ??= $this->currentActor();

        SubscriptionCatalogAudit::query()->create([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'operation' => $operation,
            'actor_id' => $actor?->id,
            'actor_role' => self::actorRole($actor),
            'reason' => $reason !== null ? Str::limit($reason, 500, '') : null,
            'before_state' => $before,
            'after_state' => $after,
            'correlation_id' => $this->correlationId(),
            'ip_address' => $this->ip(),
            'user_agent' => $this->userAgent(),
            'created_at' => now(),
        ]);

        $this->platform($operation, null, $actor?->id, [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function tenant(
        int $tenantId,
        string $operation,
        array $before,
        array $after,
        string $source,
        ?string $reason = null,
        ?int $actorId = null,
        ?string $actorRole = null
    ): void {
        if ($actorId === null && $actorRole === null) {
            $actor = $this->currentActor();
            $actorId = $actor?->id;
            $actorRole = self::actorRole($actor);
        }

        TenantSubscriptionAudit::query()->create([
            'tenant_id' => $tenantId,
            'actor_id' => $actorId,
            'actor_role' => $actorRole,
            'operation' => $operation,
            'source' => Str::limit($source, 32, ''),
            'reason' => $reason,
            'before_state' => $before,
            'after_state' => $after,
            'correlation_id' => $this->correlationId(),
            'ip_address' => $this->ip(),
            'user_agent' => $this->userAgent(),
            'created_at' => now(),
        ]);

        $this->platform($operation, $tenantId, $actorId, ['source' => $source]);
    }

    /**
     * Security-relevant denials (unauthorised access, limit rejections).
     *
     * @param  array<string, mixed>  $metadata
     */
    public function denial(string $event, ?int $tenantId, array $metadata = [], int $httpStatus = 403): void
    {
        $this->platform($event, $tenantId, $this->currentActor()?->id, $metadata, $httpStatus);
    }

    public static function actorRole(?User $actor): ?string
    {
        if (! $actor) {
            return null;
        }
        if ($actor->isSuperAdmin()) {
            return Role::SUPER_ADMIN;
        }
        if ($actor->isEkklesiaAdmin()) {
            return Role::EKKLESIA_ADMIN;
        }

        return $actor->role?->name ?? ($actor->isTenantAdmin() ? 'Administrator' : null);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function platform(string $event, ?int $tenantId, ?int $actorId, array $metadata, ?int $httpStatus = null): void
    {
        try {
            $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();
            $this->platformAudit->record(
                self::CATEGORY,
                'subscriptions.'.$event,
                $tenantId,
                $actorId,
                $httpStatus,
                $request?->method(),
                $request?->path(),
                $metadata
            );
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function currentActor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    private function correlationId(): ?string
    {
        $id = request()->header('X-Request-Id') ?: request()->header('X-Correlation-Id');

        return $id ? Str::limit((string) $id, 64, '') : null;
    }

    private function ip(): ?string
    {
        return app()->runningInConsole() && ! app()->runningUnitTests() ? null : request()->ip();
    }

    private function userAgent(): ?string
    {
        $agent = request()->userAgent();

        return $agent ? Str::limit($agent, 255, '') : null;
    }
}
