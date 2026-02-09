<?php
/**
 * Swebank Pay Client - Handles Swebank Pay Checkout payments
 *
 * Uses official Swebank Pay SDK: swedbank-pay/swedbank-pay-sdk-php
 *
 * @package TheHUB\Payment
 */

namespace TheHUB\Payment;

// Note: Swebank Pay SDK classes will be autoloaded via Composer
// use SwedbankPay\Api\Client\Client;
// use SwedbankPay\Api\Service\Paymentorder\Resource\Request\Paymentorder;

class SwebankPayClient {
    private $token;
    private $payeeId;
    private $mode; // 'test' or 'production'
    private $baseUrl;

    /**
     * Constructor
     *
     * @param string $token Swebank Pay API token
     * @param string $payeeId Payee ID (merchant ID)
     * @param string $mode 'test' or 'production'
     */
    public function __construct(string $token, string $payeeId, string $mode = 'test') {
        $this->token = $token;
        $this->payeeId = $payeeId;
        $this->mode = $mode;

        // Swebank Pay API endpoints
        $this->baseUrl = ($mode === 'production')
            ? 'https://api.payex.com'
            : 'https://api.externalintegration.payex.com';
    }

    /**
     * Create Payment Order
     *
     * Similar to Stripe's Payment Intent but redirects user to Swebank Pay
     * for payment completion.
     *
     * @param array $data Payment data:
     *   - amount: Total amount in SEK
     *   - currency: Currency code (default 'SEK')
     *   - description: Order description
     *   - order_id: Internal order reference
     *   - payer_email: Customer email
     *   - payer_phone: Customer phone
     *   - items: Array of line items
     *   - urls: Array with completeUrl, cancelUrl, callbackUrl
     * @return array Result with payment_order_id and redirect URL
     */
    public function createPaymentOrder(array $data): array {
        try {
            // Convert amount to öre (smallest currency unit)
            $amountInOre = (int)($data['amount'] * 100);

            // Build payment order request
            $payload = [
                'paymentorder' => [
                    'operation' => 'Purchase',
                    'currency' => strtoupper($data['currency'] ?? 'SEK'),
                    'amount' => $amountInOre,
                    'vatAmount' => 0, // VAT calculation if needed
                    'description' => $data['description'] ?? 'Order',
                    'userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? 'TheHUB/1.0',
                    'language' => 'sv-SE',
                    'generateRecurrenceToken' => false,
                    'urls' => [
                        'completeUrl' => $data['urls']['completeUrl'] ?? '',
                        'cancelUrl' => $data['urls']['cancelUrl'] ?? '',
                        'callbackUrl' => $data['urls']['callbackUrl'] ?? '',
                        'termsOfServiceUrl' => $data['urls']['termsOfServiceUrl'] ?? ''
                    ],
                    'payeeInfo' => [
                        'payeeId' => $this->payeeId,
                        'payeeReference' => $data['order_id'] ?? uniqid('order_'),
                        'payeeName' => 'TheHUB',
                        'orderReference' => $data['order_id'] ?? ''
                    ],
                    'payer' => [
                        'email' => $data['payer_email'] ?? '',
                        'msisdn' => $data['payer_phone'] ?? '',
                        'workPhoneNumber' => '',
                        'homePhoneNumber' => ''
                    ],
                    'orderItems' => $this->buildOrderItems($data['items'] ?? [])
                ]
            ];

            // Make API request
            $response = $this->request('POST', '/psp/paymentorders', $payload);

            if (isset($response['paymentOrder'])) {
                $paymentOrder = $response['paymentOrder'];

                // Extract redirect URL from operations
                $redirectUrl = null;
                foreach ($paymentOrder['operations'] ?? [] as $operation) {
                    if ($operation['rel'] === 'redirect-checkout') {
                        $redirectUrl = $operation['href'];
                        break;
                    }
                }

                return [
                    'success' => true,
                    'payment_order_id' => $paymentOrder['id'] ?? null,
                    'redirect_url' => $redirectUrl,
                    'status' => $paymentOrder['status'] ?? null
                ];
            }

            return [
                'success' => false,
                'error' => 'Failed to create payment order',
                'response' => $response
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get Payment Order status
     *
     * @param string $paymentOrderId Payment Order ID
     * @return array Status result
     */
    public function getPaymentOrder(string $paymentOrderId): array {
        try {
            $response = $this->request('GET', $paymentOrderId);

            if (isset($response['paymentOrder'])) {
                $paymentOrder = $response['paymentOrder'];

                return [
                    'success' => true,
                    'id' => $paymentOrder['id'] ?? null,
                    'status' => $paymentOrder['status'] ?? null,
                    'paid' => ($paymentOrder['status'] ?? '') === 'Paid',
                    'amount' => isset($paymentOrder['amount']) ? $paymentOrder['amount'] / 100 : 0,
                    'currency' => $paymentOrder['currency'] ?? 'SEK',
                    'payment_method' => $paymentOrder['instrument'] ?? null
                ];
            }

            return [
                'success' => false,
                'error' => 'Payment order not found'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Capture Payment
     *
     * For authorized payments that need to be captured.
     *
     * @param string $paymentOrderId Payment Order ID
     * @param float|null $amount Amount to capture (null for full)
     * @return array Capture result
     */
    public function capturePayment(string $paymentOrderId, ?float $amount = null): array {
        try {
            // Get payment order to find capture URL
            $paymentOrder = $this->getPaymentOrder($paymentOrderId);

            if (!$paymentOrder['success']) {
                return $paymentOrder;
            }

            // TODO: Implement capture via Swebank Pay SDK
            // This requires finding the capture operation URL from the payment order

            return [
                'success' => false,
                'error' => 'Capture not yet implemented - needs Swebank Pay SDK integration'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Cancel Payment Order
     *
     * @param string $paymentOrderId Payment Order ID
     * @return array Cancel result
     */
    public function cancelPayment(string $paymentOrderId): array {
        try {
            // TODO: Implement cancel via Swebank Pay SDK

            return [
                'success' => false,
                'error' => 'Cancel not yet implemented - needs Swebank Pay SDK integration'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Refund Payment
     *
     * @param string $paymentOrderId Payment Order ID
     * @param float|null $amount Amount to refund (null for full refund)
     * @return array Refund result
     */
    public function refundPayment(string $paymentOrderId, ?float $amount = null): array {
        try {
            // TODO: Implement refund via Swebank Pay SDK

            return [
                'success' => false,
                'error' => 'Refund not yet implemented - needs Swebank Pay SDK integration'
            ];

        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Verify Callback Signature
     *
     * Swebank Pay sends callbacks to notify about payment status changes.
     *
     * @param string $payload Raw request body
     * @param string $signature Signature header
     * @return array Verification result
     */
    public function verifyCallback(string $payload, string $signature): array {
        try {
            // TODO: Implement callback verification
            // Swebank Pay uses different signature method than Stripe

            return [
                'valid' => false,
                'error' => 'Callback verification not yet implemented'
            ];

        } catch (\Exception $e) {
            return [
                'valid' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Build order items array for Swebank Pay
     *
     * @param array $items Cart items
     * @return array Formatted order items
     */
    private function buildOrderItems(array $items): array {
        $orderItems = [];

        foreach ($items as $item) {
            $orderItems[] = [
                'reference' => $item['reference'] ?? $item['id'] ?? '',
                'name' => $item['name'] ?? '',
                'type' => 'PRODUCT',
                'class' => 'ProductGroup1',
                'quantity' => $item['quantity'] ?? 1,
                'quantityUnit' => 'pcs',
                'unitPrice' => (int)(($item['unit_price'] ?? 0) * 100), // Convert to öre
                'vatPercent' => 0, // VAT if applicable
                'amount' => (int)(($item['amount'] ?? 0) * 100), // Total in öre
                'vatAmount' => 0
            ];
        }

        return $orderItems;
    }

    /**
     * Make API request to Swebank Pay
     *
     * @param string $method HTTP method
     * @param string $endpoint API endpoint
     * @param array|null $data Request data
     * @return array Response
     */
    private function request(string $method, string $endpoint, ?array $data = null): array {
        $url = $endpoint;

        // If endpoint doesn't start with http, prepend base URL
        if (!str_starts_with($endpoint, 'http')) {
            $url = $this->baseUrl . $endpoint;
        }

        $ch = curl_init($url);

        $headers = [
            'Authorization: Bearer ' . $this->token,
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

        // Log response for debugging (remove in production)
        error_log("Swebank Pay API Response ({$method} {$endpoint}): " . substr($response, 0, 500));

        return $decoded ?? [];
    }
}
