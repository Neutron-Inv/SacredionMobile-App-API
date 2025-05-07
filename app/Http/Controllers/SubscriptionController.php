<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Subscription;
use App\Models\Cors;
use App\Models\User;
use Carbon\Carbon;
use App\Models\Plan;

class SubscriptionController extends Controller
{
    public function order_history(Request $request)
    {
        $subscriptions = Subscription::with(['cors', 'plan'])
            ->join('payments', 'subscriptions.payment_reference', '=', 'payments.payment_reference')
            ->where('subscriptions.user_id', $request->user_id)
            ->orderBy('subscriptions.created_at', 'desc')
            ->select('subscriptions.*', 'payments.price') // include price in the result
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

    public function plan($cors_id)
    {
        $plans = Plan::whereRaw('JSON_CONTAINS(cors, ?)', $cors_id)->get();

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

        // Use plan values for duration and user limit, ensure duration is integer
        $days = (int)$plan->duration;
        $limit = (int)$plan->user_limit;

        // Calculate expiry date based on plan duration from now
        $expiryDate = Carbon::now()->addDays($days);
        $expiryDateFormatted = $expiryDate->format('Ymd');

        $daysInSeconds = $days * 24 * 60 * 60 * 1000;

        $url = "{$cors->url}/ShareUser.html?usr={$cors->username}&pwd={$cors->password}"
            . "&addusr={$user->user_name}&addpwd={$user->user_name}"
            . "&addexp={$expiryDateFormatted}&addcnt={$daysInSeconds}&limitn={$limit}";

        try {
            // Initialize cURL session
            $ch = curl_init();

            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_HEADER => false,
                CURLOPT_HTTPHEADER => [
                    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36',
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                    'Connection: keep-alive'
                ],
            ]);

            $response = curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            // Check if the page contains success message or expected response
            if ($statusCode == 200) {
                // Success: Create subscription record
                $subscription = Subscription::create([
                    'user_id' => $user->id,
                    'cors_id' => $cors->id,
                    'plan_id' => $plan->id,
                    'payment_reference' => 'BETA-' . time(),
                    'expiry_date' => $expiryDate,
                    'user_limit' => $limit,
                    'days_limit' => $days
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Subscription created successfully',
                    'data' => [
                        'subscription' => $subscription,
                        'generated_url' => $url,
                        'response' => $response
                    ]
                ]);
            }

            // If no success message found
            return response()->json([
                'status' => 'error',
                'message' => 'Subscription may not have been processed correctly',
                'generated_url' => $url,
                'response' => $response
            ], 500);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Failed to process subscription with CORS service',
                'error' => $e->getMessage(),
                'generated_url' => $url
            ], 500);
        }
    }
}
