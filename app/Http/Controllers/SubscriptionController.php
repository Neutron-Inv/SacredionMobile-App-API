<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Subscription;
use App\Models\Cors;
use App\Models\User;
use Carbon\Carbon;
use App\Models\Plan;
use Illuminate\Support\Facades\Log;
use App\Models\CorsPendingRequest;

class SubscriptionController extends Controller
{
    public function order_history(Request $request)
    {
        $subscriptions = Subscription::with(['cors', 'plan'])
            ->where('user_id', $request->user_id)
            ->get();

        return response()->json([
            'status' => 'success',
            'data' => $subscriptions
        ]);
    }

    public function cors_list()
    {
        $cors = Cors::all();

        return response()->json([
            'status' => 'success',
            'data' => $cors
        ]);
    }

    public function plan()
    {
        $plans = Plan::all();

        return response()->json([
            'status' => 'success',
            'data' => $plans
        ]);
    }

    public function subscribe(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'cors_id' => 'required|exists:cors,id',
            'plan_id' => 'required|exists:plans,id'
        ]);

        $cors = Cors::findOrFail($request->cors_id);
        $user = User::findOrFail($request->user_id);
        $plan = Plan::findOrFail($request->plan_id);

        // Use plan values for duration and user limit
        $days = (int)$plan->duration;
        $limit = (int)$plan->user_limit;

        // Calculate expiry date based on plan duration from now
        $expiryDate = Carbon::now()->addDays($days);
        $expiryDateFormatted = $expiryDate->format('Ymd');

        $daysInSeconds = $days * 24 * 60 * 60 * 1000;

        $corsUrl = "{$cors->url}/ShareUser.html?usr={$cors->username}&pwd={$cors->password}"
            . "&addusr={$user->user_name}&addpwd={$user->user_name}"
            . "&addexp={$expiryDateFormatted}&addcnt={$daysInSeconds}&limitn={$limit}";

        Log::info('Starting subscription process', [
            'cors_url' => $corsUrl,
            'proxy_url' => config('services.proxy.url')
        ]);

        try {
            // Initialize cURL session for proxy request
            $ch = curl_init();

            $proxyData = json_encode([
                'target_url' => $corsUrl,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36',
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                    'Connection' => 'keep-alive'
                ]
            ]);

            Log::info('Sending request to proxy', [
                'proxy_data' => $proxyData
            ]);

            curl_setopt_array($ch, [
                CURLOPT_URL => config('services.proxy.url'),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $proxyData,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json'
                ],
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10
            ]);

            $response = curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            $curlErrno = curl_errno($ch);

            Log::info('Proxy response received', [
                'status_code' => $statusCode,
                'curl_error' => $curlError,
                'curl_errno' => $curlErrno,
                'response' => $response
            ]);

            curl_close($ch);

            if ($curlError) {
                throw new \Exception("Curl error: $curlError");
            }

            $responseData = json_decode($response, true);

            if ($statusCode !== 200) {
                throw new \Exception("Proxy returned status code: $statusCode");
            }

            if (!isset($responseData['success']) || !$responseData['success']) {
                throw new \Exception("Proxy request unsuccessful: " . ($responseData['error'] ?? 'Unknown error'));
            }

            // If we get here, the proxy request was successful
            // Create subscription record
            $subscription = Subscription::create([
                'user_id' => $user->id,
                'cors_id' => $cors->id,
                'plan_id' => $plan->id,
                'payment_reference' => 'BETA-' . time(),
                'expiry_date' => $expiryDate,
                'user_limit' => $limit,
                'days_limit' => $days
            ]);

            Log::info('Subscription created successfully', [
                'subscription_id' => $subscription->id,
                'proxy_response' => $responseData
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Subscription created successfully',
                'data' => [
                    'subscription' => $subscription,
                    'proxy_response' => $responseData
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Subscription process failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process subscription',
                'error_details' => [
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    public function handleProxyRequest($token)
    {
        $pendingRequest = CorsPendingRequest::where('token', $token)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->firstOrFail();

        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $pendingRequest->request_url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HTTPHEADER => [
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36',
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                    'Connection: keep-alive'
                ],
            ]);

            $response = curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($statusCode == 200 || $statusCode == 302) {
                // Update the pending request
                $pendingRequest->update([
                    'status' => 'completed',
                    'response' => $response
                ]);

                // Create the subscription
                $subscription = Subscription::create([
                    'user_id' => $pendingRequest->user_id,
                    'cors_id' => $pendingRequest->cors_id,
                    'plan_id' => $pendingRequest->plan_id,
                    'payment_reference' => 'BETA-' . time(),
                    'expiry_date' => Carbon::parse($pendingRequest->expires_at),
                    'user_limit' => $pendingRequest->plan->user_limit,
                    'days_limit' => $pendingRequest->plan->duration
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Subscription created successfully',
                    'data' => [
                        'subscription' => $subscription
                    ]
                ]);
            }

            // Update the pending request with the error
            $pendingRequest->update([
                'status' => 'failed',
                'response' => $response
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process subscription',
                'error_details' => [
                    'status_code' => $statusCode,
                    'response' => $response
                ]
            ], 500);
        } catch (\Exception $e) {
            Log::error('Exception in proxy request', [
                'error' => $e->getMessage(),
                'token' => $token
            ]);

            $pendingRequest->update([
                'status' => 'failed',
                'response' => $e->getMessage()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process subscription',
                'error_details' => [
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }

    public function checkStatus($token)
    {
        $pendingRequest = CorsPendingRequest::where('token', $token)->firstOrFail();

        return response()->json([
            'status' => $pendingRequest->status,
            'data' => [
                'expires_at' => $pendingRequest->expires_at,
                'response' => $pendingRequest->status === 'completed' ? $pendingRequest->response : null
            ]
        ]);
    }
}
