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

    public function handleWebhook(Request $request)
    {
        Log::info('Webhook received', [
            'payload' => $request->all()
        ]);

        try {
            // Verify the webhook is from Paystack
            if (!$this->verifyWebhookSignature($request)) {
                Log::error('Invalid webhook signature');
                return response()->json(['status' => 'error', 'message' => 'Invalid signature'], 400);
            }

            $payload = $request->all();

            // Handle only successful charge events
            if ($payload['event'] === 'charge.success') {
                $data = $payload['data'];
                $payment = Payment::where('reference', $data['reference'])->first();

                if (!$payment) {
                    Log::error('Payment not found for webhook', ['reference' => $data['reference']]);
                    return response()->json(['status' => 'error', 'message' => 'Payment not found'], 404);
                }

                Log::info('Processing successful payment', [
                    'payment_id' => $payment->id,
                    'amount' => $data['amount'],
                    'reference' => $data['reference']
                ]);

                // Update payment status
                $payment->update([
                    'status' => 'success',
                    'paid_at' => now(),
                    'payment_data' => json_encode($data)
                ]);

                // Create subscription
                $subscription = $this->createSubscription($payment);

                if ($subscription) {
                    Log::info('Subscription created successfully', [
                        'subscription_id' => $subscription->id,
                        'payment_id' => $payment->id
                    ]);

                    return response()->json([
                        'status' => 'success',
                        'message' => 'Webhook processed successfully'
                    ]);
                }
            }

            return response()->json(['status' => 'success', 'message' => 'Webhook received']);
        } catch (\Exception $e) {
            Log::error('Error processing webhook', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Error processing webhook'
            ], 500);
        }
    }

    private function createSubscription($payment)
    {
        try {
            Log::info('Starting subscription creation process', [
                'payment_id' => $payment->id,
                'user_id' => $payment->user_id
            ]);

            $user = User::findOrFail($payment->user_id);
            $plan = Plan::findOrFail($payment->plan_id);
            $cors = Cors::findOrFail($payment->cors_id);

            // Calculate expiry date based on plan duration
            $days = (int)$plan->duration;
            $expiryDate = now()->addDays($days);
            $expiryDateFormatted = $expiryDate->format('Ymd');
            $daysInSeconds = $days * 24 * 60 * 60 * 1000;

            // Create subscription record first
            $subscription = Subscription::create([
                'user_id' => $user->id,
                'cors_id' => $cors->id,
                'plan_id' => $plan->id,
                'payment_reference' => $payment->reference,
                'expiry_date' => $expiryDate,
                'user_limit' => $plan->user_limit,
                'days_limit' => $days
            ]);

            // Generate CORS service URL
            $corsUrl = "{$cors->url}/ShareUser.html?usr={$cors->username}&pwd={$cors->password}"
                . "&addusr={$user->user_name}&addpwd={$user->user_name}"
                . "&addexp={$expiryDateFormatted}&addcnt={$daysInSeconds}&limitn={$plan->user_limit}";

            // Get the proxy URL from config
            $proxyUrl = config('services.proxy.url');

            Log::info('Making proxy service request', [
                'subscription_id' => $subscription->id,
                'cors_url' => $corsUrl,
                'proxy_url' => $proxyUrl
            ]);

            // Initialize cURL session for proxy request
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $proxyUrl,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode([
                    'target_url' => $corsUrl,
                    'headers' => [
                        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/134.0.0.0 Safari/537.36',
                        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                        'Connection' => 'keep-alive'
                    ]
                ]),
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
            curl_close($ch);

            Log::info('Proxy service response received', [
                'subscription_id' => $subscription->id,
                'status_code' => $statusCode,
                'curl_error' => $curlError,
                'response' => $response
            ]);

            if ($curlError) {
                Log::error('Proxy service request failed', [
                    'subscription_id' => $subscription->id,
                    'error' => $curlError
                ]);
                // Even if proxy request fails, we return the subscription
                // as it's already created in our system
                return $subscription;
            }

            $proxyResponse = json_decode($response, true);

            if (!$proxyResponse || !isset($proxyResponse['success'])) {
                Log::error('Invalid proxy response format', [
                    'subscription_id' => $subscription->id,
                    'response' => $response
                ]);
                return $subscription;
            }

            if (!$proxyResponse['success']) {
                Log::error('Proxy request unsuccessful', [
                    'subscription_id' => $subscription->id,
                    'proxy_error' => $proxyResponse['error'] ?? 'Unknown error'
                ]);
                return $subscription;
            }

            Log::info('CORS service request successful through proxy', [
                'subscription_id' => $subscription->id,
                'cors_status_code' => $proxyResponse['status_code']
            ]);

            return $subscription;
        } catch (\Exception $e) {
            Log::error('Error in subscription creation', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'payment_id' => $payment->id
            ]);
            throw $e;
        }
    }

    private function verifyWebhookSignature(Request $request)
    {
        $signature = $request->header('x-paystack-signature');
        $payload = $request->getContent();
        $secret = config('services.paystack.secret_key');

        $computedSignature = hash_hmac('sha512', $payload, $secret);

        return hash_equals($signature, $computedSignature);
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
