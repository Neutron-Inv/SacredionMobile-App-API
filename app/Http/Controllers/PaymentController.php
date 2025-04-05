<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Plan;
use App\Models\User;
use App\Models\Cors;
use App\Models\Payment;
use App\Models\Subscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    protected $paystackSecretKey;
    protected $paystackBaseUrl = 'https://api.paystack.co';

    public function __construct()
    {
        $this->paystackSecretKey = config('services.paystack.secret_key');
    }

    public function initializePayment(Request $request)
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'cors_id' => 'required|exists:cors,id',
            'plan_id' => 'required|exists:plans,id',
        ]);

        $user = User::findOrFail($request->user_id);
        $plan = Plan::findOrFail($request->plan_id);
        $cors = Cors::findOrFail($request->cors_id);

        // Convert price to kobo (Paystack expects amount in kobo)
        $amountInKobo = $plan->price * 100;

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->paystackSecretKey,
                'Content-Type' => 'application/json',
            ])->post($this->paystackBaseUrl . '/transaction/initialize', [
                'email' => $user->email,
                'amount' => $amountInKobo,
                'callback_url' => route('payment.callback'),
                'metadata' => [
                    'user_id' => $user->id,
                    'cors_id' => $cors->id,
                    'plan_id' => $plan->id,
                ],
            ]);

            if ($response->successful()) {
                $responseData = $response->json();

                // Create a pending payment record
                Payment::create([
                    'user_id' => $user->id,
                    'plan_id' => $plan->id,
                    'cors_id' => $cors->id,
                    'amount' => $plan->price,
                    'payment_reference' => $responseData['data']['reference'],
                    'status' => 'pending',
                    'payment_method' => 'paystack',
                ]);

                return response()->json([
                    'status' => 'success',
                    'message' => 'Payment initialized successfully',
                    'data' => [
                        'authorization_url' => $responseData['data']['authorization_url'],
                        'reference' => $responseData['data']['reference'],
                    ]
                ]);
            }

            return response()->json([
                'status' => 'error',
                'message' => 'Failed to initialize payment',
                'error' => $response->json()
            ], 400);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Payment initialization failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function webhook(Request $request)
    {
        Log::info('Webhook received', ['method' => $request->method(), 'payload' => $request->all()]);
        $payload = $request->all();
        $signature = $request->header('X-Paystack-Signature');

        // Verify webhook signature
        if (!$this->verifyPaystackSignature($signature, $request->getContent())) {
            return response()->json(['status' => 'error', 'message' => 'Invalid signature'], 400);
        }

        // Handle the event
        if ($payload['event'] === 'charge.success') {
            $reference = $payload['data']['reference'];
            $payment = Payment::where('payment_reference', $reference)->first();

            if ($payment && $payment->status === 'pending') {
                // Update payment status
                $payment->update([
                    'status' => 'completed',
                    'payment_details' => json_encode($payload['data']),
                ]);

                // Create subscription
                $this->createSubscription($payment);
            }
        }

        return response()->json(['status' => 'success']);
    }

    protected function verifyPaystackSignature($signature, $payload)
    {
        $computedSignature = hash_hmac('sha512', $payload, $this->paystackSecretKey);
        return hash_equals($computedSignature, $signature);
    }

    protected function createSubscription($payment)
    {
        Log::info('Starting subscription creation process', [
            'payment_id' => $payment->id,
            'payment_reference' => $payment->payment_reference
        ]);

        try {
            // Get user, plan, and cors details
            Log::info('Fetching user, plan, and cors details', [
                'user_id' => $payment->user_id,
                'plan_id' => $payment->plan_id,
                'cors_id' => $payment->cors_id
            ]);

            $user = User::findOrFail($payment->user_id);
            $plan = Plan::findOrFail($payment->plan_id);
            $cors = Cors::findOrFail($payment->cors_id);

            Log::info('Successfully retrieved user, plan, and cors details', [
                'user_name' => $user->user_name,
                'plan_name' => $plan->name,
                'cors_name' => $cors->name
            ]);

            // Calculate expiry date based on plan duration
            $days = (int)$plan->duration;
            $limit = (int)$plan->user_limit;
            $expiryDate = Carbon::now()->addDays($days);
            $daysInSeconds = $days * 24 * 60 * 60 * 1000;

            Log::info('Calculated subscription parameters', [
                'days' => $days,
                'limit' => $limit,
                'expiry_date' => $expiryDate->format('Y-m-d H:i:s'),
                'days_in_seconds' => $daysInSeconds
            ]);

            // Create subscription
            Log::info('Creating subscription record');

            $subscription = Subscription::create([
                'user_id' => $user->id,
                'cors_id' => $cors->id,
                'plan_id' => $plan->id,
                'payment_reference' => $payment->payment_reference,
                'expiry_date' => $expiryDate,
                'user_limit' => $limit,
                'days_limit' => $days
            ]);

            Log::info('Subscription record created successfully', [
                'subscription_id' => $subscription->id
            ]);

            // Update payment with subscription_id
            Log::info('Updating payment with subscription_id');

            $payment->update(['subscription_id' => $subscription->id]);

            Log::info('Payment updated with subscription_id');

            // Make the CORS service request
            Log::info('Initiating CORS service request');

            $this->makeCorsServiceRequest($subscription, $cors, $user, $expiryDate, $daysInSeconds, $limit);

            Log::info('Subscription creation process completed successfully', [
                'subscription_id' => $subscription->id,
                'payment_id' => $payment->id
            ]);

            return $subscription;
        } catch (\Exception $e) {
            Log::error('Error in subscription creation process', [
                'payment_id' => $payment->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    protected function makeCorsServiceRequest($subscription, $cors, $user, $expiryDate, $daysInSeconds, $limit)
    {
        Log::info('Starting CORS service request', [
            'subscription_id' => $subscription->id,
            'cors_id' => $cors->id,
            'user_id' => $user->id
        ]);

        $url = "{$cors->url}/ShareUser.html?usr={$cors->username}&pwd={$cors->password}"
            . "&addusr={$user->user_name}&addpwd={$user->user_name}"
            . "&addexp={$expiryDate->format('Ymd')}&addcnt={$daysInSeconds}&limitn={$limit}";

        Log::info('Generated CORS service URL', [
            'url' => $url,
            'expiry_date_formatted' => $expiryDate->format('Ymd')
        ]);

        try {
            Log::info('Initializing cURL request');

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

            Log::info('Executing cURL request');

            $response = curl_exec($ch);
            $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

            Log::info('cURL request completed', [
                'status_code' => $statusCode,
                'response_length' => strlen($response)
            ]);

            curl_close($ch);

            if ($statusCode !== 200) {
                Log::error('CORS service request failed', [
                    'subscription_id' => $subscription->id,
                    'status_code' => $statusCode,
                    'response' => $response
                ]);
            } else {
                Log::info('CORS service request successful', [
                    'subscription_id' => $subscription->id,
                    'status_code' => $statusCode
                ]);
            }
        } catch (\Exception $e) {
            Log::error('CORS service request error', [
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    public function callback(Request $request)
    {
        $reference = $request->query('reference');

        // Verify the transaction status
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->paystackSecretKey,
        ])->get($this->paystackBaseUrl . '/transaction/verify/' . $reference);

        if ($response->successful() && $response->json()['data']['status'] === 'success') {
            // Transaction was successful
            return view('payment.success', ['reference' => $reference]);
        }

        // Transaction failed or verification failed
        return view('payment.failure', ['reference' => $reference]);
    }
}
