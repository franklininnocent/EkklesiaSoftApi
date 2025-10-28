<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\TokenRepository;
use Laravel\Passport\Token;

// Test authentication with detailed debugging
Route::middleware(['api', \App\Http\Middleware\DebugAuthMiddleware::class, 'auth:api'])->get('/test-auth', function (Request $request) {
    $user = $request->user();
    
    return response()->json([
        'status' => 'authenticated',
        'user' => $user,
        'user_class' => get_class($user),
        'auth_guard' => auth()->getDefaultDriver(),
        'auth_check' => auth('api')->check(),
    ]);
});

// Debug route to manually verify token
Route::get('/debug-token', function (Request $request) {
    $authHeader = $request->header('Authorization');
    $bearer = null;
    
    if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
        $bearer = substr($authHeader, 7);
    }
    
    $info = [
        'has_auth_header' => !empty($authHeader),
        'auth_header_preview' => $authHeader ? substr($authHeader, 0, 50) . '...' : null,
        'bearer_token_length' => $bearer ? strlen($bearer) : 0,
        'auth_guard_default' => auth()->getDefaultDriver(),
        'auth_guard_api_check' => auth('api')->check(),
    ];
    
    if ($bearer) {
        // Try to find the token in database
        $tokenId = null;
        try {
            $jwt = (new \Lcobucci\JWT\Token\Parser(new \Lcobucci\JWT\Encoding\JoseEncoder()))->parse($bearer);
            $tokenId = $jwt->claims()->get('jti');
            $info['jwt_parsed'] = true;
            $info['token_id'] = $tokenId;
            
            $token = Token::find($tokenId);
            $info['token_found_in_db'] = !is_null($token);
            if ($token) {
                $info['token_revoked'] = $token->revoked;
                $info['token_user_id'] = $token->user_id;
                $info['token_expired'] = $token->expires_at->isPast();
            }
        } catch (\Exception $e) {
            $info['jwt_parse_error'] = $e->getMessage();
        }
    }
    
    return response()->json($info);
});

// Public test route
Route::get('/test-public', function () {
    return response()->json([
        'status' => 'public',
        'message' => 'This endpoint does not require authentication',
        'timestamp' => now()->toDateTimeString(),
    ]);
});

// Test Passport guard directly
Route::get('/test-guard', function (Request $request) {
    $token = $request->bearerToken();
    
    $info = [
        'has_bearer_token' => !empty($token),
        'token_length' => $token ? strlen($token) : 0,
    ];
    
    // Try to authenticate using the API guard
    try {
        $guard = auth()->guard('api');
        $info['guard_name'] = 'api';
        $info['guard_class'] = get_class($guard);
        
        // Try to get user via guard
        $user = $guard->user();
        $info['user_resolved'] = !is_null($user);
        if ($user) {
            $info['user_email'] = $user->email;
            $info['user_class'] = get_class($user);
        }
        
        $info['guard_check'] = $guard->check();
        
    } catch (\Exception $e) {
        $info['error'] = $e->getMessage();
        $info['trace'] = $e->getTraceAsString();
    }
    
    return response()->json($info);
});

