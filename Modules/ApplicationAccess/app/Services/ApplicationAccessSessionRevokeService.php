<?php

namespace Modules\ApplicationAccess\Services;

use App\Services\TokenService;
use Illuminate\Support\Facades\DB;
use Modules\ApplicationAccess\Models\ApplicationAccessSession;
use Modules\ApplicationAccess\Repositories\ApplicationAccessSessionRepository;
use Modules\ApplicationAccess\Support\ApplicationAccessEnums;
use Modules\Authentication\Models\User;
use RuntimeException;

class ApplicationAccessSessionRevokeService
{
    public function __construct(
        private readonly TokenService $tokenService,
        private readonly ApplicationAccessSessionRepository $sessions,
        private readonly ApplicationAccessPrivilegedAudit $privilegedAudit,
    ) {}

    /**
     * @return array{session: ApplicationAccessSession, revoked_self: bool}
     */
    public function revoke(ApplicationAccessSession $session, User $actor): array
    {
        $tokenId = $session->oauth_access_token_id;
        if ($tokenId === null || $tokenId === '') {
            throw new RuntimeException('Session is not linked to an OAuth access token.');
        }

        $this->tokenService->revokeAccessToken($tokenId);

        $revoked = (bool) DB::table('oauth_access_tokens')
            ->where('id', $tokenId)
            ->value('revoked');

        if (! $revoked) {
            throw new RuntimeException('Failed to revoke access token.');
        }

        $session = $this->sessions->findById($session->id) ?? $session;
        if ($session->ended_at === null) {
            $session = $this->sessions->endSession(
                $session,
                ApplicationAccessEnums::SESSION_REVOKED,
                'revoked'
            );
        }

        $this->privilegedAudit->recordSessionRevoke($actor, $session);

        return [
            'session' => $session->refresh(),
            'revoked_self' => (int) $session->user_id === (int) $actor->id,
        ];
    }
}
