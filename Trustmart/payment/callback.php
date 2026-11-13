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
$refno          = payment_input('refno',          64, '/^[A-Za-z0-9_\-]+$/');
$status         = payment_input('status_id',      16, '/^[A-Za-z0-9_]+$/');
$billcode       = payment_input('billcode',       64, '/^[A-Za-z0-9_\-]+$/');
$amount         = payment_input('amount',         16, '/^\d+(\.\d{1,2})?$/');
$transaction_id = payment_input('transaction_id', 64, '/^[A-Za-z0-9_\-]+$/');
$signature      = payment_input('signature',     128, '/^[a-fA-F0-9]+$/');

// Validate required parameters
if (empty($refno) || empty($status)) {
    http_response_code(400);
    exit('Invalid callback parameters');
}

// Verify signature for simulation gateway (non-ToyyibPay payments)
// Note: We need to fetch payment first to check payment_method and amount
$stmt = $pdo->prepare("
    SELECT payment_method, amount
    FROM payments
    WHERE payment_reference = ?
    LIMIT 1
");
$stmt->execute([$refno]);
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
    if (!verify_payment_signature($refno, $status, $payment_check['amount'], $signature)) {
        http_response_code(403);
        exit('Invalid signature - callback authentication failed');
    }
}

// Verify payment with ToyyibPay if using their gateway
if ($payment_check['payment_method'] === 'toyyibpay' && TOYYIBPAY_ENABLED) {
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
    $refno,
    ($status === '1' || $status === 'success'),
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
