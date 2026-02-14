<?php
/**
 * Stripe Webhook Proxy
 *
 * Stripe is configured to send to /api/stripe-webhook.php
 * but the actual handler lives in /api/webhooks/stripe-webhook.php
 *
 * This file simply includes the real handler.
 */
require __DIR__ . '/webhooks/stripe-webhook.php';
