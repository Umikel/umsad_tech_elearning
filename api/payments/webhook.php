<?php
/**
 * Paystack webhook receiver.
 *
 * This endpoint deliberately does not use browser sessions or CSRF tokens.
 * Authenticity is established from Paystack's HMAC-SHA512 signature over the
 * exact raw request body, then successful-charge details are fetched again
 * from Paystack before fulfillment.
 */

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/includes/config.php';
require_once dirname(__DIR__, 2) . '/includes/Database.php';
require_once dirname(__DIR__, 2) . '/includes/helpers.php';
require_once dirname(__DIR__, 2) . '/includes/Paystack.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    jsonResponse(false, 'Method not allowed.', [], 405);
}

$declaredLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($declaredLength > 1048576) {
    jsonResponse(false, 'Webhook payload is too large.', [], 413);
}

$rawBody = file_get_contents('php://input');
if ($rawBody === false || $rawBody === '' || strlen($rawBody) > 1048576) {
    jsonResponse(false, 'Invalid webhook payload.', [], 400);
}

$mode = defined('PAYSTACK_MODE') ? strtolower((string) PAYSTACK_MODE) : '';
$secretKey = defined('PAYSTACK_SECRET_KEY') ? trim((string) PAYSTACK_SECRET_KEY) : '';
if (!in_array($mode, ['test', 'live'], true)
    || !preg_match('/^sk_(test|live)_[A-Za-z0-9]+$/', $secretKey, $keyMatches)
    || $keyMatches[1] !== $mode) {
    error_log('Paystack webhook received while gateway credentials were not configured.');
    jsonResponse(false, 'Webhook is unavailable.', [], 503);
}

$signature = strtolower(trim((string) ($_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '')));
if (!preg_match('/^[a-f0-9]{128}$/', $signature)) {
    jsonResponse(false, 'Invalid webhook signature.', [], 401);
}

$expectedSignature = hash_hmac('sha512', $rawBody, $secretKey);
if (!hash_equals($expectedSignature, $signature)) {
    jsonResponse(false, 'Invalid webhook signature.', [], 401);
}

try {
    $event = json_decode($rawBody, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
} catch (JsonException $e) {
    jsonResponse(false, 'Malformed webhook payload.', [], 400);
}

if (!is_array($event) || !isset($event['event']) || !is_string($event['event'])) {
    jsonResponse(false, 'Invalid webhook event.', [], 400);
}

// Unsupported events are acknowledged so Paystack does not retry them.
if ($event['event'] !== 'charge.success') {
    jsonResponse(true, 'Webhook acknowledged.', ['processed' => false], 200);
}

if (!isset($event['data']) || !is_array($event['data'])) {
    jsonResponse(false, 'Invalid charge event.', [], 400);
}

$reference = isset($event['data']['reference']) && is_string($event['data']['reference'])
    ? trim($event['data']['reference'])
    : '';
if ($reference === ''
    || strlen($reference) > 100
    || !preg_match('/^[A-Za-z0-9._=-]+$/', $reference)) {
    jsonResponse(false, 'Invalid payment reference.', [], 400);
}

try {
    $db = new Database();
    $paystack = new Paystack($db);

    // Treat the signed webhook as a notification, not as the source of truth.
    // Re-fetch the transaction from Paystack before granting course access.
    $verifiedData = $paystack->verifyReference($reference);
    $result = $paystack->fulfillSuccessfulPayment($verifiedData);

    // Fulfillment has already queued its idempotent notifications. Respond to
    // Paystack promptly and let the outbox worker deliver email separately.

    if ($result['requires_review']) {
        error_log(
            'Paystack payment ' . $result['payment_id']
            . ' completed but requires duplicate-payment review.'
        );
    }

    jsonResponse(true, 'Webhook processed.', [
        'processed' => true,
        'payment_id' => $result['payment_id'],
        'already_completed' => $result['already_completed'],
        'requires_review' => $result['requires_review'],
    ], 200);
} catch (PaystackException $e) {
    error_log('Paystack webhook could not be fulfilled: ' . $e->getMessage());
    jsonResponse(false, 'Webhook could not be processed.', [], $e->getHttpStatus());
} catch (Throwable $e) {
    error_log('Paystack webhook failed: ' . $e->getMessage());
    jsonResponse(false, 'Webhook could not be processed.', [], 500);
}
