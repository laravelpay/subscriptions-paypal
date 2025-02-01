<?php

namespace App\Gateways\PayPalSubscriptions;

use LaraPay\Framework\Interfaces\SubscriptionGateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use LaraPay\Framework\Subscription;
use Illuminate\Http\Request;

class Gateway extends SubscriptionGateway
{
    protected string $identifier = 'paypal-subscriptions';
    protected string $version = '1.0.0';
    protected array $currencies = [];

    protected $subscription;

    public function config(): array
    {
        return [
            'mode' => [
                'label'       => 'PayPal Mode (Sandbox/Live)',
                'description' => 'Select sandbox for testing or live for production',
                'type'        => 'select',
                'options'     => ['sandbox' => 'Sandbox', 'live' => 'Live'],
                'rules'       => ['required'],
            ],
            'client_id' => [
                'label'       => 'PayPal Client ID',
                'description' => 'Your PayPal REST API Client ID',
                'type'        => 'text',
                'rules'       => ['required', 'string'],
            ],
            'client_secret' => [
                'label'       => 'PayPal Client Secret',
                'description' => 'Your PayPal REST API Client Secret',
                'type'        => 'text',
                'rules'       => ['required', 'string'],
            ],
        ];
    }

    /**
     * Main entry point for creating a subscription and redirecting to PayPal.
     */
    public function subscribe($subscription)
    {
        $this->subscription = $subscription;
        return $this->createSubscription();
    }

    private function createSubscription()
    {
        // Ensure a webhook is in place for receiving PayPal events
        $this->createWebhookUrl();

        // If a PayPal plan ID is provided, use it; otherwise create a plan
        $planId = $this->subscription->data('paypal_plan_id') ?? $this->createPlan();
        if (!$planId) {
            throw new \Exception('Failed to create plan');
        }

        // Create the actual PayPal subscription
        $payPalSub = $this->createPaypalSubscription($planId);
        $this->subscription->update(['subscription_id' => $payPalSub['id']]);

        if (!isset($payPalSub['links'])) {
            throw new \Exception('Failed to create subscription');
        }

        // Redirect user to the "approve" link
        foreach ($payPalSub['links'] as $link) {
            if ($link['rel'] === 'approve') {
                return redirect($link['href']);
            }
        }

        throw new \Exception('Approval link not found');
    }

    protected function createPlan()
    {
        $product = $this->createProduct();
        $interval = $this->getOptimalInterval($this->subscription->frequency);

        $plan = $this->paypalRequest('post', '/billing/plans', [
            "product_id" => $product['id'],
            "name"       => $this->subscription->name,
            "billing_cycles" => [
                [
                    "frequency" => [
                        "interval_unit"  => $interval['interval'],
                        "interval_count" => $interval['frequency'],
                    ],
                    "tenure_type"   => "REGULAR",
                    "sequence"      => 1,
                    "total_cycles"  => 0, // infinite
                    "pricing_scheme" => [
                        "fixed_price" => [
                            "value"         => $this->subscription->amount,
                            "currency_code" => $this->subscription->currency,
                        ]
                    ]
                ]
            ],
            "payment_preferences" => [
                "auto_bill_outstanding"      => true,
                "setup_fee_failure_action"   => "CONTINUE",
                "payment_failure_threshold"  => 3
            ],
        ]);

        return $plan['id'] ?? null;
    }

    protected function createPaypalSubscription($planId)
    {
        return $this->paypalRequest('post', '/billing/subscriptions', [
            "plan_id"   => $planId,
            "custom_id" => $this->subscription->id,
            "application_context" => [
                "return_url" => $this->subscription->callbackUrl(),
                "cancel_url" => $this->subscription->cancelUrl(),
            ]
        ]);
    }

    protected function createProduct()
    {
        return $this->paypalRequest('post', '/catalogs/products', [
            "name"     => $this->subscription->name,
            "type"     => "DIGITAL",
            "category" => "SOFTWARE",
        ]);
    }

    /**
     * Helps find an interval (DAY, WEEK, MONTH, YEAR) that evenly divides $days.
     */
    private function getOptimalInterval(int $days): array
    {
        $intervals = [
            365 => 'YEAR',
            30  => 'MONTH',
            7   => 'WEEK',
            1   => 'DAY',
        ];

        // Pick the largest interval that cleanly divides $days
        foreach ($intervals as $dayEquivalent => $label) {
            if ($days % $dayEquivalent === 0) {
                return [
                    'frequency' => $days / $dayEquivalent,
                    'interval'  => $label
                ];
            }
        }

        // Fallback (shouldn't usually happen unless you have weird intervals)
        return ['frequency' => 1, 'interval' => 'MONTH'];
    }

    /**
     * Checks if a webhook already exists. If not, creates it.
     */
    private function createWebhookUrl()
    {
        $gateway = $this->subscription->gateway;
        $key = $this->isSandboxMode() ? 'sandbox_webhook_id' : 'live_webhook_id';

        // If we already have a webhook ID stored, return it
        if ($gateway->config($key)) {
            return $gateway->config($key);
        }

        // Otherwise, create a new webhook
        $webhook = $this->paypalRequest('post', '/notifications/webhooks', [
            "url" => route('larapay.webhook', [
                'gateway_id' => 'paypal-subscriptions',
                'unique_gw'  => $gateway->id
            ]),
            "event_types" => [
                ["name" => "BILLING.SUBSCRIPTION.ACTIVATED"],
                ["name" => "BILLING.SUBSCRIPTION.CANCELLED"],
                ["name" => "BILLING.SUBSCRIPTION.EXPIRED"],
                ["name" => "BILLING.SUBSCRIPTION.RE-ACTIVATED"],
                ["name" => "BILLING.SUBSCRIPTION.SUSPENDED"],
                ["name" => "PAYMENT.SALE.COMPLETED"],
            ],
        ]);

        $gateway->config[$key] = $webhook['id'];
        $gateway->save();

        return $webhook['id'];
    }

    /**
     * Get or cache an access token from PayPal for subsequent calls.
     */
    private function getAccessToken()
    {
        return Cache::remember('larapay:paypal_subscriptions_access_token', 60, function () {
            $gateway      = $this->subscription->gateway;
            $clientId     = $gateway->config('client_id');
            $clientSecret = $gateway->config('client_secret');

            $response = Http::withBasicAuth($clientId, $clientSecret)
                ->asForm()
                ->post($this->apiEndpoint('/oauth2/token'), [
                    'grant_type' => 'client_credentials',
                ]);

            if ($response->failed()) {
                throw new \Exception('Failed to get access token');
            }

            return $response->json()['access_token'];
        });
    }

    private function isSandboxMode()
    {
        return $this->subscription->gateway->config('mode', 'sandbox') === 'sandbox';
    }

    private function apiUrl()
    {
        return $this->isSandboxMode()
            ? 'https://api-m.sandbox.paypal.com/v1'
            : 'https://api-m.paypal.com/v1';
    }

    private function apiEndpoint($path = '')
    {
        return $this->apiUrl() . $path;
    }

    /**
     * Generic helper to send a request to PayPal and throw if it fails.
     */
    private function paypalRequest(string $method, string $path, array $data = [])
    {
        $response = Http::withToken($this->getAccessToken())->$method($this->apiEndpoint($path), $data);

        if ($response->failed()) {
            throw new \Exception(
                "PayPal $method $path failed: " . $response->body()
            );
        }

        return $response->json();
    }

    /**
     * Handle the callback when PayPal redirects back or sends a webhook.
     */
    public function callback(Request $request)
    {
        $subscription = Subscription::find($request->get('subscription_id'));

        if (!$subscription) {
            throw new \Exception('Subscription not found');
        }

        $this->subscription = $subscription;
        $subData = $this->paypalRequest('get', '/billing/subscriptions/' . $subscription->subscription_id);

        if (($subData['status'] ?? null) === 'ACTIVE') {
            $subscription->activate($subscription->subscription_id, $subData);
            return redirect($subscription->successUrl());
        }
    }

    /**
     * Process PayPal's webhook events asynchronously.
     */
    private function webhook(Request $request)
    {
        if (!$request->has('event_type')) {
            throw new \Exception('Unexpected Payload');
        }

        $customId   = $resource['custom_id'] ?? null;
        $subscription = Subscription::find($customId);

        if(!$subscription) {
            throw new \Exception('Subscription not found');
        }

        $this->subscription = $subscription;

        // Verify PayPal's signature
        $this->verifyPaypalWebhook($request);

        $eventType  = $request->input('event_type');
        $resource   = $request->input('resource') ?? [];
        $subId      = $resource['id'] ?? null;

        // Handle relevant subscription events
        if (in_array($eventType, [
            'BILLING.SUBSCRIPTION.ACTIVATED',
            'BILLING.SUBSCRIPTION.CANCELLED',
            'BILLING.SUBSCRIPTION.EXPIRED',
        ])) {
            if ($customId && $eventType === 'BILLING.SUBSCRIPTION.ACTIVATED') {
                if($subscription->isActive()) {
                    return;
                }

                if ($subscription) {
                    $subscription->activate($subId, $request->json());
                }
            } elseif ($customId && $eventType === 'BILLING.SUBSCRIPTION.CANCELLED') {
                // Handle your cancellation logic
            }
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Verify PayPal's webhook signature to ensure authenticity.
     */
    private function verifyPaypalWebhook(Request $request)
    {
        $webhookId  = $this->createWebhookUrl(); // ensures we have a valid Webhook ID
        $verification = $this->paypalRequest('post', '/notifications/verify-webhook-signature', [
            'auth_algo'         => $request->header('PAYPAL-AUTH-ALGO'),
            'cert_url'          => $request->header('PAYPAL-CERT-URL'),
            'transmission_id'   => $request->header('PAYPAL-TRANSMISSION-ID'),
            'transmission_sig'  => $request->header('PAYPAL-TRANSMISSION-SIG'),
            'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
            'webhook_id'        => $webhookId,
            'webhook_event'     => $request->json()->all(),
        ]);

        if (($verification['verification_status'] ?? null) !== 'SUCCESS') {
            throw new \Exception('Failed to verify webhook signature');
        }
    }

    /**
     * Check if a subscription is still ACTIVE on PayPal.
     */
    public function checkSubscription($subscription): bool
    {
        $subData = $this->paypalRequest('get', '/billing/subscriptions/' . $subscription->subscription_id);

        if (!isset($subData['status'])) {
            throw new \Exception('Failed to check subscription');
        }

        return $subData['status'] === 'ACTIVE';
    }

    /**
     * Cancel a subscription on PayPal.
     */
    public function cancelSubscription($subscription): bool
    {
        $this->paypalRequest('post', '/billing/subscriptions/' . $subscription->subscription_id . '/cancel', [
            'reason' => 'User canceled subscription',
        ]);

        return true;
    }
}
