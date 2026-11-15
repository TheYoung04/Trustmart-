<?php
/**
 * Payment Callback Handler
 * 
 * Receives payment notifications from ToyyibPay gateway
 * This endpoint is called by the payment gateway, not by the user
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/payment.php';

// Log callback for debugging (optional)
$log_file = __DIR__ . '/../logs/payment_callback.log';
if (!file_exists(dirname($log_file))) {
    @mkdir(dirname($log_file), 0755, true);
}
@file_put_contents($log_file, date('Y-m-d H:i:s') . ' - ' . json_encode($_POST) . "\n", FILE_APPEND);

// Get callback parameters. payment_input() replaces the deprecated
// FILTER_SANITIZE_STRING (removed in PHP 8.2) and rejects malformed
// identifiers early. Signature verification is HMAC, and every DB read
// below uses prepared statements.
// ToyyibPay's real webhook posts: refno (gateway reference), status
// (1=success / 2=pending / 3=fail), billcode, order_id (OUR payment_reference,
// set via billExternalReferenceNo), amount and hash. The legacy HMAC path used
// refno + status_id + signature, so read both spellings and normalise.
$refno          = payment_input('refno',          64, '/^[A-Za-z0-9_\-]+$/');
$order_id       = payment_input('order_id',       64, '/^[A-Za-z0-9_\-]+$/');
$status         = payment_input('status',         16, '/^[A-Za-z0-9_]+$/');
$status_id      = payment_input('status_id',      16, '/^[A-Za-z0-9_]+$/');
$billcode       = payment_input('billcode',       64, '/^[A-Za-z0-9_\-]+$/');
$amount         = payment_input('amount',         16, '/^\d+(\.\d{1,2})?$/');
$transaction_id = payment_input('transaction_id', 64, '/^[A-Za-z0-9_\-]+$/');
$signature      = payment_input('signature',     128, '/^[a-fA-F0-9]+$/');

// Our internal reference is order_id for ToyyibPay, refno for the legacy
// simulator path; status is 'status' (ToyyibPay) or 'status_id' (legacy).
$our_ref    = ($order_id !== null && $order_id !== '') ? $order_id : $refno;
$pay_status = ($status   !== null && $status   !== '') ? $status   : $status_id;

// Validate required parameters
if (empty($our_ref) || empty($pay_status)) {
    http_response_code(400);
    exit('Invalid callback parameters');
}

// Fetch the payment by OUR reference to check method, amount and the bill
// code we stored when the bill was created.
$stmt = $pdo->prepare("
    SELECT payment_method, amount, gateway_response
    FROM payments
    WHERE payment_reference = ?
    LIMIT 1
");
$stmt->execute([$our_ref]);
$payment_check = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment_check) {
    http_response_code(404);
    exit('Payment not found');
}

if ($payment_check['payment_method'] !== 'toyyibpay') {
    // Signature is REQUIRED for simulation gateway to prevent fraud
    if (empty($signature)) {
        http_response_code(401);
        exit('Missing signature - callback authentication failed');
    }
    
    // Verify the signature matches
    if (!verify_payment_signature($our_ref, $pay_status, $payment_check['amount'], $signature)) {
        http_response_code(403);
        exit('Invalid signature - callback authentication failed');
    }
}

// Verify payment with ToyyibPay if using their gateway
if ($payment_check['payment_method'] === 'toyyibpay' && TOYYIBPAY_ENABLED) {
    // Bind the callback to the bill WE created for this payment. Without this
    // a forged billcode (some other already-paid bill) could authorise this
    // order, because verifyPayment() trusts the billcode in the request. Check
    // both keys so a retry (gateway_response now holds the raw POST) still matches.
    $stored = json_decode((string) ($payment_check['gateway_response'] ?? ''), true);
    $expected_billcode = is_array($stored) ? (string) ($stored['billCode'] ?? $stored['billcode'] ?? '') : '';
    if ($expected_billcode === '' || !hash_equals($expected_billcode, (string) $billcode)) {
        http_response_code(400);
        exit('Bill code mismatch');
    }

    try {
        require_once __DIR__ . '/toyyibpay_helper.php';
        
        $toyyibpay = new ToyyibPayHelper();
        $verified = $toyyibpay->verifyPayment($_POST);
        
        if (!$verified) {
            http_response_code(400);
            exit('Payment verification failed');
        }
        
    } catch (Exception $e) {
        http_response_code(500);
        exit('Verification error: ' . $e->getMessage());
    }
}

// Terminal-state transition is delegated to the SINGLE authoritative
// writer, shared with the local simulator. HMAC verification (and, for
// ToyyibPay, server-side bill verification) already happened above, so
// this is the only place payment state actually changes. The writer is
// idempotent and race-safe (row locked FOR UPDATE, only pending/processing
// rows can move) and — unlike the old inline code — a late "failed"
// notification can never downgrade an already-paid payment.
$result = apply_payment_gateway_result(
    $pdo,
    $our_ref,
    ($pay_status === '1' || $pay_status === 'success'),
    json_encode($_POST)
);

switch ($result['state']) {
    case 'not_found':
        http_response_code(404);
        exit('Payment not found');
    case 'error':
        http_response_code(500);
        exit('Database error: ' . $result['error']);
    case 'already_processed':
        http_response_code(200);
        exit('Payment already processed');
    case 'needs_reconciliation':
        // A genuine payment arrived for an order that is no longer payable
        // (e.g. cancelled mid-payment). Acknowledge so the gateway stops
        // retrying; the funds are captured and flagged for manual refund.
        http_response_code(200);
        exit('Reconciliation required');
    case 'completed':
        http_response_code(200);
        exit('OK');
    case 'failed':
        http_response_code(200);
        exit('Payment failed');
    default:
        http_response_code(500);
        exit('Unexpected gateway state');
}
