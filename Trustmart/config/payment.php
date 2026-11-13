<?php
/**
 * TrustMart Payment Configuration
 * 
 * Supports:
 * - Built-in simulated FPX/e-Wallet gateway (for testing)
 * - ToyyibPay integration (optional, requires API key)
 */

// Load environment variables
require_once __DIR__ . '/env.php';

// Payment gateway mode: 'simulation' or 'toyyibpay'
define('PAYMENT_GATEWAY_MODE', env('PAYMENT_GATEWAY_MODE', 'simulation'));

// Base URL for payment callbacks and returns
define('PAYMENT_BASE_URL', env('PAYMENT_BASE_URL', 'http://localhost/Trustmart'));

// Payment callback secret for HMAC signature verification
// CRITICAL: Set PAYMENT_CALLBACK_SECRET in .env file for production!
define('PAYMENT_CALLBACK_SECRET', env('PAYMENT_CALLBACK_SECRET', 'default_dev_secret_' . md5(__DIR__)));

// Simulated gateway settings
define('PAYMENT_SIM_SUCCESS_RATE', 0.95); // 95% success rate in simulation
define('PAYMENT_SIM_DELAY_SECONDS', 3); // Simulate 3-second processing

// ToyyibPay settings (optional - fill these if using ToyyibPay)
define('TOYYIBPAY_ENABLED', env('TOYYIBPAY_ENABLED', false));
define('TOYYIBPAY_SECRET_KEY', env('TOYYIBPAY_SECRET_KEY', '')); // Your ToyyibPay userSecretKey
define('TOYYIBPAY_CATEGORY_CODE', env('TOYYIBPAY_CATEGORY_CODE', '')); // Your ToyyibPay categoryCode
define('TOYYIBPAY_API_URL', env('TOYYIBPAY_API_URL', 'https://dev.toyyibpay.com')); // Use https://toyyibpay.com for production

// Payment methods available
define('PAYMENT_METHODS', [
    'fpx' => [
        'name' => 'FPX (Perbankan Dalam Talian)',
        'icon' => '🏦',
        'enabled' => true,
        'banks' => [
            'maybank' => 'Maybank',
            'cimb' => 'CIMB Bank',
            'public' => 'Public Bank',
            'rhb' => 'RHB Bank',
            'hong_leong' => 'Hong Leong Bank',
            'ambank' => 'AmBank',
            'bsn' => 'Bank Simpanan Nasional',
            'muamalat' => 'Bank Muamalat'
        ]
    ],
    'ewallet' => [
        'name' => 'E-Wallet',
        'icon' => '💳',
        'enabled' => true,
        'providers' => [
            'tng' => 'Touch \'n Go eWallet',
            'boost' => 'Boost',
            'grabpay' => 'GrabPay',
            'shopeepay' => 'ShopeePay'
        ]
    ]
]);

// Transaction fee (0 = no fee, 0.01 = 1%)
define('PAYMENT_TRANSACTION_FEE', 0.00);

// Payment timeout (minutes) - auto-cancel unpaid orders after this duration
define('PAYMENT_TIMEOUT_MINUTES', 30);

// Currency
define('PAYMENT_CURRENCY', 'MYR');

// Allowed payment status transitions
define('PAYMENT_STATUS_TRANSITIONS', [
    'pending' => ['processing', 'failed', 'refunded'],
    'processing' => ['completed', 'failed'],
    'completed' => ['refunded'],
    'failed' => [],
    'refunded' => []
]);

/**
 * Whether the built-in FPX/e-Wallet payment simulator may be used.
 *
 * The simulator is a local/demo tool only. It is DISABLED outside explicit
 * development mode (TRUSTMART_DEV_MODE, which now defaults to false), so a
 * production deployment can never authorise a real payment through it. When
 * this returns false, online checkout must be served by a real gateway
 * (ToyyibPay).
 */
function payment_simulation_allowed() {
    return defined('TRUSTMART_DEV_MODE') && TRUSTMART_DEV_MODE === true;
}

/**
 * Generate a unique payment reference
 */
function generate_payment_reference($prefix = 'TM') {
    return $prefix . date('YmdHis') . substr(md5(uniqid(mt_rand(), true)), 0, 6);
}

/**
 * Read a scalar HTTP input safely WITHOUT the deprecated
 * FILTER_SANITIZE_STRING (removed in PHP 8.2). Returns the trimmed raw
 * value, capped in length, optionally restricted to a character whitelist
 * (returns null when the whitelist does not match). All DB access already
 * uses prepared statements, so this guards log/display hygiene and rejects
 * malformed identifiers early — it is not the SQL-safety layer.
 */
function payment_input($key, $maxLen = 64, $pattern = null) {
    $raw = $_REQUEST[$key] ?? '';
    if (!is_string($raw)) return null;
    $raw = trim($raw);
    if ($raw === '') return null;
    if ($pattern !== null && !preg_match($pattern, $raw)) return null;
    return substr($raw, 0, $maxLen);
}

/**
 * Calculate payment amount with fees
 */
function calculate_payment_amount($base_amount) {
    $fee = $base_amount * PAYMENT_TRANSACTION_FEE;
    return round($base_amount + $fee, 2);
}

/**
 * Check if payment status transition is allowed
 */
function is_valid_payment_transition($from_status, $to_status) {
    if (!isset(PAYMENT_STATUS_TRANSITIONS[$from_status])) {
        return false;
    }
    return in_array($to_status, PAYMENT_STATUS_TRANSITIONS[$from_status]);
}

/**
 * Get payment method display name
 */
function get_payment_method_name($method) {
    $methods = PAYMENT_METHODS;
    return $methods[$method]['name'] ?? ucfirst($method);
}

/**
 * Get payment method icon
 */
function get_payment_method_icon($method) {
    $methods = PAYMENT_METHODS;
    return $methods[$method]['icon'] ?? '💰';
}

/**
 * Generate HMAC signature for payment callback verification
 * 
 * @param string $payment_ref Payment reference
 * @param string $status Payment status (1=success, 0=failed)
 * @param float $amount Payment amount
 * @return string HMAC signature
 */
function generate_payment_signature($payment_ref, $status, $amount) {
    $data = $payment_ref . '|' . $status . '|' . number_format($amount, 2, '.', '');
    return hash_hmac('sha256', $data, PAYMENT_CALLBACK_SECRET);
}

/**
 * Verify payment callback signature
 * 
 * @param string $payment_ref Payment reference
 * @param string $status Payment status
 * @param float $amount Payment amount
 * @param string $signature Signature to verify
 * @return bool True if signature is valid
 */
function verify_payment_signature($payment_ref, $status, $amount, $signature) {
    $expected = generate_payment_signature($payment_ref, $status, $amount);
    return hash_equals($expected, $signature);
}

/**
 * SINGLE authoritative writer for a payment's terminal state.
 *
 * Both the real gateway webhook (callback.php, after HMAC verification)
 * and the local simulator (gateway_sim.php, after session+CSRF+ownership
 * checks) call this, so there is exactly ONE audited code path that flips
 * a payment to completed/failed and mirrors it onto the transaction. This
 * removes the old double-write where the simulator and the callback had
 * two separate, drift-prone implementations of the same transition.
 *
 * It is idempotent and race-safe: the payments row is locked FOR UPDATE
 * and only rows still in ('pending','processing') can become completed,
 * so a duplicate/late notification cannot re-pay or un-refund an order.
 * A completed payment is never downgraded to failed.
 *
 * @param PDO    $pdo
 * @param string $ref           payments.payment_reference
 * @param bool   $success       gateway reported success?
 * @param string $response_json raw gateway payload to persist for audit
 * @return array ['ok'=>bool,'state'=>'completed|failed|already_processed|not_found|needs_reconciliation','error'=>?string]
 */
function apply_payment_gateway_result(PDO $pdo, $ref, $success, $response_json) {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("
            SELECT p.payment_id, p.status AS payment_status,
                   t.transaction_id AS txn_id, t.status AS order_status
              FROM payments p
              JOIN transactions t ON t.transaction_id = p.transaction_id
             WHERE p.payment_reference = ?
               FOR UPDATE
        ");
        $stmt->execute([$ref]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$payment) {
            $pdo->rollBack();
            return ['ok' => false, 'state' => 'not_found', 'error' => 'Payment not found'];
        }

        if ($success) {
            // A payment can still be open while the ORDER has been cancelled
            // (or otherwise moved out of a payable state). Only a payable
            // order may be flipped to paid/fulfilled. A genuine late payment
            // for a non-payable order is captured truthfully and routed to
            // refund/reconciliation — it is NEVER silently fulfilled.
            // FIX H1: Auto-queue and process refund for late payments.
            $order_payable = in_array($payment['order_status'], ['Menunggu', 'Disahkan'], true);

            if (!$order_payable) {
                // Mark payment as completed (money captured)
                $pdo->prepare("
                    UPDATE payments
                       SET status = 'completed',
                           paid_at = CURRENT_TIMESTAMP,
                           gateway_response = ?
                     WHERE payment_id = ?
                       AND status IN ('pending', 'processing', 'failed')
                ")->execute([$response_json, $payment['payment_id']]);
                
                // Immediately queue refund for this late payment
                $refund_stmt = $pdo->prepare("
                    UPDATE payments
                    SET refund_status = 'requested',
                        refund_requested_at = CURRENT_TIMESTAMP,
                        refund_method = 'auto',
                        refund_attempts = 0
                    WHERE payment_id = ?
                      AND refund_status = 'none'
                ");
                $refund_stmt->execute([$payment['payment_id']]);
                
                $pdo->commit();
                
                // Process refund immediately (non-blocking)
                try {
                    $refund_result = process_payment_refund(
                        $pdo, 
                        $payment['payment_id'], 
                        'Late payment for ' . $payment['order_status'] . ' order - auto-refund',
                        'auto'
                    );
                    
                    $refund_msg = $refund_result['success'] 
                        ? 'auto-refund initiated: ' . $refund_result['refund_status']
                        : 'refund failed: ' . ($refund_result['error'] ?? 'unknown');
                    
                    error_log('[apply_payment_gateway_result] LATE PAYMENT auto-refund. ref=' . $ref . ' txn=' . $payment['txn_id'] . ' status=' . $payment['order_status'] . ' -> ' . $refund_msg);
                    
                    return [
                        'ok' => true, 
                        'state' => 'late_payment_refunded', 
                        'error' => null,
                        'refund_status' => $refund_result['refund_status'] ?? 'requested'
                    ];
                } catch (Exception $e) {
                    error_log('[apply_payment_gateway_result] LATE PAYMENT refund exception: ' . $e->getMessage());
                    // Refund failed but payment captured - will appear in admin refund queue
                    return ['ok' => false, 'state' => 'needs_reconciliation', 'error' => 'Payment captured, refund queued with errors'];
                }
            }

            // Only a still-pending/processing payment may transition to paid.
            $stmt = $pdo->prepare("
                UPDATE payments
                   SET status = 'completed',
                       paid_at = CURRENT_TIMESTAMP,
                       gateway_response = ?
                 WHERE payment_id = ?
                   AND status IN ('pending', 'processing')
            ");
            $stmt->execute([$response_json, $payment['payment_id']]);

            if ($stmt->rowCount() > 0) {
                $pdo->prepare("
                    UPDATE transactions
                       SET payment_status = 'paid',
                           paid_at = CURRENT_TIMESTAMP
                     WHERE transaction_id = ?
                ")->execute([$payment['txn_id']]);
                $pdo->commit();
                return ['ok' => true, 'state' => 'completed', 'error' => null];
            }

            // Already terminal (completed/refunded/failed) — do not touch the txn.
            $pdo->rollBack();
            return ['ok' => true, 'state' => 'already_processed', 'error' => null];
        }

        // Failure: never downgrade an already paid/refunded payment.
        $stmt = $pdo->prepare("
            UPDATE payments
               SET status = 'failed',
                   gateway_response = ?
             WHERE payment_id = ?
               AND status IN ('pending', 'processing')
        ");
        $stmt->execute([$response_json, $payment['payment_id']]);

        if ($stmt->rowCount() > 0) {
            $pdo->prepare("
                UPDATE transactions
                   SET payment_status = 'failed'
                 WHERE transaction_id = ?
            ")->execute([$payment['txn_id']]);
        }
        $pdo->commit();
        return ['ok' => true, 'state' => 'failed', 'error' => null];
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[apply_payment_gateway_result] ' . $e->getMessage());
        return ['ok' => false, 'state' => 'error', 'error' => $e->getMessage()];
    }
}

/**
 * Work out how much is actually being returned (audit #5).
 *
 * Before this, process_payment_refund() always refunded payments.amount, so a
 * partial refund decided by PTU in the settlement form
 * (disputes.settlement_refund_amount) was silently executed as a full one, and
 * nothing recorded what actually left the building. The decision is read from
 * the dispute here rather than required of every call site, so a caller that
 * forgets the argument still honours what the admin chose — the gateway refund
 * and the admin's manual sign-off therefore cannot disagree about the number.
 * Clamped to the amount actually paid: a refund can never exceed the payment it
 * refunds. Anything >= the payment (including a bad/zero value) is treated as
 * full, which is the only safe default.
 *
 * @param array    $payment       A payments row (needs 'amount' and 'transaction_id')
 * @param float|string|null $explicit  Caller-supplied amount, if any
 * @return array{amount:float, is_partial:bool}
 */
function resolve_refund_amount(PDO $pdo, array $payment, $explicit = null): array {
    $paid_amount = (float)$payment['amount'];

    if ($explicit !== null && is_numeric($explicit)) {
        $amount = (float)$explicit;
    } else {
        $stmt = $pdo->prepare(
            "SELECT settlement_refund_amount
               FROM disputes
              WHERE transaction_id = ?
                AND settlement_refund_amount IS NOT NULL
              ORDER BY dispute_id DESC
              LIMIT 1"
        );
        $stmt->execute([$payment['transaction_id']]);
        $decided = $stmt->fetchColumn();
        $amount = ($decided === false || $decided === null) ? $paid_amount : (float)$decided;
    }

    $amount = round($amount, 2);
    if ($amount <= 0 || $amount >= $paid_amount) {
        return ['amount' => round($paid_amount, 2), 'is_partial' => false];
    }
    return ['amount' => $amount, 'is_partial' => true];
}

/**
 * Process refund for a payment
 * 
 * This is the central refund function that handles both simulation and ToyyibPay gateways.
 * Updates database and calls appropriate gateway API.
 * 
 * @param PDO $pdo Database connection
 * @param int $payment_id Payment record ID
 * @param string $refund_reason Reason for refund
 * @param string $refund_method Method: 'auto' (system), 'manual' (user), 'admin'
 * @param float|string|null $refund_amount Amount to return; NULL means "whatever PTU
 *        decided in the settlement form, else the full payment" (see resolve_refund_amount)
 * @return array {
 *     @type bool $success Whether refund was processed
 *     @type string $message User-friendly message
 *     @type string $error Technical error if failed
 *     @type string $refund_status New refund status (requested/processing/completed/failed)
 *     @type float $refund_amount Amount actually returned
 *     @type bool $is_partial Whether less than the payment was returned
 * }
 */
function process_payment_refund($pdo, $payment_id, $refund_reason = 'Order cancelled', $refund_method = 'auto', $refund_amount = null) {
    // Only manage the transaction if the caller is not already in one.
    // PDO does NOT support nested transactions; starting a new one while a
    // caller's transaction is open throws a PDOException and, if rolled back,
    // would silently discard the caller's pending work (e.g. order cancellation).
    $ownTransaction = !$pdo->inTransaction();

    try {
        // Start transaction
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }
        
        // Lock and fetch payment record
        $stmt = $pdo->prepare("
            SELECT p.*, t.transaction_id, t.buyer_id, t.seller_id, t.total_amount
            FROM payments p
            JOIN transactions t ON t.transaction_id = p.transaction_id
            WHERE p.payment_id = ?
            FOR UPDATE
        ");
        $stmt->execute([$payment_id]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$payment) {
            if ($ownTransaction) {
                $pdo->rollBack();
            }
            return [
                'success' => false,
                'message' => 'Pembayaran tidak dijumpai',
                'error' => 'Payment record not found',
                'refund_status' => 'failed'
            ];
        }
        
        // Check if payment is eligible for refund
        if ($payment['status'] !== 'completed') {
            if ($ownTransaction) {
                $pdo->rollBack();
            }
            return [
                'success' => false,
                'message' => 'Hanya pembayaran yang telah selesai boleh dipulangkan',
                'error' => 'Payment status is not completed: ' . $payment['status'],
                'refund_status' => 'failed'
            ];
        }
        
        // Check if already refunded or in progress
        if (in_array($payment['refund_status'], ['completed', 'processing'], true)) {
            if ($ownTransaction) {
                $pdo->rollBack();
            }
            return [
                'success' => false,
                'message' => 'Pembayaran ini sudah dalam proses pulangan atau telah dipulangkan',
                'error' => 'Refund already ' . $payment['refund_status'],
                'refund_status' => $payment['refund_status']
            ];
        }
        
        // FIX H3: Check if return verification is required before processing refund
        if ($payment['refund_status'] === 'pending_return') {
            // Check if goods have been returned and verified
            $return_check = $pdo->prepare("
                SELECT rs.return_id, rs.status, rs.verified_at
                FROM return_shipments rs
                JOIN disputes d ON d.dispute_id = rs.dispute_id
                WHERE rs.transaction_id = ?
                  AND d.settlement_return_required = 1
                ORDER BY rs.return_id DESC
                LIMIT 1
            ");
            $return_check->execute([$payment['transaction_id']]);
            $return_status = $return_check->fetch(PDO::FETCH_ASSOC);
            
            if (!$return_status || $return_status['status'] !== 'verified') {
                if ($ownTransaction) {
                    $pdo->rollBack();
                }
                $current_status = $return_status ? $return_status['status'] : 'not_created';
                return [
                    'success' => false,
                    'message' => 'Pulangan ditahan: Menunggu pengesahan penerimaan barang pulangan',
                    'error' => 'Return not verified. Current return status: ' . $current_status,
                    'refund_status' => 'pending_return'
                ];
            }
            
            // Return verified, allow refund to proceed
            error_log("[process_payment_refund] Return verified (return_id={$return_status['return_id']}), proceeding with refund for payment_id=$payment_id");
        }
        
        // Increment refund attempts
        $attempts = (int)$payment['refund_attempts'] + 1;

        // Refund amount (audit #5) — see resolve_refund_amount() for the rule.
        ['amount' => $refund_amount, 'is_partial' => $is_partial] =
            resolve_refund_amount($pdo, $payment, $refund_amount);
        
        // Update refund status to 'requested'
        $stmt = $pdo->prepare("
            UPDATE payments
            SET refund_status = 'requested',
                refund_requested_at = CURRENT_TIMESTAMP,
                refund_method = ?,
                refund_attempts = ?
            WHERE payment_id = ?
        ");
        $stmt->execute([$refund_method, $attempts, $payment_id]);
        
        // Process refund based on payment method
        $refund_result = null;
        
        if ($payment['payment_method'] === 'toyyibpay' && TOYYIBPAY_ENABLED) {
            // Process ToyyibPay refund
            try {
                require_once __DIR__ . '/../payment/toyyibpay_helper.php';
                
                $toyyibpay = new ToyyibPayHelper();
                
                // Extract bill code from gateway_response
                $gateway_data = json_decode($payment['gateway_response'], true);
                $bill_code = $gateway_data['billCode'] ?? null;
                
                if (!$bill_code) {
                    throw new Exception('Bill code not found in payment record');
                }
                
                $refund_result = $toyyibpay->refundPayment([
                    'billCode' => $bill_code,
                    'payment_reference' => $payment['payment_reference'],
                    'refund_amount' => $refund_amount,
                    'refund_reason' => $refund_reason
                ]);
                
            } catch (Exception $e) {
                $refund_result = [
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
            
        } else {
            // Simulation gateway - auto-approve refund
            $refund_result = [
                'success' => true,
                'refund_reference' => 'SIM_REF_' . strtoupper(substr(md5(uniqid()), 0, 12)),
                'message' => 'Simulated refund approved automatically',
                'status' => 'success'
            ];
        }
        
        // Update payment based on refund result
        if ($refund_result && $refund_result['success']) {
            // Refund successful or initiated
            $new_status = ($payment['payment_method'] === 'toyyibpay') ? 'processing' : 'completed';
            
            $stmt = $pdo->prepare("
                UPDATE payments
                SET refund_status = ?,
                    refund_amount = ?,
                    refund_reference = ?,
                    refund_completed_at = CASE WHEN ? = 'completed' THEN CURRENT_TIMESTAMP ELSE NULL END,
                    refund_error = NULL,
                    status = CASE WHEN ? = 'completed' AND ? = 0 THEN 'refunded' ELSE status END
                WHERE payment_id = ?
            ");
            $stmt->execute([
                $new_status,
                $refund_amount,
                $refund_result['refund_reference'] ?? null,
                $new_status,
                $new_status,
                $is_partial ? 1 : 0,
                $payment_id
            ]);
            
            // Update transaction payment_status. A partial refund leaves the
            // payment genuinely not-refunded, so the badge must not claim it is.
            $stmt = $pdo->prepare("
                UPDATE transactions
                SET payment_status = CASE WHEN ? = 'completed' AND ? = 0 THEN 'refunded' ELSE payment_status END
                WHERE transaction_id = ?
            ");
            $stmt->execute([$new_status, $is_partial ? 1 : 0, $payment['transaction_id']]);
            
            // #5: close the settlement lifecycle. Only once money has actually
            // left (completed) does the decision become 'completed'; a gateway
            // 'processing' refund keeps it 'queued' so the admin still owns it
            // and can see it is unresolved.
            if ($new_status === 'completed') {
                $stmt = $pdo->prepare("
                    UPDATE disputes
                    SET settlement_status = 'completed'
                    WHERE transaction_id = ?
                      AND settlement_status = 'queued'
                ");
                $stmt->execute([$payment['transaction_id']]);
            }
            
            error_log("[process_payment_refund] payment={$payment_id} refunded={$refund_amount}/" . round((float)$payment['amount'], 2) . ($is_partial ? ' PARTIAL' : ' FULL') . " status={$new_status}");
            
            if ($ownTransaction) {
                $pdo->commit();
            }
            
            return [
                'success' => true,
                'message' => $new_status === 'completed' 
                    ? ($is_partial
                        ? 'Sebahagian pembayaran telah dipulangkan (RM' . number_format($refund_amount, 2) . ')'
                        : 'Pembayaran telah dipulangkan')
                    : 'Permohonan pulangan wang telah dihantar dan sedang diproses',
                'refund_status' => $new_status,
                'refund_amount' => $refund_amount,
                'is_partial' => $is_partial,
                'refund_reference' => $refund_result['refund_reference'] ?? null
            ];
            
        } else {
            // Refund failed
            $error_msg = $refund_result['error'] ?? 'Unknown error';
            
            $stmt = $pdo->prepare("
                UPDATE payments
                SET refund_status = 'failed',
                    refund_error = ?
                WHERE payment_id = ?
            ");
            $stmt->execute([$error_msg, $payment_id]);
            
            if ($ownTransaction) {
                $pdo->commit();
            }
            
            return [
                'success' => false,
                'message' => 'Pulangan wang gagal. Sila hubungi sokongan.',
                'error' => $error_msg,
                'refund_status' => 'failed'
            ];
        }
        
    } catch (Exception $e) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        
        error_log('[process_payment_refund] Error: ' . $e->getMessage());
        
        return [
            'success' => false,
            'message' => 'Ralat sistem semasa memproses pulangan wang',
            'error' => $e->getMessage(),
            'refund_status' => 'failed'
        ];
    }
}

/**
 * Check if payment is eligible for refund
 * 
 * @param array $payment Payment record
 * @return bool True if can be refunded
 */
function can_refund_payment($payment) {
    return $payment['status'] === 'completed' 
        && !in_array($payment['refund_status'], ['completed', 'processing'], true);
}
