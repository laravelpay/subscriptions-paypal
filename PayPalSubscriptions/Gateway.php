<?php

namespace App\Gateways\PayPalSubscriptions;

use LaraPay\Framework\Interfaces\SubscriptionGateway;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use LaraPay\Framework\Subscription;
use Illuminate\Http\Request;

class Gateway extends SubscriptionGateway
{
    /**
     * Unique identifier for this gateway.
     *
     * @var string
     */
    protected string $identifier = 'paypal-subscriptions';

    /**
     * Gateway version.
     *
     * @var string
     */
    protected string $version = '1.0.0';

    /**
     * Specify supported currencies. PayPalSubscriptions supports many, but we list a couple here.
     *
     * @var array
     */
    protected array $currencies = [];

    /**
     * The subscription instance.
     *
     * @var \LaraPay\Framework\Subscription
     */
    protected $subscription;

    /**
     * Define the gateway configuration fields
     * that are required for PayPal's API.
     *
     * @return array
     */
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
     * Create a payment on PayPalSubscriptions and redirect the user to the PayPalSubscriptions checkout page.
     *
     * @param  \LaraPay\Framework\Subscription  $subscription
     * @return \Illuminate\Http\RedirectResponse
     *
     * @throws \Exception
     */
    public function subscribe($subscription)
    {
        $this->subscription = $subscription;
        return $this->createSubscription();
    }

    private function createSubscription()
    {
        $this->createWebhookUrl();

        if($this->subscription->data('paypal_plan_id')) {
            // If the plan id is passed, we use that
            $planId = $this->subscription->data('paypal_plan_id');
        } else {
            // If plan id is not passed, we create a new plan
            $planId = $this->createPlan();
        }

        if(!$planId)
        {
            throw new \Exception('Failed to create plan');
        }

        // Create the subscription
        $subscription = $this->createPaypalSubscription($planId);

        // store the subscription id for future reference
        $this->subscription->update([
            'subscription_id' => $subscription['id'],
        ]);

        if (!$subscription || !isset($subscription['links'])) {
            throw new \Exception('Failed to create subscription');
        }

        foreach ($subscription['links'] as $link) {
            if ($link['rel'] === 'approve') {
                // Redirect user to PayPal for approval
                return redirect($link['href']);
            }
        }

        throw new \Exception('Approval link not found');
    }

    protected function createPlan()
    {
        $subscription = $this->subscription;
        $product = $this->createProduct();
        $accessToken = $this->getAccessToken();
        $period = $this->getOptimalInterval($subscription->frequency);

        $paymentPreference = [
            "auto_bill_outstanding" => true,
            "setup_fee_failure_action" => "CONTINUE",
            "payment_failure_threshold" => 3
        ];

        $response = Http::withToken($accessToken)
            ->post($this->apiEndpoint('/billing/plans'), [
                "product_id" => $product['id'],
                "name" => $subscription->name,
                "billing_cycles" => [
                    [
                        "frequency" => [
                            "interval_unit" => $period['interval'],
                            "interval_count" => $period['frequency'],
                        ],
                        "tenure_type" => "REGULAR",
                        "sequence" => 1,
                        "total_cycles" => 0, // 0 means it keeps renewing until canceled
                        "pricing_scheme" => [
                            "fixed_price" => [
                                "value" => $subscription->amount,
                                "currency_code" => $subscription->currency,
                            ]
                        ]
                    ]
                ],
                "payment_preferences" => $paymentPreference,
            ]);

        if ($response->failed()) {
            return throw new \Exception('Failed to create plan');
        }

        $plan = $response->json();
        return $plan['id'] ?? null;
    }

    protected function createPaypalSubscription($planId)
    {
        $accessToken = $this->getAccessToken();
        $subscription = $this->subscription;

        $response = Http::withToken($accessToken)
            ->post($this->apiEndpoint('/billing/subscriptions'), [
                "plan_id" => $planId,
                'custom_id' => $subscription->id,
                "application_context" => [
                    "return_url" => route('larapay.webhook', ['gateway_id' => 'paypal-subscriptions', 'ppl_return_url' => $subscription->id]),
                    "cancel_url" => $subscription->cancelUrl(),
                ]
            ]);

        if ($response->failed()) {
            throw new \Exception('Failed to create subscription');
        }

        return $response->json();
    }

    protected function createProduct()
    {
        $accessToken = $this->getAccessToken();
        $response = Http::withToken($accessToken)
            ->post($this->apiEndpoint('/catalogs/products'), [
                "name" => $this->subscription->name,
                "type" => "DIGITAL",
                "category" => "SOFTWARE",
            ]);

        if ($response->failed()) {
            return throw new \Exception('Failed to create product');
        }

        $product = $response->json();
        return $product;
    }

    /**
     * Determine the most optimal interval (day, week, month, year)
     * such that $days is divisible by that interval's day-equivalent.
     *
     * @param  int  $days
     * @return array  [<count> => <interval>]
     */
    private function getOptimalInterval(int $days): array
    {
        // Define the interval mapping in descending order by day-equivalent.
        $intervals = [
            365 => 'YEAR',
            30  => 'MONTH',
            7   => 'WEEK',
            1   => 'DAY',
        ];

        // Check from largest to smallest interval to find a clean divisor
        foreach ($intervals as $dayEquivalent => $label) {
            // If $days is exactly divisible by the day-equivalent,
            // that is our optimal interval.
            if ($days % $dayEquivalent === 0) {
                $count = $days / $dayEquivalent;  // e.g. 90/30 = 3 => 'MONTH'
                return ['frequency' => $count, 'interval' => $label];
            }
        }

        // fallback to default interval
        return ['frequency' => 1, 'interval' => 'MONTH'];
    }

    private function createWebhookUrl()
    {
        $gateway = $this->subscription->gateway;

        // if webhook already exists, return it
        if($this->isSandboxMode() && $gateway->config('sandbox_webhook_id')) {
            return $gateway->config('sandbox_webhook_id');
        } elseif(!$this->isSandboxMode() && $gateway->config('live_webhook_id')) {
            return $gateway->config('live_webhook_id');
        }

        $accessToken = $this->getAccessToken();

        $response = Http::withToken($accessToken)
            ->post($this->apiEndpoint('/notifications/webhooks'), [
                "url" => route('larapay.webhook', ['gateway_id' => 'paypal-subscriptions', 'unique_gc' => $gateway->id]),
                "event_types" => [
                    ['name' => 'BILLING.SUBSCRIPTION.ACTIVATED'],
                    ['name' => 'BILLING.SUBSCRIPTION.CANCELLED'],
                    ['name' => 'BILLING.SUBSCRIPTION.EXPIRED'],
                    ['name' => 'BILLING.SUBSCRIPTION.RE-ACTIVATED'],
                    ['name' => 'BILLING.SUBSCRIPTION.SUSPENDED'],
                    ['name' => 'PAYMENT.SALE.COMPLETED'],
                ],
            ]);

        if ($response->failed()) {
            throw new \Exception('Failed to create webhook');
        }

        $webhook = $response->json();

        if($this->isSandboxMode())
        {
            $gateway->config['sandbox_webhook_id'] = $webhook['id'];
            $gateway->save();
            return $webhook['id'];
        }

        $gateway->config['live_webhook_id'] = $webhook['id'];
        $gateway->save();

        return $webhook['id'];
    }

    private function getWebhookId()
    {
        return $this->createWebhookUrl();
    }

    private function getAccessToken()
    {
        return Cache::remember('larapay:paypal_subscriptions_access_token', 60, function () {
            $gateway = $this->subscription->gateway;
            $clientId = $gateway->config('client_id');
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
        return $this->isSandboxMode() ? 'https://api-m.sandbox.paypal.com/v1' : 'https://api-m.paypal.com/v1';
    }

    private function apiEndpoint($path = '')
    {
        return $this->apiUrl() . $path;
    }

    /**
     * Handle asynchronous callbacks (webhooks) from  .
     * Mollie calls this endpoint whenever the payment status changes.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return void
     *
     * @throws \Exception
     */
    public function callback(Request $request)
    {
        // when the user is redirected back from PayPal, PayPal includes the subscription id in the query string
        // We use that to make an API call to paypal to check the status of the subscription and activate it
        if($request->has('ppl_return_url')) {
            $subscription = Subscription::find($request->get('ppl_return_url'));

            if(!$subscription) {
                throw new \Exception('Subscription not found');
            }

            $this->subscription = $subscription;
            $subscriptionId = $subscription->subscription_id;
            $accessToken = $this->getAccessToken();
            $response = Http::withToken($accessToken)
                ->get($this->apiEndpoint('/billing/subscriptions/' . $subscriptionId));

            if ($response->success() AND isset($response->json()['status']) AND $response->json()['status'] === 'ACTIVE') {
                $subscription->activate($subscriptionId, $response->json());

                // we redirect the user to the success url
                return redirect($subscription->successUrl());
            }
        }

        // otherwise, we handle the webhook event
        return $this->handleCallback($request);
    }

    private function handleCallback(Request $request)
    {
        // if request doesnt contain event type, then return an json response
        if (!$request->has('event_type')) {
            return throw new \Exception('Unexpected Payload');
        }

        // Retrieve all event data
        $event = $request->all();
        $eventType = $event['event_type'] ?? null;

        // Step 1: Verify the webhook to ensure authenticity
        $this->verifyPaypalWebhook($request);

        // Step 2: Process the event only if verification passed
        if (in_array($eventType, [
            'BILLING.SUBSCRIPTION.ACTIVATED',
            'BILLING.SUBSCRIPTION.CANCELLED',
            'BILLING.SUBSCRIPTION.EXPIRED',
            // ... other subscription events you care about
        ])) {
            $subscriptionData = $event['resource'];
            $customId = $subscriptionData['custom_id'] ?? null;
            $subscriptionId = $subscriptionData['id'] ?? null;

            if ($customId) {
                if($eventType === 'BILLING.SUBSCRIPTION.ACTIVATED') {
                    $subscription = Subscription::find($customId);

                    if ($subscription) {
                        $subscription->activate(
                            $subscriptionId,
                            $request->json(),
                        );
                    }

                } else if ($eventType === 'BILLING.SUBSCRIPTION.CANCELLED') {
                    // Handle cancellation logic here
                }
            }
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Verifies that the received webhook event is genuinely from PayPal.
     */
    private function verifyPaypalWebhook(Request $request)
    {
        // Prepare data for verification
        $webhookId = $this->getWebhookId();
        $verificationData = [
            'auth_algo'         => $request->header('PAYPAL-AUTH-ALGO'),
            'cert_url'          => $request->header('PAYPAL-CERT-URL'),
            'transmission_id'   => $request->header('PAYPAL-TRANSMISSION-ID'),
            'transmission_sig'  => $request->header('PAYPAL-TRANSMISSION-SIG'),
            'transmission_time' => $request->header('PAYPAL-TRANSMISSION-TIME'),
            'webhook_id'        => $webhookId,
            'webhook_event'     => $request->json()->all()
        ];

        // Send request to PayPal to verify signature
        $accessToken = $this->getAccessToken();
        $response = Http::withToken($accessToken)
            ->post($this->apiEndpoint('/notifications/verify-webhook-signature'), $verificationData);

        if ($response->failed()) {
            throw new \Exception('Failed to verify webhook signature');
        }

        if($response->json('verification_status') !== 'SUCCESS') {
            throw new \Exception('Failed to verify webhook signature');
        }

        return true;
    }

    public function checkSubscription($subscription): bool
    {
        $accessToken = $this->getAccessToken();
        $subscriptionId = $subscription->subscription_id;

        $response = Http::withToken($accessToken)
            ->get($this->apiEndpoint('/billing/subscriptions/' . $subscriptionId));

        if ($response->failed()) {
            throw new \Exception('Failed to check subscription');
        }

        $subscription = $response->json();

        if(!isset($subscription['status']))
        {
            throw new \Exception('Failed to check subscription');
        }

        return $subscription['status'] === 'ACTIVE';
    }

    public function cancelSubscription($subscription): bool
    {
        $accessToken = $this->getAccessToken();
        $subscriptionId = $subscription->subscription_id;

        $response = Http::withToken($accessToken)
            ->post($this->apiEndpoint('/billing/subscriptions/' . $subscriptionId . '/cancel'), [
                'reason' => 'User canceled subscription',
            ]);

        if ($response->failed()) {
            throw new \Exception('Failed to cancel subscription');
        }

        return true;
    }
}
