<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use App\Models\User;
use App\Notifications\PasswordResetCodeNotification;
use Illuminate\Support\Facades\Cache;

class MobilePasswordResetController extends Controller
{
    /**
     * Send password reset code to user's email
     */
    public function sendResetLink(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
        ]);

        try {
            $user = User::where('email', $request->email)->first();
            $code = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            // Store the code in cache with 10 minutes expiration
            Cache::put('password_reset_code_' . $request->email, $code, now()->addMinutes(10));

            // Send the code via email
            $user->notify(new PasswordResetCodeNotification($code));

            Log::info('Password reset code sent', ['email' => $request->email]);
            return response()->json([
                'status' => 'success',
                'message' => 'Password reset code has been sent to your email'
            ]);
        } catch (\Exception $e) {
            Log::error('Error sending password reset code', [
                'email' => $request->email,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to send reset code'
            ], 500);
        }
    }

    /**
     * Verify reset code
     */
    public function verifyToken(Request $request)
    {
        $request->validate([
            'code' => ['required', 'string', 'size:6'],
            'email' => ['required', 'email'],
        ]);

        try {
            $storedCode = Cache::get('password_reset_code_' . $request->email);

            if (!$storedCode || $storedCode !== $request->code) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid or expired code'
                ], 400);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Code verified successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error verifying reset code', [
                'email' => $request->email,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to verify code'
            ], 500);
        }
    }

    /**
     * Reset password using verified code
     */
    public function resetPassword(Request $request)
    {
        $request->validate([
            'code' => ['required', 'string', 'size:6'],
            'email' => ['required', 'email'],
            'password' => ['required', 'min:8', 'confirmed'],
        ]);

        try {
            $storedCode = Cache::get('password_reset_code_' . $request->email);

            if (!$storedCode || $storedCode !== $request->code) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid or expired code'
                ], 400);
            }

            $user = User::where('email', $request->email)->first();

            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found'
                ], 404);
            }

            $user->forceFill([
                'password' => Hash::make($request->password)
            ])->save();

            // Clear the code from cache
            Cache::forget('password_reset_code_' . $request->email);

            Log::info('Password reset successful', ['email' => $request->email]);
            return response()->json([
                'status' => 'success',
                'message' => 'Password has been reset successfully'
            ]);
        } catch (\Exception $e) {
            Log::error('Error resetting password', [
                'email' => $request->email,
                'error' => $e->getMessage()
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to reset password'
            ], 500);
        }
    }
}
