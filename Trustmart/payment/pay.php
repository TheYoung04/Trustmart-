<?php
/**
 * Payment Initiation Page
 * 
 * Creates payment record and redirects to appropriate gateway
 */

session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/payment.php';
require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/payment_expiry.php';

require_role(['pembeli', 'penjual']);

// Verify transaction ID
if (!isset($_GET['txn']) || !is_numeric($_GET['txn'])) {
    $_SESSION['error_msg'] = 'ID transaksi tidak sah';
    header('Location: ../dashboard/buyer.php');
    exit;
}

$transaction_id = intval($_GET['txn']);
// Session stores the user under $_SESSION['user'] (see auth_check/login);
// there is no top-level $_SESSION['user_id'] and no users.username column.
$user_id = (int) ($_SESSION['user']['user_id'] ?? 0);

// Verify this is the buyer's transaction and it's unpaid.
// Age is measured by the database clock (TIMESTAMPDIFF against NOW()) rather
// than PHP's clock, so the check here and the one in the resume branch below
// cannot disagree with MySQL's timezone settings.
$stmt = $pdo->prepare("
    SELECT t.*, p.name AS product_name, p.price, u.full_name AS username, u.email,
           TIMESTAMPDIFF(MINUTE, t.created_at, NOW()) AS age_min
    FROM transactions t
    JOIN products p ON p.product_id = t.product_id
    JOIN users u ON u.user_id = t.buyer_id
    WHERE t.transaction_id = ? 
      AND t.buyer_id = ?
      AND t.payment_type = 'online'
      AND t.payment_status IN ('unpaid', 'pending', 'failed')
      AND t.status = 'Menunggu'
    LIMIT 1
");
$stmt->execute([$transaction_id, $user_id]);
$transaction = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$transaction) {
    $_SESSION['error_msg'] = 'Transaksi tidak ditemui atau sudah dibayar';
    header('Location: ../dashboard/buyer.php');
    exit;
}

// CRITICAL: charge the server-calculated checkout SNAPSHOT stored on the
// order. product.php writes total_amount = price × qty at checkout, so that
// figure is the immutable price the buyer agreed to. Do NOT recompute it from
// the listing's CURRENT price (#9): a seller editing the price after checkout
// must not invalidate an order that was legitimately priced. We only guard
// against a corrupt / non-positive snapshot; total_amount is authoritative.
$order_total = round((float) $transaction['total_amount'], 2);

if ($order_total <= 0 || !is_finite($order_total)) {
    $_SESSION['error_msg'] = 'Ralat: Jumlah pesanan tidak sah. Sila hubungi sokongan.';
    error_log("[SECURITY] Invalid order amount snapshot: Transaction {$transaction_id}, total={$transaction['total_amount']}");
    header('Location: ../dashboard/buyer.php');
    exit;
}

// #6 The payment window has to actually end. PAYMENT_TIMEOUT_MINUTES was only
// enforced by payment/expire_unpaid.php, i.e. by a cron job that is not running
// here, so an order abandoned days ago stayed payable forever: the buyer could
// hand over money for goods the seller has long since resold, and the abandoned
// order kept its stock reserved. Rather than merely refuse the attempt (which
// would leave the dead order on the buyer's badge forever), close it here the
// way the scheduled sweep would: cancel, release stock, fail any lingering bill.
// Shared with expire_unpaid.php through expire_stale_order() so the two cannot
// drift.
$timeout = payment_timeout_minutes();
if ((int) $transaction['age_min'] > $timeout) {
    $expired = expire_stale_order($pdo, $transaction_id, 'pay.php');
    error_log("[pay_timeout] txn={$transaction_id} age_min={$transaction['age_min']}"
        . " window={$timeout} closed=" . ($expired['ok'] ? '1' : '0'));
    $_SESSION['error_msg'] = $expired['ok']
        ? 'Tempoh pembayaran pesanan ini telah tamat (' . $timeout . ' minit). Pesanan dibatalkan dan stok telah dikembalikan — sila buat pesanan baharu.'
        : 'Tempoh pembayaran pesanan ini telah tamat (' . $timeout . ' minit). Sila buat pesanan baharu.';
    header('Location: ../dashboard/buyer.php');
    exit;
}

// #8 Abandoned-payment recovery — RESUME a still-open (pending) attempt.
// A buyer who started payment and then left the gateway has their order in
// 'pending'. Rather than force a brand-new bill, send them back to the gateway
// page that is still valid. Revalidate ownership + state (already enforced by
// the query above) and expiry here: only resume a pending payment whose
// payment_url is still within the PAYMENT_TIMEOUT window. An expired bill
// falls through to method selection so a fresh, unexpired bill is created.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && $transaction['payment_status'] === 'pending') {
    $resume = $pdo->prepare(
        "SELECT payment_url FROM payments
          WHERE transaction_id = ? AND status = 'pending'
            AND payment_url IS NOT NULL AND payment_url <> ''
            AND created_at >= (NOW() - INTERVAL ? MINUTE)
          ORDER BY payment_id DESC LIMIT 1"
    );
    $resume->execute([$transaction_id, max(1, (int) PAYMENT_TIMEOUT_MINUTES)]);
    $active = $resume->fetch(PDO::FETCH_ASSOC);

    // Expiry is evaluated in SQL (NOW()), matching expire_unpaid.php, so PHP
    // and MySQL timezone settings cannot drift the comparison. A stale/absent
    // bill falls through to method selection to create a fresh, unexpired one.
    if ($active) {
        header('Location: ' . $active['payment_url']);
        exit;
    }
}

// Handle payment method selection
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();
    
    $payment_method = payment_input('payment_method', 20, '/^[a-z_]+$/');
    
    if (!in_array($payment_method, ['fpx', 'ewallet', 'toyyibpay'], true)) {
        $_SESSION['error_msg'] = 'Kaedah pembayaran tidak sah';
        header('Location: ?txn=' . $transaction_id);
        exit;
    }
    
    // Server-side gateway selection — never trust the client to choose the
    // processing path:
    //   • FPX / e-Wallet are ONLY ever simulated. Outside development mode
    //     they must not silently fall through to the simulator — route them
    //     to the configured real gateway, or reject if none is available.
    //   • ToyyibPay requires the real gateway to actually be enabled.
    $use_simulation   = in_array($payment_method, ['fpx', 'ewallet'], true);
    $use_real_gateway = ($payment_method === 'toyyibpay');
    
    if ($use_real_gateway && !TOYYIBPAY_ENABLED) {
        $_SESSION['error_msg'] = 'ToyyibPay tidak tersedia';
        header('Location: ?txn=' . $transaction_id);
        exit;
    }
    
    if ($use_simulation && !payment_simulation_allowed()) {
        if (TOYYIBPAY_ENABLED) {
            // Force the real gateway server-side instead of the simulator.
            $payment_method   = 'toyyibpay';
            $use_simulation   = false;
            $use_real_gateway = true;
        } else {
            $_SESSION['error_msg'] = 'Kaedah pembayaran tidak tersedia. Sila pilih gerbang pembayaran yang sah.';
            header('Location: ?txn=' . $transaction_id);
            exit;
        }
    }
    
    $pdo->beginTransaction();
    
    try {
        // Generate payment reference
        $payment_ref = generate_payment_reference('TM');
        $amount = calculate_payment_amount($transaction['total_amount']);

        // W2: any 'pending' payment row left by an earlier abandoned attempt
        // at this order must be closed before we open a new one, otherwise
        // it lives on as a zombie (nothing reaps it: expire_unpaid.php only
        // cancels Menunggu orders, and admin panels assume pending rows
        // are only pre-callback, not historical). Only 'pending' rows are
        // superseded — 'processing' rows are left alone so a real ToyyibPay
        // bill the buyer is actively paying cannot be silently failed.
        $stale = $pdo->prepare(
            "SELECT payment_id FROM payments
              WHERE transaction_id = ? AND status = 'pending'"
        );
        $stale->execute([$transaction_id]);
        $stale_ids = $stale->fetchAll(PDO::FETCH_COLUMN);
        if ($stale_ids) {
            $fail = $pdo->prepare(
                "UPDATE payments
                    SET status = 'failed',
                        gateway_response = ?
                  WHERE payment_id IN (" . implode(',', array_fill(0, count($stale_ids), '?')) . ")"
            );
            $fail->execute(array_merge(
                [json_encode(['gateway' => 'internal', 'reason' => 'superseded_by_retry', 'timestamp' => date('c')])],
                $stale_ids
            ));
            error_log('[pay.php] superseded ' . count($stale_ids) . " stale pending payment(s) for txn $transaction_id: " . implode(',', $stale_ids));
        }

        // Create payment record
        $stmt = $pdo->prepare("
            INSERT INTO payments (
                transaction_id, payment_reference, payment_method,
                amount, currency, status
            ) VALUES (?, ?, ?, ?, ?, 'pending')
        ");
        $currency = PAYMENT_CURRENCY;
        $stmt->execute([
            $transaction_id, 
            $payment_ref, 
            $payment_method,
            $amount,
            $currency
        ]);
        $payment_id = $pdo->lastInsertId();
        
        // Update transaction status
        $stmt = $pdo->prepare("
            UPDATE transactions
            SET payment_status = 'pending',
                payment_reference = ?
            WHERE transaction_id = ?
        ");
        $stmt->execute([$payment_ref, $transaction_id]);
        
        // Generate payment URL based on the server-decided gateway path
        if ($use_real_gateway) {
            require_once __DIR__ . '/toyyibpay_helper.php';
            
            try {
                $toyyibpay = new ToyyibPayHelper();
                $bill = $toyyibpay->createBill([
                    'transaction_id' => $transaction_id,
                    'payment_reference' => $payment_ref,
                    'amount' => $amount,
                    'product_name' => $transaction['product_name'],
                    'buyer_name' => $transaction['username'],
                    'buyer_email' => $transaction['email']
                ]);
                
                $payment_url = $bill['billpaymentLink'];
                
                // Save ToyyibPay bill code
                $stmt = $pdo->prepare("
                    UPDATE payments
                    SET payment_url = ?,
                        gateway_response = ?
                    WHERE payment_id = ?
                ");
                $gateway_resp = json_encode(['billCode' => $bill['billCode']]);
                $stmt->execute([$payment_url, $gateway_resp, $payment_id]);
                
            } catch (Exception $e) {
                throw new Exception('ToyyibPay error: ' . $e->getMessage());
            }
            
        } else {
            // Simulated gateway — reachable only in development mode (already
            // enforced above, and re-checked inside gateway_sim.php itself).
            if (!payment_simulation_allowed()) {
                throw new Exception('Tiada gerbang pembayaran yang tersedia.');
            }
            $payment_url = PAYMENT_BASE_URL . '/payment/gateway_sim.php?ref=' . urlencode($payment_ref);
            
            $stmt = $pdo->prepare("
                UPDATE payments SET payment_url = ? WHERE payment_id = ?
            ");
            $stmt->execute([$payment_url, $payment_id]);
        }
        
        $pdo->commit();
        
        // Redirect to payment gateway
        header('Location: ' . $payment_url);
        exit;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error_msg'] = 'Ralat semasa memproses pembayaran: ' . $e->getMessage();
        header('Location: ?txn=' . $transaction_id);
        exit;
    }
}

$page_title = 'Bayar Pesanan';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container" style="max-width: 600px; margin: 40px auto;">
    <h2>💳 Pilih Kaedah Pembayaran</h2>
    
    <?php if (isset($_SESSION['error_msg'])): ?>
        <div class="alert alert-danger">
            <?= htmlspecialchars($_SESSION['error_msg']) ?>
        </div>
        <?php unset($_SESSION['error_msg']); ?>
    <?php endif; ?>
    
    <div class="card" style="margin: 20px 0; padding: 20px; background: #f8f9fa;">
        <h4 style="margin: 0 0 12px;">Butiran Pesanan</h4>
        <div style="display: flex; flex-direction: column; gap: 8px; font-size: 14px;">
            <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 16px;">
                <span style="color: #666;">Produk</span>
                <span style="font-weight: 600; text-align: right; word-break: break-word;"><?= htmlspecialchars($transaction['product_name']) ?></span>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 16px;">
                <span style="color: #666;">Kuantiti</span>
                <span style="font-weight: 600;"><?= $transaction['quantity'] ?> unit</span>
            </div>
            <div style="display: flex; justify-content: space-between; align-items: baseline; gap: 16px; margin-top: 4px; padding-top: 12px; border-top: 1px solid #e5e5e5;">
                <span style="color: #666;">Jumlah Bayaran</span>
                <span style="font-size: 24px; font-weight: bold; color: #27ae60;">
                    RM <?= number_format(calculate_payment_amount($transaction['total_amount']), 2) ?>
                </span>
            </div>
        </div>
    </div>
    
    <form method="POST" action="">
        <?= csrf_input() ?>
        
        <div style="margin: 20px 0;">
            <?php foreach (PAYMENT_METHODS as $method => $config): ?>
                <?php if ($config['enabled'] && payment_simulation_allowed()): ?>
                    <label class="payment-method-card" style="display: block; padding: 15px; margin: 10px 0; border: 2px solid #e0e0e0; border-radius: 8px; cursor: pointer; transition: all 0.2s;">
                        <input type="radio" name="payment_method" value="<?= $method ?>" required style="margin-right: 10px;">
                        <span style="font-size: 24px;"><?= $config['icon'] ?></span>
                        <strong><?= htmlspecialchars($config['name']) ?></strong>
                    </label>
                <?php endif; ?>
            <?php endforeach; ?>
            
            <?php if (TOYYIBPAY_ENABLED): ?>
                <label class="payment-method-card" style="display: block; padding: 15px; margin: 10px 0; border: 2px solid #e0e0e0; border-radius: 8px; cursor: pointer;">
                    <input type="radio" name="payment_method" value="toyyibpay" required style="margin-right: 10px;">
                    <span style="font-size: 24px;">💰</span>
                    <strong>ToyyibPay (FPX/Card)</strong>
                </label>
            <?php endif; ?>
            
            <?php if (!payment_simulation_allowed() && !TOYYIBPAY_ENABLED): ?>
                <p style="color:#c0392b;">Tiada kaedah pembayaran tersedia. Sila hubungi sokongan.</p>
            <?php endif; ?>
        </div>
        
        <button type="submit" class="btn btn-primary" style="width: 100%; padding: 15px; font-size: 18px; text-align: center;">
            Teruskan ke Pembayaran
        </button>
        
        <a href="../dashboard/buyer.php" class="btn btn-secondary" style="display: block; width: 100%; padding: 12px; margin-top: 10px; text-align: center;">
            Batal
        </a>
    </form>
</div>

<style>
    /* trustmart-ui.css has no box-sizing reset; keep full-width buttons
       inside the container instead of overflowing it by their padding. */
    .container .btn,
    .payment-method-card {
        box-sizing: border-box;
    }
    .payment-method-card:hover {
        border-color: #3498db;
        background: #f0f8ff;
    }
    .payment-method-card input[type="radio"]:checked + span,
    .payment-method-card input[type="radio"]:checked ~ strong {
        color: #3498db;
    }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
