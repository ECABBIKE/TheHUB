<?php
/**
 * Stripe Client - Handles Stripe Connect payments
 *
 * @package TheHUB\Payment
 */

namespace TheHUB\Payment;

class StripeClient {
    private $apiKey;
    private $baseUrl = 'https://api.stripe.com/v1';

    public function __construct(string $apiKey) {
        $this->apiKey = $apiKey;
    }

    /**
     * Create Payment Intent
     *
     * Supports two modes:
     * 1. Single seller (destination charge): Uses transfer_data with one connected account
     * 2. Multi-seller (separate charges and transfers): Uses transfer_group,
     *    then createTransfer() is called later per seller after payment succeeds
     *
     * @param array $data Payment data
     * @return array Result
     */
    public function createPaymentIntent(array $data): array {
        $params = [
            'amount' => (int)($data['amount'] * 100), // Convert to cents/ore
            'currency' => strtolower($data['currency'] ?? 'sek'),
            'description' => $data['description'] ?? '',
            'metadata' => $data['metadata'] ?? []
        ];

        // Payment method types - default to card, can include swish, vipps
        $paymentMethods = $data['payment_method_types'] ?? ['card'];
        $params['payment_method_types'] = $paymentMethods;

        // Check if Vipps is included (requires preview header)
        $extraHeaders = [];
        if (in_array('vipps', $paymentMethods)) {
            $extraHeaders[] = 'vipps_preview: v1';
        }

        // Add receipt email if provided
        if (!empty($data['email'])) {
            $params['receipt_email'] = $data['email'];
        }

        // Multi-seller mode: Use transfer_group (separate charges and transfers)
        // Transfers are created later via createTransfer() after payment succeeds
        if (!empty($data['transfer_group'])) {
            $params['transfer_group'] = $data['transfer_group'];
        }
        // Single seller mode: Use destination charge (legacy, simpler for single seller)
        elseif (!empty($data['stripe_account_id'])) {
            $params['transfer_data'] = [
                'destination' => $data['stripe_account_id']
            ];

            // Platform fee - supports percentage or fixed amount
            if (!empty($data['platform_fee_fixed'])) {
                // Fixed fee in öre (e.g., 1000 = 10 kr)
                $params['application_fee_amount'] = (int)$data['platform_fee_fixed'];
            } elseif (!empty($data['platform_fee_percent'])) {
                // Percentage fee (e.g., 2 = 2%)
                $feeAmount = (int)($params['amount'] * $data['platform_fee_percent'] / 100);
                $params['application_fee_amount'] = $feeAmount;
            }
        }

        $response = $this->request('POST', '/payment_intents', $params, $extraHeaders);

        return [
            'success' => !isset($response['error']),
            'payment_intent_id' => $response['id'] ?? null,
            'client_secret' => $response['client_secret'] ?? null,
            'status' => $response['status'] ?? null,
            'error' => $response['error']['message'] ?? null
        ];
    }

    /**
     * Get Payment Intent status
     *
     * @param string $paymentIntentId Payment Intent ID
     * @return array Status result
     */
    public function getPaymentIntent(string $paymentIntentId): array {
        $response = $this->request('GET', "/payment_intents/{$paymentIntentId}");

        return [
            'success' => !isset($response['error']),
            'id' => $response['id'] ?? null,
            'status' => $response['status'] ?? null,
            'paid' => ($response['status'] ?? '') === 'succeeded',
            'amount' => isset($response['amount']) ? $response['amount'] / 100 : 0,
            'currency' => $response['currency'] ?? 'sek',
            'payment_method' => $response['payment_method'] ?? null,
            'error' => $response['error']['message'] ?? null
        ];
    }

    /**
     * Create refund
     *
     * @param string $paymentIntentId Payment Intent ID
     * @param float|null $amount Amount to refund (null for full refund)
     * @return array Refund result
     */
    public function createRefund(string $paymentIntentId, ?float $amount = null): array {
        $params = [
            'payment_intent' => $paymentIntentId
        ];

        if ($amount !== null) {
            $params['amount'] = (int)($amount * 100);
        }

        $response = $this->request('POST', '/refunds', $params);

        return [
            'success' => !isset($response['error']),
            'refund_id' => $response['id'] ?? null,
            'status' => $response['status'] ?? null,
            'amount' => isset($response['amount']) ? $response['amount'] / 100 : 0,
            'error' => $response['error']['message'] ?? null
        ];
    }

    /**
     * Cancel Payment Intent
     *
     * @param string $paymentIntentId Payment Intent ID
     * @return array Cancel result
     */
    public function cancelPaymentIntent(string $paymentIntentId): array {
        $response = $this->request('POST', "/payment_intents/{$paymentIntentId}/cancel");

        return [
            'success' => !isset($response['error']),
            'status' => $response['status'] ?? null,
            'error' => $response['error']['message'] ?? null
        ];
    }

    /**
     * Create Connected Account (Express) - Recipient Model
     *
     * Uses "recipient" configuration where the platform collects payments
     * and transfers funds to connected accounts. Simpler onboarding for
     * sellers - they just need bank details, no full merchant setup.
     *
     * @param array $data Account data
     * @return array Result
     */
    public function createConnectedAccount(array $data): array {
        $params = [
            'type' => 'express',
            'country' => $data['country'] ?? 'SE',
            'email' => $data['email'] ?? null,
            // Standard Express account with both capabilities
            // - card_payments: Can accept card payments directly (required by Stripe)
            // - transfers: Can receive transfers from the platform
            'capabilities' => [
                'card_payments' => ['requested' => true],
                'transfers' => ['requested' => true]
            ],
            'business_type' => $data['business_type'] ?? 'individual',
            'metadata' => $data['metadata'] ?? []
        ];

        // Optional: Add business profile for better UX
        if (!empty($data['business_name'])) {
            $params['business_profile'] = [
                'name' => $data['business_name'],
                'url' => $data['business_url'] ?? null
            ];
        }

        $response = $this->request('POST', '/accounts', $params);

        return [
            'success' => !isset($response['error']),
            'account_id' => $response['id'] ?? null,
            'error' => $response['error']['message'] ?? null
        ];
    }

    /**
     * Create Account Link for onboarding
     *
     * @param string $accountId Stripe Account ID
     * @param string $returnUrl Return URL after onboarding
     * @param string $refreshUrl Refresh URL if link expires
     * @return array Result with onboarding URL
     */
    public function createAccountLink(string $accountId, string $returnUrl, string $refreshUrl): array {
        $params = [
            'account' => $accountId,
            'return_url' => $returnUrl,
            'refresh_url' => $refreshUrl,
            'type' => 'account_onboarding'
        ];

        $response = $this->request('POST', '/account_links', $params);

        return [
            'success' => !isset($response['error']),
            'url' => $response['url'] ?? null,
            'expires_at' => $response['expires_at'] ?? null,
            'error' => $response['error']['message'] ?? null
        ];
    }

    /**
     * Get Connected Account details
     *
     * @param string $accountId Stripe Account ID
     * @return array Account details
     */
    public function getAccount(string $accountId): array {
        $response = $this->request('GET', "/accounts/{$accountId}");

        return [
            'success' => !isset($response['error']),
            'id' => $response['id'] ?? null,
            'email' => $response['email'] ?? null,
            'charges_enabled' => $response['charges_enabled'] ?? false,
            'payouts_enabled' => $response['payouts_enabled'] ?? false,
            'details_submitted' => $response['details_submitted'] ?? false,
            'error' => $response['error']['message'] ?? null
        ];
    }

    /**
     * Verify webhook signature
     *
     * @param string $payload Raw request body
     * @param string $sigHeader Stripe-Signature header
     * @param string $secret Webhook secret
     * @return array Verification result
     */
    public function verifyWebhookSignature(string $payload, string $sigHeader, string $secret): array {
        $elements = [];
        foreach (explode(',', $sigHeader) as $element) {
            $parts = explode('=', $element, 2);
            if (count($parts) === 2) {
                $elements[$parts[0]] = $parts[1];
            }
        }

        if (!isset($elements['t']) || !isset($elements['v1'])) {
            return ['valid' => false, 'error' => 'Invalid signature header format'];
        }

        $timestamp = $elements['t'];
        $signature = $elements['v1'];

        // Check timestamp tolerance (5 minutes)
        if (abs(time() - $timestamp) > 300) {
            return ['valid' => false, 'error' => 'Timestamp outside tolerance'];
        }

        // Compute expected signature
        $signedPayload = $timestamp . '.' . $payload;
        $expectedSignature = hash_hmac('sha256', $signedPayload, $secret);

        if (!hash_equals($expectedSignature, $signature)) {
            return ['valid' => false, 'error' => 'Signature mismatch'];
        }

        return ['valid' => true, 'event' => json_decode($payload, true)];
    }

    /**
     * Make API request to Stripe
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array|null $data Request data
     * @param array $extraHeaders Additional headers (e.g., for beta features)
     * @return array Response
     */
    public function request(string $method, string $endpoint, ?array $data = null, array $extraHeaders = []): array {
        $url = $this->baseUrl . $endpoint;

        $ch = curl_init($url);

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/x-www-form-urlencoded',
            'Stripe-Version: 2023-10-16'
        ];

        // Add any extra headers (e.g., vipps_preview=v1 for Vipps beta)
        foreach ($extraHeaders as $header) {
            $headers[] = $header;
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30
        ]);

        if ($data !== null && $method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $this->buildQuery($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['error' => ['message' => 'CURL Error: ' . $error]];
        }

        curl_close($ch);

        return json_decode($response, true) ?? [];
    }

    /**
     * Build query string from nested array (Stripe format)
     *
     * @param array $data Data to encode
     * @param string $prefix Prefix for nested keys
     * @return string Query string
     */
    private function buildQuery(array $data, string $prefix = ''): string {
        $result = [];

        foreach ($data as $key => $value) {
            $fullKey = $prefix ? $prefix . '[' . $key . ']' : $key;

            if (is_array($value)) {
                $result[] = $this->buildQuery($value, $fullKey);
            } elseif (is_bool($value)) {
                // Stripe expects "true"/"false" strings, not "1"/"0"
                $result[] = urlencode($fullKey) . '=' . ($value ? 'true' : 'false');
            } elseif ($value === null) {
                // Skip null values
                continue;
            } else {
                $result[] = urlencode($fullKey) . '=' . urlencode((string)$value);
            }
        }

        return implode('&', $result);
    }

    // ============================================================
    // STRIPE BILLING / SUBSCRIPTIONS (v2 API Features)
    // ============================================================

    /**
     * Create or get a Stripe Customer
     *
     * @param array $data Customer data (email, name, metadata)
     * @return array Result with customer_id
     */
    public function createCustomer(array $data): array {
        $params = [
            'email' => $data['email'],
            'name' => $data['name'] ?? null,
            'metadata' => $data['metadata'] ?? []
        ];

        // Remove null values
        $params = array_filter($params, fn($v) => $v !== null);

        $response = $this->request('POST', '/customers', $params);

        return [
            'success' => !isset($response['error']),
            'customer_id' => $response['id'] ?? null,
            'email' => $response['email'] ?? null,
            'error' => $response['error']['message'] ?? null
        ];
    }

    /**
     * Get customer by email
     *
     * @param string $email Customer email
     * @return array Customer data or null
     */
    public function getCustomerByEmail(string $email): array {
        $response = $this->request('GET', '/customers?email=' . urlencode($email) . '&limit=1');

        if (!empty($response['data'][0])) {
            return [
                'success' => true,
                'customer_id' => $response['data'][0]['id'],
                'customer' => $response['data'][0]
            ];
        }

        return [
            'success' => false,
            'customer_id' => null,
            'error' => 'Customer not found'
        ];
    }

    /**
     * Get or create customer
     *
     * @param array $data Customer data
     * @return array Customer result
     */
    public function getOrCreateCustomer(array $data): array {
        // Try to find existing customer
        $existing = $this->getCustomerByEmail($data['email']);
        if ($existing['success']) {
            return $existing;
        }

        // Create new customer
        return $this->createCustomer($data);
    }

    /**
     * Create a Product in Stripe
     *
     * @param array $data Product data
     * @return array Result
     */
    public function createProduct(array $data): array {
        $params = [
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'metadata' => $data['metadata'] ?? []
        ];

        $params = array_filter($params, fn($v) => $v !== null);

        $response = $this->request('POST', '/products', $params);

        return [
            'success' => !isset($response['error']),
            'product_id' => $response['id'] ?? null,
            'error' => $response['error']['message'] ?? null
        ];
    }

    /**
     * Create a Price for a Product
     *
     * @param array $data Price data
     * @return array Result
     */
    public function createPrice(array $data): array {
        $params = [
            'product' => $data['product_id'],
            'unit_amount' => (int)$data['amount'], // Already in ore/cents
            'currency' => strtolower($data['currency'] ?? 'sek'),
        ];

        // Recurring price (subscription)
        if (!empty($data['recurring'])) {
            $params['recurring'] = [
                'interval' => $data['interval'] ?? 'month',
                'interval_count' => $data['interval_count'] ?? 1
            ];
        }

        $response = $this->request('POST', '/prices', $params);

        return [
            'success' => !isset($response['error']),
            'price_id' => $response['id'] ?? null,
            'error' => $response['error']['message'] ?? null
        ];
    }

    /**
     * Create a Subscription
     *
     * @param array $data Subscription data
     * @return array Result
     */
    public function createSubscription(array $data): array {
        $params = [
            'customer' => $data['customer_id'],
            'items' => [
                ['price' => $data['price_id']]
            ],
            'payment_behavior' => 'default_incomplete',
            'payment_settings' => [
                'save_default_payment_method' => 'on_subscription'
            ],
            'expand' => ['latest_invoice.payment_intent']
        ];

        // Add trial period if specified
        if (!empty($data['trial_days'])) {
            $params['trial_period_days'] = $data['trial_days'];
        }

        // Add metadata
        if (!empty($data['metadata'])) {
            $params['metadata'] = $data['metadata'];
        }

        $response = $this->request('POST', '/subscriptions', $params);

        return [
            'success' => !isset($response['error']),
            'subscription_id' => $response['id'] ?? null,
            'status' => $response['status'] ?? null,
            'client_secret' => $response['latest_invoice']['payment_intent']['client_secret'] ?? null,
            'error' => $response['error']['message'] ?? null
        ];
    }

    /**
     * Get Subscription details
     *
     * @param string $subscriptionId Subscription ID
     * @return array Subscription details
     */
    public function getSubscription(string $subscriptionId): array {
        $response = $this->request('GET', "/subscriptions/{$subscriptionId}");

        return [
            'success' => !isset($response['error']),
            'id' => $response['id'] ?? null,
            'status' => $response['status'] ?? null,
            'current_period_start' => isset($response['current_period_start'])
                ? date('Y-m-d H:i:s', $response['current_period_start']) : null,
            'current_period_end' => isset($response['current_period_end'])
                ? date('Y-m-d H:i:s', $response['current_period_end']) : null,
            'cancel_at_period_end' => $response['cancel_at_period_end'] ?? false,
            'customer' => $response['customer'] ?? null,
            'error' => $response['error']['message'] ?? null
        ];
    }

    /**
     * Cancel Subscription
     *
     * @param string $subscriptionId Subscription ID
     * @param bool $atPeriodEnd Cancel at end of billing period
     * @return array Result
     */
    public function cancelSubscription(string $subscriptionId, bool $atPeriodEnd = true): array {
        if ($atPeriodEnd) {
            // Cancel at period end (recommended)
            $response = $this->request('POST', "/subscriptions/{$subscriptionId}", [
                'cancel_at_period_end' => 'true'
            ]);
        } else {
            // Cancel immediately
            $response = $this->request('DELETE', "/subscriptions/{$subscriptionId}");
        }

        return [
            'success' => !isset($response['error']),
            'status' => $response['status'] ?? null,
            'canceled_at' => isset($response['canceled_at'])
                ? date('Y-m-d H:i:s', $response['canceled_at']) : null,
            'error' => $response['error']['message'] ?? null
        ];
    }

    /**
     * Reactivate a subscription that was set to cancel
     *
     * @param string $subscriptionId Subscription ID
     * @return array Result
     */
    public function reactivateSubscription(string $subscriptionId): array {
        $response = $this->request('POST', "/subscriptions/{$subscriptionId}", [
            'cancel_at_period_end' => 'false'
        ]);

        return [
            'success' => !isset($response['error']),
            'status' => $response['status'] ?? null,
            'error' => $response['error']['message'] ?? null
        ];
    }

    /**
     * Create Checkout Session for subscription
     *
     * @param array $data Session data
     * @return array Result with checkout URL
     */
    public function createSubscriptionCheckout(array $data): array {
        $params = [
            'mode' => 'subscription',
            'line_items' => [
                [
                    'price' => $data['price_id'],
                    'quantity' => 1
                ]
            ],
            'success_url' => $data['success_url'],
            'cancel_url' => $data['cancel_url'],
        ];

        // Add customer if we have their email
        if (!empty($data['customer_email'])) {
            $params['customer_email'] = $data['customer_email'];
        }

        // Or use existing customer ID
        if (!empty($data['customer_id'])) {
            $params['customer'] = $data['customer_id'];
            unset($params['customer_email']);
        }

        // Add metadata
        if (!empty($data['metadata'])) {
            $params['subscription_data'] = [
                'metadata' => $data['metadata']
            ];
        }

        // Trial period
        if (!empty($data['trial_days'])) {
            $params['subscription_data']['trial_period_days'] = $data['trial_days'];
        }

        $response = $this->request('POST', '/checkout/sessions', $params);

        return [
            'success' => !isset($response['error']),
            'session_id' => $response['id'] ?? null,
            'url' => $response['url'] ?? null,
            'error' => $response['error']['message'] ?? null
        ];
    }

    /**
     * Create Billing Portal Session
     * Allows customers to manage their subscription
     *
     * @param string $customerId Stripe Customer ID
     * @param string $returnUrl URL to return to after portal
     * @return array Result with portal URL
     */
    public function createBillingPortalSession(string $customerId, string $returnUrl): array {
        $params = [
            'customer' => $customerId,
            'return_url' => $returnUrl
        ];

        $response = $this->request('POST', '/billing_portal/sessions', $params);

        return [
            'success' => !isset($response['error']),
            'url' => $response['url'] ?? null,
            'error' => $response['error']['message'] ?? null
        ];
    }

    /**
     * Get all subscriptions for a customer
     *
     * @param string $customerId Stripe Customer ID
     * @return array List of subscriptions
     */
    public function getCustomerSubscriptions(string $customerId): array {
        $response = $this->request('GET', '/subscriptions?customer=' . urlencode($customerId));

        return [
            'success' => !isset($response['error']),
            'subscriptions' => $response['data'] ?? [],
            'error' => $response['error']['message'] ?? null
        ];
    }

    /**
     * List invoices for a subscription
     *
     * @param string $subscriptionId Subscription ID
     * @param int $limit Max invoices to return
     * @return array List of invoices
     */
    public function getSubscriptionInvoices(string $subscriptionId, int $limit = 10): array {
        $response = $this->request('GET', '/invoices?subscription=' . urlencode($subscriptionId) . '&limit=' . $limit);

        return [
            'success' => !isset($response['error']),
            'invoices' => $response['data'] ?? [],
            'error' => $response['error']['message'] ?? null
        ];
    }

    /**
     * Create Login Link for Express Account Dashboard
     *
     * @param string $accountId Stripe Account ID
     * @return array Result with login URL
     */
    public function createLoginLink(string $accountId): array {
        $response = $this->request('POST', "/accounts/{$accountId}/login_links");

        return [
            'success' => !isset($response['error']),
            'url' => $response['url'] ?? null,
            'error' => $response['error']['message'] ?? null
        ];
    }

    // ============================================================
    // MULTI-SELLER TRANSFERS (Separate Charges and Transfers)
    // ============================================================

    /**
     * Get Charge ID from Payment Intent
     *
     * After a successful payment, we need the charge_id to use as
     * source_transaction when creating transfers to sellers.
     *
     * @param string $paymentIntentId Payment Intent ID
     * @return array Result with charge_id
     */
    public function getChargeFromPaymentIntent(string $paymentIntentId): array {
        $response = $this->request('GET', "/payment_intents/{$paymentIntentId}");

        if (isset($response['error'])) {
            return [
                'success' => false,
                'error' => $response['error']['message'] ?? 'Unknown error'
            ];
        }

        // The latest_charge field contains the charge ID
        $chargeId = $response['latest_charge'] ?? null;

        // Fallback: check charges array if latest_charge is not available
        if (!$chargeId && !empty($response['charges']['data'][0]['id'])) {
            $chargeId = $response['charges']['data'][0]['id'];
        }

        return [
            'success' => $chargeId !== null,
            'charge_id' => $chargeId,
            'amount' => isset($response['amount']) ? $response['amount'] / 100 : 0,
            'currency' => $response['currency'] ?? 'sek',
            'transfer_group' => $response['transfer_group'] ?? null,
            'error' => $chargeId ? null : 'No charge found for payment intent'
        ];
    }

    /**
     * Create Transfer to Connected Account
     *
     * Used in multi-seller orders: after the platform receives payment,
     * create individual transfers to each seller's connected account.
     *
     * @param array $data Transfer data:
     *   - amount: Amount in SEK (will be converted to öre)
     *   - currency: Currency (default 'sek')
     *   - destination: Connected account ID (acct_...)
     *   - source_transaction: Charge ID from the original payment (ch_...)
     *   - transfer_group: Group ID for tracking (e.g., order_id)
     *   - description: Optional description
     *   - metadata: Optional metadata
     * @return array Result with transfer_id
     */
    public function createTransfer(array $data): array {
        $params = [
            'amount' => (int)($data['amount'] * 100), // Convert to öre
            'currency' => strtolower($data['currency'] ?? 'sek'),
            'destination' => $data['destination'], // Connected account ID
        ];

        // Link to source charge (required for proper fund tracking)
        if (!empty($data['source_transaction'])) {
            $params['source_transaction'] = $data['source_transaction'];
        }

        // Transfer group for tracking (matches order_id)
        if (!empty($data['transfer_group'])) {
            $params['transfer_group'] = $data['transfer_group'];
        }

        // Optional description
        if (!empty($data['description'])) {
            $params['description'] = $data['description'];
        }

        // Optional metadata
        if (!empty($data['metadata'])) {
            $params['metadata'] = $data['metadata'];
        }

        $response = $this->request('POST', '/transfers', $params);

        return [
            'success' => !isset($response['error']),
            'transfer_id' => $response['id'] ?? null,
            'amount' => isset($response['amount']) ? $response['amount'] / 100 : 0,
            'destination' => $response['destination'] ?? null,
            'error' => $response['error']['message'] ?? null
        ];
    }

    /**
     * Create Transfer Reversal (refund a transfer)
     *
     * If a customer requests a refund after transfers have been made,
     * you may need to reverse transfers from connected accounts.
     *
     * @param string $transferId Transfer ID to reverse
     * @param float|null $amount Amount to reverse (null for full reversal)
     * @return array Result
     */
    public function createTransferReversal(string $transferId, ?float $amount = null): array {
        $params = [];

        if ($amount !== null) {
            $params['amount'] = (int)($amount * 100);
        }

        $response = $this->request('POST', "/transfers/{$transferId}/reversals", $params);

        return [
            'success' => !isset($response['error']),
            'reversal_id' => $response['id'] ?? null,
            'amount' => isset($response['amount']) ? $response['amount'] / 100 : 0,
            'error' => $response['error']['message'] ?? null
        ];
    }

    /**
     * List Transfers for a transfer group (order)
     *
     * Useful for viewing all transfers made for a specific order.
     *
     * @param string $transferGroup Transfer group ID (e.g., order_id)
     * @param int $limit Max transfers to return
     * @return array List of transfers
     */
    public function listTransfers(string $transferGroup, int $limit = 100): array {
        $response = $this->request('GET', '/transfers?transfer_group=' . urlencode($transferGroup) . '&limit=' . $limit);

        return [
            'success' => !isset($response['error']),
            'transfers' => $response['data'] ?? [],
            'total' => count($response['data'] ?? []),
            'error' => $response['error']['message'] ?? null
        ];
    }

    /**
     * Get Balance for Platform Account
     *
     * Shows available and pending balance on the platform account.
     * Useful for verifying funds before making transfers.
     *
     * @return array Balance data
     */
    public function getBalance(): array {
        $response = $this->request('GET', '/balance');

        if (isset($response['error'])) {
            return [
                'success' => false,
                'error' => $response['error']['message'] ?? 'Unknown error'
            ];
        }

        $available = [];
        $pending = [];

        foreach ($response['available'] ?? [] as $bal) {
            $available[$bal['currency']] = $bal['amount'] / 100;
        }

        foreach ($response['pending'] ?? [] as $bal) {
            $pending[$bal['currency']] = $bal['amount'] / 100;
        }

        return [
            'success' => true,
            'available' => $available,
            'pending' => $pending
        ];
    }

    /**
     * Get Balance for Connected Account
     *
     * @param string $accountId Connected account ID
     * @return array Balance data
     */
    public function getConnectedAccountBalance(string $accountId): array {
        $response = $this->request('GET', '/balance', null, [
            'Stripe-Account: ' . $accountId
        ]);

        if (isset($response['error'])) {
            return [
                'success' => false,
                'error' => $response['error']['message'] ?? 'Unknown error'
            ];
        }

        $available = [];
        $pending = [];

        foreach ($response['available'] ?? [] as $bal) {
            $available[$bal['currency']] = $bal['amount'] / 100;
        }

        foreach ($response['pending'] ?? [] as $bal) {
            $pending[$bal['currency']] = $bal['amount'] / 100;
        }

        return [
            'success' => true,
            'available' => $available,
            'pending' => $pending
        ];
    }
}
