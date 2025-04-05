<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\JsonResponse;
use Laravel\Sanctum\HasApiTokens;
use Carbon\Carbon;

class AuthenticatedSessionController extends Controller
{
    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): JsonResponse
    {
        // Attempt authentication
        if (!Auth::attempt($request->only('email', 'password'))) {
            return response()->json([
                'message' => 'Invalid credentials',
            ], 401);
        }

        // Get authenticated user
        $user = Auth::user();

        // Revoke old tokens (optional)
        $user->tokens()->delete();

        // Create new token for API authentication
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'User logged in successfully!',
            'user' => $user,
            'token' => $token,
        ], 200);
    }

    public function user(Request $request): JsonResponse
    {
        $user = Auth::user();

        // Get active subscriptions
        $activeSubscriptions = $user->subscriptions()
            ->where('expiry_date', '>', Carbon::today())->with('cors')->with('plan')
            ->get();

        return response()->json([
            'user' => $user,
            'subscriptions' => $activeSubscriptions,
        ], 200);
    }


    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): Response
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
