<?php

namespace Modules\ApplicationAccess\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\ApplicationAccess\Support\ApplicationAccessAuthorization;
use Modules\ApplicationAccess\Support\BearerTokenInspector;
use Modules\Authentication\Models\User;

class ApplicationAccessStreamAuthorizer
{
    public function __construct(
        private readonly BearerTokenInspector $tokenInspector,
    ) {}

    public function canStream(Request $request, User $user): bool
    {
        if ((int) $user->active !== 1) {
            return false;
        }

        if (! $user->hasEkklesiaRole()) {
            return false;
        }

        if (! ApplicationAccessAuthorization::hasAny($user, ['application_access.view'])) {
            return false;
        }

        $tokenId = $this->tokenInspector->extractAccessTokenId($request->bearerToken());
        if ($tokenId === null) {
            return true;
        }

        $revoked = DB::table('oauth_access_tokens')
            ->where('id', $tokenId)
            ->value('revoked');

        return $revoked === null ? true : ! (bool) $revoked;
    }
}
