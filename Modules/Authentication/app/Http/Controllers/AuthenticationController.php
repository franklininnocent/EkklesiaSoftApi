<?php

namespace Modules\Authentication\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Authentication\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use App\Services\TokenService;

class AuthenticationController extends Controller
{
    protected $tokenService;

    public function __construct(TokenService $tokenService)
    {
        $this->tokenService = $tokenService;
    }

    /**
     * Register a new user.
     */
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
            'role_id' => 'nullable|exists:roles,id',
            'tenant_id' => 'nullable|integer',
        ]);

        // Default to EkklesiaUser role if not specified
        $roleId = $request->role_id ?? 4; // 4 = EkklesiaUser

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role_id' => $roleId,
            'tenant_id' => $request->tenant_id,
            'active' => 1, // Active by default
        ]);

        // Load role relationship
        $user->load('role');

        // Generate OAuth2 tokens with refresh token
        try {
            $tokens = $this->tokenService->createTokens($user);

            return response()->json([
                'access_token' => $tokens['access_token_string'],
                'refresh_token' => $tokens['refresh_token_string'],
                'expiry_time' => $tokens['access_token']->expires_at->toDateTimeString(),
                'user_id' => $user->id,
                'role_id' => $user->role_id,
                'token_type' => 'Bearer',
                'message' => 'Registration successful',
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Token generation failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Login user and return token.
     */
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        // Load user with role relationship
        $user = User::with('role')->where('email', $request->email)->first();

        // Check if user exists
        if (! $user) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Check password
        if (! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Check if user is active
        if ($user->active !== 1) {
            throw ValidationException::withMessages([
                'email' => ['Your account has been deactivated. Please contact support.'],
            ]);
        }

        // Check if user role is active
        if ($user->role && $user->role->active !== 1) {
            throw ValidationException::withMessages([
                'email' => ['Your role has been deactivated. Please contact support.'],
            ]);
        }

        // Generate OAuth2 tokens with refresh token
        try {
            $tokens = $this->tokenService->createTokens($user);

            return response()->json([
                'access_token' => $tokens['access_token_string'],
                'refresh_token' => $tokens['refresh_token_string'],
                'expiry_time' => $tokens['access_token']->expires_at->toDateTimeString(),
                'user_id' => $user->id,
                'role_id' => $user->role_id,
                'token_type' => 'Bearer',
                'message' => 'Login successful',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Token generation failed: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Refresh the user's access token using refresh token.
     */
    public function refresh(Request $request)
    {
        $request->validate([
            'refresh_token' => 'required|string',
        ]);

        try {
            // Refresh tokens using the refresh token
            $tokens = $this->tokenService->refreshTokens($request->refresh_token);

            return response()->json([
                'access_token' => $tokens['access_token_string'],
                'refresh_token' => $tokens['refresh_token_string'],
                'expiry_time' => $tokens['access_token']->expires_at->toDateTimeString(),
                'user_id' => $tokens['access_token']->user_id,
                'role_id' => \Modules\Authentication\Models\User::find($tokens['access_token']->user_id)->role_id,
                'token_type' => 'Bearer',
                'message' => 'Token refreshed successfully',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 401);
        }
    }

    /**
     * Logout and revoke tokens.
     */
    public function logout(Request $request)
    {
        $user = Auth::guard('api')->user();
        if ($user) {
            // Revoke all user tokens (access and refresh tokens)
            $this->tokenService->revokeAllTokens($user->id);
        }

        return response()->json(['message' => 'Logged out successfully']);
    }

    /**
     * Get authenticated user details.
     */
    public function user(Request $request)
    {
        $user = Auth::guard('api')->user();
        
        if ($user) {
            // Load role relationship
            $user->load('role');
            
            // Prepare user response with role information
            $userResponse = $user->toArray();
            $userResponse['role_name'] = $user->role ? $user->role->name : null;
            $userResponse['role_level'] = $user->role ? $user->role->level : null;
            $userResponse['is_super_admin'] = $user->isSuperAdmin();
            $userResponse['is_admin'] = $user->isAdmin();
            
            return response()->json($userResponse);
        }
        
        return response()->json(['message' => 'Unauthenticated'], 401);
    }

    /**
     * Get logged-in user details (new endpoint).
     * Returns structured user data with role information.
     */
    public function getUser(Request $request)
    {
        $user = Auth::guard('api')->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        // Load relationships: role, roles (multi-role), permissions, and tenant
        $user->load(['role', 'roles.permissions', 'permissions', 'tenant']);

        // Build user response with all relationships
        $userData = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'contact_number' => $user->contact_number,
            'user_type' => $user->user_type,
            'is_primary_admin' => $user->is_primary_admin ?? false,
            'tenant_id' => $user->tenant_id,
            'role_id' => $user->role_id, // Legacy
            'role_name' => $user->role ? $user->role->name : null, // Legacy
            'role_level' => $user->role ? $user->role->level : null, // Legacy
            'active' => $user->active,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
            'is_super_admin' => $user->isSuperAdmin(),
            'is_admin' => $user->isAdmin(),
            'has_ekklesia_role' => $user->hasEkklesiaRole(),
            // Multi-role support
            'roles' => $user->roles,
            'permissions' => $user->getAllPermissions()->values(),
            // Tenant relationship
            'tenant' => $user->tenant,
        ];

        return response()->json([
            'success' => true,
            'data' => $userData,
            'message' => 'User details retrieved successfully'
        ]);
    }

    /**
     * View methods (for Nwidart module - optional if you use API only)
     */
    public function index()
    {
        return view('authentication::index');
    }

    public function create()
    {
        return view('authentication::create');
    }

    public function store(Request $request) {}

    public function show($id)
    {
        return view('authentication::show');
    }

    public function edit($id)
    {
        return view('authentication::edit');
    }

    public function update(Request $request, $id) {}

    public function destroy($id) {}
}
