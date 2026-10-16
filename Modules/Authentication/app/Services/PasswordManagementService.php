<?php

namespace Modules\Authentication\Services;

use App\Services\TokenService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Authentication\Models\PasswordHistory;
use Modules\Authentication\Models\User;
use Modules\Authentication\Support\PasswordPolicy;
use Modules\Tenants\Services\PlatformAuditLogger;
use Modules\Tenants\Support\TenantContext;

class PasswordManagementService
{
    public function __construct(
        private readonly TokenService $tokenService,
        private readonly PlatformAuditLogger $auditLogger,
        private readonly PasswordAuthorizationService $authorization,
    ) {
    }

    /**
     * @return array{access_token: string, refresh_token: string, expiry_time: string, token_type: string}
     */
    public function changeOwnPassword(User $actor, string $currentPassword, string $newPassword): array
    {
        if (! $this->authorization->canChangeOwnPassword($actor)) {
            throw new \RuntimeException('You are not allowed to change your password.', 403);
        }

        return DB::transaction(function () use ($actor, $currentPassword, $newPassword) {
            /** @var User $locked */
            $locked = User::query()->whereKey($actor->id)->lockForUpdate()->firstOrFail();

            if (! Hash::check($currentPassword, $locked->password)) {
                $this->audit('auth', 'user.password_change_failed', $locked, [
                    'reason' => 'invalid_current_password',
                ]);

                throw new \RuntimeException('Current password is incorrect.', 422);
            }

            $this->assertPasswordPolicy($locked, $newPassword, true);

            $this->applyPasswordHash($locked, $newPassword, false);
            $locked->save();

            $this->recordHistory($locked);

            $tokens = $this->rotateTokens($locked);

            $this->audit('auth', 'user.password_changed', $locked, [
                'result' => 'success',
            ]);

            return $tokens;
        });
    }

    /**
     * @return array{temporary_password: string}
     */
    public function adminResetPassword(User $actor, User $target): array
    {
        if (! $this->authorization->canResetPassword($actor, $target)) {
            $this->audit('auth', 'user.password_reset_denied', $target, [
                'target_user_id' => $target->id,
                'result' => 'denied',
            ], $actor);

            throw new \RuntimeException('You do not have permission to reset this user\'s password.', 403);
        }

        $temporaryPassword = Str::password(20);

        DB::transaction(function () use ($target, $temporaryPassword) {
            /** @var User $locked */
            $locked = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();

            $this->applyPasswordHash($locked, $temporaryPassword, true);
            $locked->save();

            $this->recordHistory($locked);
            $this->tokenService->revokeAllTokens($locked->id);
        });

        $this->audit('auth', 'user.password_reset', $target, [
            'target_user_id' => $target->id,
            'result' => 'success',
        ], $actor);

        return [
            'temporary_password' => $temporaryPassword,
        ];
    }

    public function resetFromRecoveryAuthorization(User $user, string $newPassword): void
    {
        DB::transaction(function () use ($user, $newPassword) {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $this->assertPasswordPolicy($locked, $newPassword, true);
            $this->applyPasswordHash($locked, $newPassword, false);
            $locked->save();

            $this->recordHistory($locked);
            $this->tokenService->revokeAllTokens($locked->id);
        });
    }

    public function setInitialPassword(User $user, string $plainPassword, bool $forceChange = true): void
    {
        $this->applyPasswordHash($user, $plainPassword, $forceChange);
    }

    public function applyRecoveryTemporaryPassword(User $user, #[\SensitiveParameter] string $temporaryPassword): void
    {
        DB::transaction(function () use ($user, $temporaryPassword) {
            /** @var User $locked */
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $this->applyPasswordHash($locked, $temporaryPassword, true);
            $locked->save();

            $this->recordHistory($locked);
            $this->tokenService->revokeAllTokens($locked->id);
        });
    }

    public function recordPasswordHistory(User $user): void
    {
        $this->recordHistory($user);
    }

    private function applyPasswordHash(User $user, string $plainPassword, bool $forceChange): void
    {
        $user->password = Hash::make($plainPassword);
        $user->force_password_change = $forceChange;
        $user->password_changed_at = now();
    }

    private function assertPasswordPolicy(User $user, string $newPassword, bool $rejectCurrentMatch): void
    {
        if ($rejectCurrentMatch && Hash::check($newPassword, $user->password)) {
            throw new \RuntimeException('New password must differ from your current password.', 422);
        }

        $recentHashes = PasswordHistory::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(PasswordPolicy::HISTORY_LIMIT)
            ->pluck('password_hash');

        foreach ($recentHashes as $hash) {
            if (Hash::check($newPassword, $hash)) {
                throw new \RuntimeException('You cannot reuse a recent password.', 422);
            }
        }
    }

    private function recordHistory(User $user): void
    {
        PasswordHistory::query()->create([
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'password_hash' => $user->password,
            'created_at' => now(),
        ]);

        $staleIds = PasswordHistory::query()
            ->where('user_id', $user->id)
            ->whereNotIn('id', PasswordHistory::query()
                ->where('user_id', $user->id)
                ->orderByDesc('created_at')
                ->limit(PasswordPolicy::HISTORY_LIMIT)
                ->pluck('id'))
            ->pluck('id');

        if ($staleIds->isNotEmpty()) {
            PasswordHistory::query()->whereIn('id', $staleIds)->delete();
        }
    }

    /**
     * @return array{access_token: string, refresh_token: string, expiry_time: string, token_type: string}
     */
    private function rotateTokens(User $user): array
    {
        $this->tokenService->revokeAllTokens($user->id);
        $tokens = $this->tokenService->createTokens($user, null, [], null, 'password_change');

        return [
            'access_token' => $tokens['access_token_string'],
            'refresh_token' => $tokens['refresh_token_string'],
            'expiry_time' => $tokens['access_token']->expires_at->toIso8601String(),
            'token_type' => 'Bearer',
        ];
    }

    private function audit(
        string $category,
        string $event,
        User $target,
        array $metadata,
        ?User $actor = null,
    ): void {
        $actor = $actor ?? $target;
        $context = app(TenantContext::class);

        $this->auditLogger->record(
            category: $category,
            event: $event,
            tenantId: $target->tenant_id !== null ? (int) $target->tenant_id : null,
            actorUserId: (int) $actor->id,
            metadata: array_merge($metadata, [
                'target_user_id' => $target->id,
                'support_session_id' => $context->supportSessionId(),
            ]),
        );
    }
}
