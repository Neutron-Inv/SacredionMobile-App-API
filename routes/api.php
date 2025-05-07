<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\EmailVerificationNotificationController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\MobilePasswordResetController;
use Illuminate\Support\Facades\Route;
use Illuminate\Http\Request;

/*
|--------------------------------------------------------------------------
| Authentication API Routes
|--------------------------------------------------------------------------
*/

// ✅ Register
Route::post('/register', [RegisteredUserController::class, 'store'])
    ->middleware('guest')
    ->name('register');

// ✅ Login (Returns Sanctum Token)
Route::post('/login', [AuthenticatedSessionController::class, 'store'])
    ->middleware('guest')
    ->name('login');

// ✅ Forgot Password
Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])
    ->middleware('guest')
    ->name('password.email');

// ✅ Reset Password
Route::post('/reset-password', [NewPasswordController::class, 'store'])
    ->middleware('guest')
    ->name('password.store');

// ✅ Email Verification (Callback after clicking email link)
Route::get('/verify-email/{id}/{hash}', VerifyEmailController::class)
    ->middleware(['auth:sanctum', 'signed', 'throttle:6,1'])
    ->name('verification.verify');

// ✅ Resend Email Verification
Route::post('/email/verification-notification', [EmailVerificationNotificationController::class, 'store'])
    ->middleware(['auth:sanctum', 'throttle:6,1'])
    ->name('verification.send');

// ✅ Logout (Revokes Sanctum Token)
Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
    ->middleware('auth:sanctum')
    ->name('logout');

/*
|--------------------------------------------------------------------------
| Protected Routes (Require Authentication)
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:sanctum'])->group(function () {
    // User Profile
    Route::get('/user', [AuthenticatedSessionController::class, 'user']);

    // Subscription Routes
    Route::get('/order-history/{user_id}', [SubscriptionController::class, 'order_history']);
    Route::get('/cors-list', [SubscriptionController::class, 'cors_list']);
    Route::get('/plans/{cors_id}', [SubscriptionController::class, 'plan']);
    Route::post('/subscribe', [SubscriptionController::class, 'subscribe']);
    Route::post('/initialize-payment', [PaymentController::class, 'initializePayment']);
});


// Payment webhook route (no auth required as it's called by Paystack)
Route::post('/payment/webhook', [PaymentController::class, 'webhook'])->name('payment.callback');

Route::get('/payment/callback', [PaymentController::class, 'callback'])->name('payment.callback');

Route::post('/send-verification-code', [VerifyEmailController::class, 'sendVerificationCode']);
Route::post('/verify-code', [VerifyEmailController::class, 'verifyCode']);

// CORS Proxy routes
Route::get('/cors-proxy/{token}', [SubscriptionController::class, 'handleProxyRequest'])->name('cors.proxy');
Route::get('/cors-status/{token}', [SubscriptionController::class, 'checkStatus'])->name('cors.status');

// Mobile Password Reset Routes
Route::post('/mobile/password/reset-link', [MobilePasswordResetController::class, 'sendResetLink']);
Route::post('/mobile/password/reset', [MobilePasswordResetController::class, 'resetPassword']);
Route::post('/mobile/password/verify-token', [MobilePasswordResetController::class, 'verifyToken']);
