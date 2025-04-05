<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class RegisteredUserController extends Controller
{
    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): JsonResponse
    {
        Log::info('Registration received', ['method' => $request->method(), 'payload' => $request->all()]);

        $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:users',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        // Generate username from first and last name
        $userName = $this->generateUsername($request->first_name, $request->last_name);

        // Check if username already exists and generate a unique one if needed
        $originalUserName = $userName;
        $counter = 1;
        while (User::where('user_name', $userName)->exists()) {
            $userName = $originalUserName . $counter;
            $counter++;
        }

        $user = User::create([
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'email' => $request->email,
            'user_name' => $userName,
            'password' => Hash::make($request->password),
        ]);
        Log::info('User registered: ' . $user->id);

        // Fire Registered Event (Triggers Email Verification if enabled)
        event(new Registered($user));
        Log::info('Event Triggered');
        // Auto-login the user and create a Sanctum token
        Auth::login($user);
        Log::info('Logged in, About to create token');
        $token = $user->createToken('auth_token')->plainTextToken;
        Log::info('Response about to be sent');
        return response()->json([
            'message' => 'User registered successfully!',
            'user' => $user,
            'token' => $token,
        ], 201);
    }

    /**
     * Generate a username from first name with random characters.
     * 
     * @param string $firstName
     * @param string $lastName
     * @return string
     */
    private function generateUsername(string $firstName, string $lastName): string
    {
        // Convert to lowercase and remove any non-alphabetic characters
        $firstName = strtolower(preg_replace('/[^a-zA-Z]/', '', $firstName));

        // If first name is empty, use a default
        if (empty($firstName)) {
            $firstName = 'user';
        }

        // Get prefix from first name (3-5 characters)
        $prefixLength = min(5, max(3, strlen($firstName)));
        $prefix = substr($firstName, 0, $prefixLength);

        // Generate random characters
        $randomLength = 12 - strlen($prefix);
        $randomChars = '';

        // Define character sets
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';

        // Generate random characters
        for ($i = 0; $i < $randomLength; $i++) {
            $randomChars .= $lowercase[rand(0, strlen($lowercase) - 1)];
        }

        // Combine prefix and random characters
        $username = $prefix . $randomChars;

        return $username;
    }
}
