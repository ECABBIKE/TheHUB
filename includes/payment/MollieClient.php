<?php
/**
 * Mollie Client - Handles Mollie payments
 *
 * Supports Swish, card payments (Apple Pay, Google Pay)
 * All payments go to platform account with manual payouts to organizers
 *
 * @package TheHUB\Payment
 */

namespace TheHUB\Payment;

class MollieClient {
    private $apiKey;
    private $baseUrl = 'https://api.mollie.com/v2';

    public function __construct(string $apiKey) {
        $this->apiKey = $apiKey;
    }

    /**
     * Create Payment
     *
     * Creates a Mollie payment and returns checkout URL.
     * All payments go directly to platform account.
     *
     * @param array $data Payment data:
     *   - amount: Amount in SEK (will be converted to string format)
     *   - description: Payment description
     *   - redirectUrl: URL to redirect after payment
     *   - webhookUrl: URL for webhook notifications
     *   - metadata: Optional metadata
     *   - method: Payment method ('creditcard', 'swish', or null for all)
     *
     * @return array Result with payment_id and checkout_url
     */
    public function createPayment(array $data): array {
        $params = [
            'amount' => [
                'currency' => strtoupper($data['currency'] ?? 'SEK'),
                'value' => number_format($data['amount'], 2, '.', '') // Mollie expects "123.45" format
            ],
            'description' => $data['description'] ?? 'Betalning',
            'redirectUrl' => $data['redirectUrl'],
            'webhookUrl' => $data['webhookUrl'] ?? null,
            'metadata' => $data['metadata'] ?? []
        ];

        // Specify payment method if provided
        // null = show all available methods
        // 'creditcard' = only card payments
        // 'swish' = only Swish (if enabled in Mollie account)
        if (!empty($data['method'])) {
            $params['method'] = $data['method'];
        }

        // Add locale for Swedish interface
        $params['locale'] = $data['locale'] ?? 'sv_SE';

        $response = $this->request('POST', '/payments', $params);

        if (isset($response['error'])) {
            return [
                'success' => false,
                'error' => $response['error']['message'] ?? 'Mollie API error'
            ];
        }

        return [
            'success' => true,
            'payment_id' => $response['id'] ?? null,
            'checkout_url' => $response['_links']['checkout']['href'] ?? null,
            'status' => $response['status'] ?? null
        ];
    }

    /**
     * Get Payment status
     *
     * @param string $paymentId Mollie Payment ID
     * @return array Payment details
     */
    public function getPayment(string $paymentId): array {
        $response = $this->request('GET', "/payments/{$paymentId}");

        if (isset($response['error'])) {
            return [
                'success' => false,
                'error' => $response['error']['message'] ?? 'Payment not found'
            ];
        }

        // Mollie payment statuses:
        // - open: Payment created, awaiting customer
        // - pending: Payment started (e.g., bank transfer initiated)
        // - paid: Payment completed successfully ✓
        // - failed: Payment failed
        // - expired: Payment expired
        // - canceled: Payment canceled

        $isPaid = ($response['status'] ?? '') === 'paid';

        return [
            'success' => true,
            'id' => $response['id'] ?? null,
            'status' => $response['status'] ?? null,
            'paid' => $isPaid,
            'amount' => isset($response['amount']['value']) ? (float)$response['amount']['value'] : 0,
            'currency' => $response['amount']['currency'] ?? 'SEK',
            'payment_method' => $response['method'] ?? null,
            'metadata' => $response['metadata'] ?? []
        ];
    }

    /**
     * Create refund
     *
     * @param string $paymentId Mollie Payment ID
     * @param float|null $amount Amount to refund (null for full refund)
     * @return array Refund result
     */
    public function createRefund(string $paymentId, ?float $amount = null): array {
        $params = [];

        if ($amount !== null) {
            $params['amount'] = [
                'currency' => 'SEK',
                'value' => number_format($amount, 2, '.', '')
            ];
        }

        $response = $this->request('POST', "/payments/{$paymentId}/refunds", $params);

        if (isset($response['error'])) {
            return [
                'success' => false,
                'error' => $response['error']['message'] ?? 'Refund failed'
            ];
        }

        return [
            'success' => true,
            'refund_id' => $response['id'] ?? null,
            'status' => $response['status'] ?? null,
            'amount' => isset($response['amount']['value']) ? (float)$response['amount']['value'] : 0
        ];
    }

    /**
     * Cancel Payment
     *
     * Only works for payments with status 'open' or 'pending'
     *
     * @param string $paymentId Mollie Payment ID
     * @return array Cancel result
     */
    public function cancelPayment(string $paymentId): array {
        $response = $this->request('DELETE', "/payments/{$paymentId}");

        if (isset($response['error'])) {
            return [
                'success' => false,
                'error' => $response['error']['message'] ?? 'Cancel failed'
            ];
        }

        return [
            'success' => true,
            'status' => $response['status'] ?? null
        ];
    }

    /**
     * List all payment methods available for the account
     *
     * Useful for checking if Swish is enabled
     *
     * @return array List of payment methods
     */
    public function getPaymentMethods(): array {
        $response = $this->request('GET', '/methods/all');

        if (isset($response['error'])) {
            return [
                'success' => false,
                'error' => $response['error']['message'] ?? 'Failed to get methods'
            ];
        }

        $methods = [];
        foreach ($response['_embedded']['methods'] ?? [] as $method) {
            $methods[] = [
                'id' => $method['id'],
                'description' => $method['description'],
                'image' => $method['image']['svg'] ?? null
            ];
        }

        return [
            'success' => true,
            'methods' => $methods
        ];
    }

    /**
     * Verify webhook signature (optional - Mollie doesn't sign webhooks)
     *
     * Mollie recommends fetching the payment via API instead of trusting webhook data.
     * This is already handled by calling getPayment() in webhook handler.
     *
     * @param string $paymentId Payment ID from webhook
     * @return array Verification result
     */
    public function verifyWebhook(string $paymentId): array {
        // Fetch payment to verify it exists and get real status
        return $this->getPayment($paymentId);
    }

    /**
     * Make API request to Mollie
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array|null $data Request data
     * @return array Response
     */
    public function request(string $method, string $endpoint, ?array $data = null): array {
        $url = $this->baseUrl . $endpoint;

        $ch = curl_init($url);

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json'
        ];

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30
        ]);

        if ($data !== null && $method !== 'GET') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['error' => ['message' => 'CURL Error: ' . $error]];
        }

        curl_close($ch);

        $decoded = json_decode($response, true);

        // Mollie returns errors with 'status' and 'title' fields
        if ($httpCode >= 400 && isset($decoded['detail'])) {
            return [
                'error' => [
                    'message' => $decoded['title'] . ': ' . $decoded['detail'],
                    'status' => $decoded['status'] ?? $httpCode
                ]
            ];
        }

        return $decoded ?? [];
    }
}
