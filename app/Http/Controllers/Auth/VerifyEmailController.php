<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use App\Models\User;
use App\Mail\VerificationCodeMail;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(
                config('app.frontend_url') . '/dashboard?verified=1'
            );
        }

        if ($request->user()->markEmailAsVerified()) {
            event(new Verified($request->user()));
        }

        return redirect()->intended(
            config('app.frontend_url') . '/dashboard?verified=1'
        );
    }

    public function sendVerificationCode(Request $request)
    {
        $request->validate(['email' => 'required|email|exists:users,email']);

        // Generate a 4-digit verification code
        $code = rand(1000, 9999);

        // Store the code in cache for 10 minutes
        Cache::put('verification_code_' . $request->email, $code, 600);

        // Send the verification code via email
        Mail::to($request->email)->send(new VerificationCodeMail($code));

        return response()->json(['status' => 'success', 'message' => 'Verification code sent to your email.']);
    }

    public function verifyCode(Request $request)
    {
        $request->validate([
            'email' => 'required|email|exists:users,email',
            'code' => 'required|digits:4'
        ]);

        // Retrieve the code from cache
        $cachedCode = Cache::get('verification_code_' . $request->email);

        if ($cachedCode && $cachedCode == $request->code) {
            // Update the user's email_verified_at timestamp
            $user = User::where('email', $request->email)->first();
            $user->email_verified_at = now();
            $user->save();

            // Optionally, remove the code from cache
            Cache::forget('verification_code_' . $request->email);

            return response()->json(['status' => 'success', 'message' => 'Email verified successfully.']);
        }

        return response()->json(['status' => 'error', 'message' => 'Invalid verification code.'], 400);
    }
}
