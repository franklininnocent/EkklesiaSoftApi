<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Client;
use Laravel\Passport\Token;
use Carbon\Carbon;

class TokenService
{

    /**
     * Create access and refresh tokens for a user
     */
    public function createTokens($user, $clientId = null, $scopes = [])
    {
        // Get or create password grant client
        $client = $this->getPasswordClient($clientId);

        if (!$client) {
            throw new \Exception('Password grant client not found. Please run: php artisan passport:install');
        }

        $accessTokenId = Str::uuid()->toString();
        $expiresAt = Carbon::now()->addHours(6);

        // Generate access token directly in database
        DB::table('oauth_access_tokens')->insert([
            'id' => $accessTokenId,
            'user_id' => $user->id,
            'client_id' => $client->id,
            'name' => 'API Token',
            'scopes' => json_encode($scopes),
            'revoked' => false,
            'expires_at' => $expiresAt,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $refreshTokenId = Str::uuid()->toString();
        $refreshExpiresAt = Carbon::now()->addDays(30);

        // Generate refresh token directly in database
        DB::table('oauth_refresh_tokens')->insert([
            'id' => $refreshTokenId,
            'access_token_id' => $accessTokenId,
            'revoked' => false,
            'expires_at' => $refreshExpiresAt,
        ]);

        // Create token objects for response
        $accessToken = (object)[
            'id' => $accessTokenId,
            'user_id' => $user->id,
            'client_id' => $client->id,
            'scopes' => $scopes,
            'expires_at' => $expiresAt,
        ];

        return [
            'access_token' => $accessToken,
            'refresh_token_string' => $refreshTokenId,
            'access_token_string' => $this->generateTokenString($accessToken, $client),
        ];
    }

    /**
     * Refresh an access token using a refresh token
     */
    public function refreshTokens($refreshTokenId)
    {
        // Find the refresh token
        $refreshToken = DB::table('oauth_refresh_tokens')
            ->where('id', $refreshTokenId)
            ->where('revoked', false)
            ->first();

        if (!$refreshToken) {
            throw new \Exception('Invalid or revoked refresh token');
        }

        // Check if refresh token is expired
        if (Carbon::parse($refreshToken->expires_at)->isPast()) {
            throw new \Exception('Refresh token has expired');
        }

        // Get the old access token
        $oldAccessToken = DB::table('oauth_access_tokens')
            ->where('id', $refreshToken->access_token_id)
            ->first();

        if (!$oldAccessToken) {
            throw new \Exception('Access token not found');
        }

        // Revoke old tokens
        DB::table('oauth_access_tokens')
            ->where('id', $refreshToken->access_token_id)
            ->update(['revoked' => true]);

        DB::table('oauth_refresh_tokens')
            ->where('id', $refreshTokenId)
            ->update(['revoked' => true]);

        // Get user
        $user = \Modules\Authentication\Models\User::find($oldAccessToken->user_id);

        if (!$user) {
            throw new \Exception('User not found');
        }

        // Create new tokens
        return $this->createTokens($user, $oldAccessToken->client_id);
    }

    /**
     * Revoke all tokens for a user
     */
    public function revokeAllTokens($userId)
    {
        DB::table('oauth_access_tokens')
            ->where('user_id', $userId)
            ->update(['revoked' => true]);

        DB::table('oauth_refresh_tokens')
            ->whereIn('access_token_id', function ($query) use ($userId) {
                $query->select('id')
                    ->from('oauth_access_tokens')
                    ->where('user_id', $userId);
            })
            ->update(['revoked' => true]);
    }

    /**
     * Get password grant client
     */
    protected function getPasswordClient($clientId = null)
    {
        if ($clientId) {
            return DB::table('oauth_clients')->where('id', $clientId)->first();
        }

        // Get the first password grant client using raw DB query to avoid Eloquent type issues
        $clients = DB::table('oauth_clients')
            ->where(function($query) {
                $query->where('revoked', false)
                      ->orWhereNull('revoked');
            })
            ->get();
        
        foreach ($clients as $client) {
            $grantTypes = json_decode($client->grant_types, true);
            if (is_array($grantTypes) && in_array('password', $grantTypes)) {
                return $client;
            }
        }

        return null;
    }

    /**
     * Generate JWT token string from access token
     */
    protected function generateTokenString($accessToken, $client)
    {
        $privateKey = file_get_contents(storage_path('oauth-private.key'));
        
        // Handle both objects and arrays from different sources
        $clientId = is_object($client) ? $client->id : $client['id'];
        $tokenId = is_object($accessToken) ? $accessToken->id : $accessToken['id'];
        $userId = is_object($accessToken) ? $accessToken->user_id : $accessToken['user_id'];
        $expiresAt = is_object($accessToken) ? $accessToken->expires_at : $accessToken['expires_at'];
        $scopes = is_object($accessToken) ? $accessToken->scopes : ($accessToken['scopes'] ?? []);
        
        // Convert Carbon to timestamp if needed
        $expiryTimestamp = $expiresAt instanceof \Carbon\Carbon 
            ? $expiresAt->timestamp 
            : (is_string($expiresAt) ? strtotime($expiresAt) : $expiresAt);
        
        $payload = [
            'aud' => $clientId,
            'jti' => $tokenId,
            'iat' => time(),
            'nbf' => time(),
            'exp' => $expiryTimestamp,
            'sub' => (string) $userId,
            'scopes' => $scopes,
        ];

        return \Firebase\JWT\JWT::encode($payload, $privateKey, 'RS256');
    }

    /**
     * Revoke a specific access token
     */
    public function revokeAccessToken($tokenId)
    {
        DB::table('oauth_access_tokens')
            ->where('id', $tokenId)
            ->update(['revoked' => true]);

        DB::table('oauth_refresh_tokens')
            ->where('access_token_id', $tokenId)
            ->update(['revoked' => true]);
    }
}

