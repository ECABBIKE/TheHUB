<?php
/**
 * Stripe Gateway
 * Handles card payments via single platform Stripe account
 *
 * @package TheHUB\Payment\Gateways
 */

namespace TheHUB\Payment\Gateways;

use TheHUB\Payment\GatewayInterface;
use TheHUB\Payment\StripeClient;

class StripeGateway implements GatewayInterface {
    private $pdo;
    private $client;

    public function __construct($pdo) {
        $this->pdo = $pdo;

        $apiKey = $this->getStripeKey();
        if ($apiKey) {
            $this->client = new StripeClient($apiKey);
        }
    }

    public function getName(): string {
        return 'Stripe (Kort / Apple Pay / Google Pay)';
    }

    public function getCode(): string {
        return 'stripe';
    }

    /**
     * Get Stripe API key from environment
     */
    private function getStripeKey(): ?string {
        $key = getenv('STRIPE_SECRET_KEY');
        if ($key) {
            return $key;
        }

        if (function_exists('env')) {
            $key = env('STRIPE_SECRET_KEY');
            if ($key) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Check if gateway is available
     * Simply checks if Stripe API key is configured
     */
    public function isAvailable(int $paymentRecipientId): bool {
        return $this->client !== null;
    }

    public function initiatePayment(array $orderData): array {
        if (!$this->client) {
            return [
                'success' => false,
                'error' => 'Stripe API key not configured'
            ];
        }

        $paymentData = [
            'amount' => (float)$orderData['total_amount'],
            'currency' => 'SEK',
            'description' => $orderData['event_name'] ?? 'TheHUB Registration',
            'email' => $orderData['customer_email'] ?? null,
            'payment_method_types' => ['card'],
            'metadata' => [
                'order_id' => $orderData['id'] ?? null,
                'order_number' => $orderData['order_number'] ?? null,
                'event_id' => $orderData['event_id'] ?? null
            ]
        ];

        $result = $this->client->createPaymentIntent($paymentData);

        return [
            'success' => $result['success'],
            'transaction_id' => $result['payment_intent_id'] ?? null,
            'client_secret' => $result['client_secret'] ?? null,
            'status' => $result['status'] ?? 'pending',
            'redirect_url' => null,
            'metadata' => [
                'stripe_status' => $result['status'] ?? null
            ],
            'error' => $result['error'] ?? null
        ];
    }

    public function checkStatus(string $transactionId): array {
        if (!$this->client) {
            return ['success' => false, 'error' => 'Stripe not configured'];
        }

        $result = $this->client->getPaymentIntent($transactionId);

        return [
            'success' => $result['success'],
            'status' => $result['status'] ?? 'unknown',
            'paid' => $result['paid'] ?? false,
            'amount' => $result['amount'] ?? 0,
            'payment_reference' => $transactionId,
            'error' => $result['error'] ?? null
        ];
    }

    public function refund(string $transactionId, float $amount): array {
        if (!$this->client) {
            return ['success' => false, 'error' => 'Stripe not configured'];
        }

        return $this->client->createRefund($transactionId, $amount);
    }

    public function cancel(string $transactionId): array {
        if (!$this->client) {
            return ['success' => false, 'error' => 'Stripe not configured'];
        }

        return $this->client->cancelPaymentIntent($transactionId);
    }

    /**
     * Get Stripe client for direct API access
     */
    public function getClient(): ?StripeClient {
        return $this->client;
    }

    /**
     * Verify webhook signature
     */
    public function verifyWebhook(string $payload, string $sigHeader, string $secret): array {
        if (!$this->client) {
            return ['valid' => false, 'error' => 'Stripe not configured'];
        }

        return $this->client->verifyWebhookSignature($payload, $sigHeader, $secret);
    }
}
