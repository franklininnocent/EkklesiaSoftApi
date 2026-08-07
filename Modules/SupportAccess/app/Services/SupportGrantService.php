<?php

namespace Modules\SupportAccess\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Modules\SupportAccess\Models\SupportAccessGrant;
use Modules\Tenants\Models\Tenant;
use Modules\Tenants\Support\SupportSessionMode;
use RuntimeException;

class SupportGrantService
{
    /**
     * @param  array{tenant_id:int,allowed_mode:string,starts_at:string,ends_at:string,note?:string|null,max_sessions?:int|null}  $payload
     */
    public function create(Authenticatable $actor, array $payload): SupportAccessGrant
    {
        if (! $this->actorHasPermission($actor, 'support.grants.manage')) {
            throw new RuntimeException('Missing permission to manage support access grants.');
        }

        return $this->persistCreate($actor, $payload);
    }

    /**
     * Parish self-serve: always scoped to the caller's effective tenant.
     *
     * @param  array{allowed_mode:string,starts_at:string,ends_at:string,note?:string|null,max_sessions?:int|null}  $payload
     */
    public function createForOwnTenant(Authenticatable $actor, int $tenantId, array $payload): SupportAccessGrant
    {
        if (! $this->actorHasPermission($actor, 'support.grants.parish.manage')) {
            throw new RuntimeException('Missing permission to manage parish support access windows.');
        }

        $payload['tenant_id'] = $tenantId;

        return $this->persistCreate($actor, $payload);
    }

    public function revoke(Authenticatable $actor, string $grantId): SupportAccessGrant
    {
        if (! $this->actorHasPermission($actor, 'support.grants.manage')) {
            throw new RuntimeException('Missing permission to revoke support access grants.');
        }

        return $this->persistRevoke($grantId);
    }

    public function revokeForOwnTenant(Authenticatable $actor, int $tenantId, string $grantId): SupportAccessGrant
    {
        if (! $this->actorHasPermission($actor, 'support.grants.parish.manage')) {
            throw new RuntimeException('Missing permission to revoke parish support access windows.');
        }

        /** @var SupportAccessGrant $grant */
        $grant = SupportAccessGrant::query()->findOrFail($grantId);
        if ((int) $grant->tenant_id !== $tenantId) {
            throw new RuntimeException('Grant does not belong to this tenant.');
        }

        return $this->persistRevoke($grantId);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters, int $perPage = 30): LengthAwarePaginator
    {
        $this->expireStale();

        $query = SupportAccessGrant::query()
            ->with([
                'tenant:id,name,slug,tenant_tier,active',
                'grantedBy:id,name,email',
            ])
            ->orderByDesc('starts_at');

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['tenant_id'])) {
            $query->where('tenant_id', (int) $filters['tenant_id']);
        }

        return $query->paginate($perPage);
    }

    public function findActiveCovering(int $tenantId, SupportSessionMode $mode): ?SupportAccessGrant
    {
        $this->expireStale();

        $now = now();

        /** @var SupportAccessGrant|null $grant */
        $grant = SupportAccessGrant::query()
            ->where('tenant_id', $tenantId)
            ->where('status', SupportAccessGrant::STATUS_ACTIVE)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>', $now)
            ->where(function ($q) use ($mode): void {
                $q->where('allowed_mode', SupportAccessGrant::MODE_ANY)
                    ->orWhere('allowed_mode', $mode->value);
            })
            ->where(function ($q): void {
                $q->whereNull('max_sessions')
                    ->orWhereColumn('sessions_used', '<', 'max_sessions');
            })
            ->orderBy('ends_at')
            ->first();

        return $grant;
    }

    public function incrementUsage(SupportAccessGrant $grant): void
    {
        $grant->sessions_used = (int) $grant->sessions_used + 1;
        $grant->save();
    }

    public function expireStale(): void
    {
        SupportAccessGrant::query()
            ->where('status', SupportAccessGrant::STATUS_ACTIVE)
            ->where('ends_at', '<=', now())
            ->update(['status' => SupportAccessGrant::STATUS_EXPIRED]);
    }

    /**
     * @param  array{tenant_id:int,allowed_mode:string,starts_at:string,ends_at:string,note?:string|null,max_sessions?:int|null}  $payload
     */
    private function persistCreate(Authenticatable $actor, array $payload): SupportAccessGrant
    {
        $tenant = Tenant::query()->find($payload['tenant_id']);
        if (! $tenant || ! $tenant->active) {
            throw new RuntimeException('Target tenant is not available for a support grant.');
        }

        $starts = \Carbon\Carbon::parse($payload['starts_at']);
        $ends = \Carbon\Carbon::parse($payload['ends_at']);
        if ($ends->lte($starts)) {
            throw new RuntimeException('Grant end time must be after start time.');
        }

        $allowed = $payload['allowed_mode'];
        $validModes = [
            SupportSessionMode::Readonly->value,
            SupportSessionMode::Standard->value,
            SupportSessionMode::Emergency->value,
            SupportAccessGrant::MODE_ANY,
        ];
        if (! in_array($allowed, $validModes, true)) {
            throw new RuntimeException('Invalid grant allowed_mode.');
        }

        $this->expireStale();

        $grant = SupportAccessGrant::query()->create([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenant->id,
            'granted_by_user_id' => (int) $actor->getAuthIdentifier(),
            'allowed_mode' => $allowed,
            'starts_at' => $starts,
            'ends_at' => $ends,
            'status' => SupportAccessGrant::STATUS_ACTIVE,
            'note' => $payload['note'] ?? null,
            'max_sessions' => isset($payload['max_sessions']) ? max(1, (int) $payload['max_sessions']) : null,
            'sessions_used' => 0,
        ]);

        return $grant->load([
            'tenant:id,name,slug,tenant_tier,active',
            'grantedBy:id,name,email',
        ]);
    }

    private function persistRevoke(string $grantId): SupportAccessGrant
    {
        /** @var SupportAccessGrant $grant */
        $grant = SupportAccessGrant::query()->findOrFail($grantId);

        if ($grant->status !== SupportAccessGrant::STATUS_ACTIVE) {
            throw new RuntimeException('Only active grants can be revoked.');
        }

        $grant->status = SupportAccessGrant::STATUS_REVOKED;
        $grant->save();

        return $grant->load([
            'tenant:id,name,slug,tenant_tier,active',
            'grantedBy:id,name,email',
        ]);
    }

    private function actorHasPermission(Authenticatable $actor, string $permission): bool
    {
        if (method_exists($actor, 'isSuperAdmin') && $actor->isSuperAdmin()) {
            return true;
        }

        return method_exists($actor, 'hasPermission') && $actor->hasPermission($permission);
    }
}
