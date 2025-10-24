<?php

namespace Modules\Authentication\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Authentication\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthenticationController extends Controller
{
    /**
     * Register a new user.
     */
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email', // ✅ Make sure table name matches migration
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        // ✅ Generate Passport token
        $token = $user->createToken('API Token')->accessToken;

        return response()->json([
            'user' => $user,
            'access_token' => $token,
        ], 201);
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

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->createToken('API Token')->accessToken;

        return response()->json([
            'user' => $user,
            'access_token' => $token,
        ]);
    }

    /**
     * Logout and revoke tokens.
     */
    public function logout(Request $request)
    {
        $user = Auth::guard('api')->user(); // ✅ Always use the correct guard
        if ($user) {
            $user->tokens()->delete();
        }

        return response()->json(['message' => 'Logged out successfully']);
    }

    /**
     * Get authenticated user details.
     */
    public function user(Request $request)
    {
        $user = Auth::guard('api')->user(); // ✅ Guard aware
        return response()->json($user);
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
