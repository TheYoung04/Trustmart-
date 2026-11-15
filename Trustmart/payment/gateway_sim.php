<?php
/**
 * Simulated Payment Gateway
 * 
 * Provides realistic FPX and e-Wallet payment simulation for testing
 */

// auth_check.php starts the session (with hardened cookie params) and pulls
// in the CSRF helpers + require_role().
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/payment.php';
require_once __DIR__ . '/../includes/auth_check.php';

// ── Environment gate ──────────────────────────────────────────
// The simulator is a local/demo tool. Disable it entirely outside explicit
// development mode so it can never authorise a real payment in production.
if (!payment_simulation_allowed()) {
    http_response_code(404);
    die('Not found');
}

// ── Authentication gate ───────────────────────────────────────
// Only a logged-in buyer/seller may drive a simulated payment.
require_role(['pembeli', 'penjual']);

// Verify payment reference parameter
$payment_ref = payment_input('ref', 64, '/^[A-Za-z0-9_\-]+$/');
if ($payment_ref === null) {
    die('Invalid payment reference');
}

// Fetch payment details
$stmt = $pdo->prepare("
    SELECT p.*, t.buyer_id, t.total_amount, t.product_id,
           pr.name AS product_name, u.full_name AS username
    FROM payments p
    JOIN transactions t ON t.transaction_id = p.transaction_id
    JOIN products pr ON pr.product_id = t.product_id
    JOIN users u ON u.user_id = t.buyer_id
    WHERE p.payment_reference = ? AND p.status = 'pending'
    LIMIT 1
");
$stmt->execute([$payment_ref]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$payment) {
    die('Payment not found or already processed');
}

// ── Ownership gate ────────────────────────────────────────────
// The current session user must be the buyer of this transaction. A valid
// CSRF token alone does NOT prove ownership — an attacker with their own
// session could otherwise approve someone else's pending payment.
$user_id = (int) ($_SESSION['user']['user_id'] ?? 0);
if ($user_id === 0 || (int) $payment['buyer_id'] !== $user_id) {
    http_response_code(403);
    die('Akses ditolak.');
}

// ── Simulation-only payment method gate ───────────────────────
// Only the simulated methods (FPX / e-Wallet, i.e. those present in
// PAYMENT_METHODS) may be handled here. A ToyyibPay payment must NEVER be
// approved by the simulator. This runs BEFORE the POST handler.
$method_config = PAYMENT_METHODS[$payment['payment_method']] ?? null;
if (!$method_config) {
    http_response_code(400);
    die('Kaedah pembayaran tidak sah untuk simulasi.');
}

// Handle payment submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_validate();

    $bank_or_provider = payment_input('provider', 40, '/^[A-Za-z0-9 _\-]*$/') ?? 'unknown';

    // Simulate processing delay
    sleep(PAYMENT_SIM_DELAY_SECONDS);

    // The simulator plays the role of the bank: it decides the outcome and
    // then hands that outcome to the SINGLE authoritative writer,
    // apply_payment_gateway_result() — the exact same function the real
    // HMAC-verified webhook (callback.php) uses. There are no direct
    // payment/transaction writes here anymore, so the simulator and the
    // callback can never drift or double-apply a transition.
    $is_success = (mt_rand(1, 100) / 100) <= PAYMENT_SIM_SUCCESS_RATE;

    $gateway_response = json_encode([
        'gateway'   => 'simulation',
        'provider'  => $bank_or_provider,
        'timestamp' => date('Y-m-d H:i:s'),
        'result'    => $is_success ? 'approved' : 'declined',
        'sim_id'    => 'SIM' . strtoupper(substr(md5(uniqid('', true)), 0, 12)),
    ]);

    $result = apply_payment_gateway_result($pdo, $payment_ref, $is_success, $gateway_response);
    if (!$result['ok'] && $result['state'] !== 'already_processed') {
        error_log('[gateway_sim] apply failed ref=' . $payment_ref . ' state=' . $result['state']);
    }

    // Redirect based on the ACTUAL persisted state — never the intended
    // outcome — so return.php cannot show "success" for a payment the DB
    // rejected. If the write did not land, the order stays pending and is
    // safely reclaimed by expire_unpaid.php.
    $stmt = $pdo->prepare('SELECT status FROM payments WHERE payment_reference = ? LIMIT 1');
    $stmt->execute([$payment_ref]);
    $final_status = $stmt->fetchColumn() ?: 'pending';

    $redirect = $final_status === 'completed' ? 'success'
              : ($final_status === 'failed' ? 'failed' : 'pending');

    header('Location: ' . PAYMENT_BASE_URL . '/payment/return.php?ref=' . urlencode($payment_ref) . '&status=' . $redirect);
    exit;
}

$page_title = 'Pembayaran Dalam Talian';
?>
<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($page_title) ?> - TrustMart</title>
    <link rel="stylesheet" href="<?= PAYMENT_BASE_URL ?>/public/assets/css/trustmart-ui.css">
    <style>
        /* No global reset exists in trustmart-ui.css; without this, every
           width:100% child overflows the card by its own padding/border. */
        .payment-simulator,
        .payment-simulator * {
            box-sizing: border-box;
        }
        .payment-simulator {
            max-width: 500px;
            margin: 40px auto;
            padding: 20px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .payment-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #e0e0e0;
        }
        .payment-header h1 {
            margin: 0 0 10px;
            color: #2c3e50;
            font-size: 24px;
        }
        .payment-amount {
            font-size: 32px;
            font-weight: bold;
            color: #27ae60;
            margin: 10px 0;
        }
        .payment-details {
            margin: 20px 0;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 4px;
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 8px 16px;
            align-items: baseline;
        }
        .payment-details dt {
            font-weight: 600;
            color: #666;
        }
        .payment-details dd {
            margin: 0;
            color: #333;
            font-weight: 600;
            text-align: right;
            word-break: break-word;
        }
        .provider-list {
            margin: 20px 0;
        }
        .provider-option {
            display: block;
            padding: 15px;
            margin: 10px 0;
            border: 2px solid #e0e0e0;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
            background: white;
        }
        .provider-option:hover {
            border-color: #3498db;
            background: #f0f8ff;
        }
        .provider-option input[type="radio"] {
            margin-right: 10px;
        }
        .btn-pay {
            width: 100%;
            padding: 15px;
            font-size: 18px;
            font-weight: 600;
            background: #27ae60;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn-pay:hover {
            background: #229954;
        }
        .btn-cancel {
            display: block;
            width: 100%;
            padding: 12px;
            margin-top: 10px;
            text-align: center;
            color: #666;
            text-decoration: none;
            border: 1px solid #ddd;
            border-radius: 6px;
            transition: all 0.2s;
        }
        .btn-cancel:hover {
            background: #f8f9fa;
            color: #333;
        }
        .simulator-notice {
            margin-top: 30px;
            padding: 15px;
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 4px;
            font-size: 14px;
            color: #856404;
        }
    </style>
</head>
<body>
    <div class="payment-simulator">
        <div class="payment-header">
            <h1><?= htmlspecialchars($method_config['icon']) ?> <?= htmlspecialchars($method_config['name']) ?></h1>
            <div class="payment-amount">RM <?= number_format($payment['amount'], 2) ?></div>
        </div>
        
        <dl class="payment-details">
            <dt>Rujukan Pembayaran</dt>
            <dd><?= htmlspecialchars($payment['payment_reference']) ?></dd>
            
            <dt>Produk</dt>
            <dd><?= htmlspecialchars($payment['product_name']) ?></dd>
            
            <dt>Pembeli</dt>
            <dd><?= htmlspecialchars($payment['username']) ?></dd>
        </dl>
        
        <form method="POST" action="">
            <?php echo csrf_input(); ?>
            
            <div class="provider-list">
                <p><strong>Pilih <?= $payment['payment_method'] === 'fpx' ? 'Bank' : 'E-Wallet' ?>:</strong></p>
                
                <?php
                $providers = $payment['payment_method'] === 'fpx' 
                    ? $method_config['banks'] 
                    : $method_config['providers'];
                
                $first = true;
                foreach ($providers as $code => $name):
                ?>
                    <label class="provider-option">
                        <input type="radio" name="provider" value="<?= htmlspecialchars($code) ?>" 
                               <?= $first ? 'checked' : '' ?> required>
                        <?= htmlspecialchars($name) ?>
                    </label>
                <?php 
                    $first = false;
                endforeach; 
                ?>
            </div>
            
            <button type="submit" class="btn-pay">Bayar Sekarang</button>
        </form>
        
        <a href="<?= PAYMENT_BASE_URL ?>/dashboard/buyer.php" class="btn-cancel">Batal Pembayaran</a>
        
        <div class="simulator-notice">
            <strong>⚠️ Mod Simulasi</strong><br>
            Ini ialah gerbang pembayaran simulasi untuk tujuan ujian. 
            Pembayaran akan berjaya dalam <?= (PAYMENT_SIM_SUCCESS_RATE * 100) ?>% kes.
        </div>
    </div>
</body>
</html>
