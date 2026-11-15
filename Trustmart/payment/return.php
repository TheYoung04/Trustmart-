<?php
/**
 * Payment Return Page
 * 
 * User returns here after payment (success or failure)
 */

session_start();
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/payment.php';
require_once __DIR__ . '/../includes/auth_check.php';

require_role(['pembeli', 'penjual']);

$payment_ref = payment_input('ref', 64, '/^[A-Za-z0-9_\-]+$/');
$status      = payment_input('status', 16, '/^[a-z]+$/');

if (empty($payment_ref)) {
    $_SESSION['error_msg'] = 'Rujukan pembayaran tidak sah';
    header('Location: ../dashboard/buyer.php');
    exit;
}

// Fetch payment details
$stmt = $pdo->prepare("
    SELECT p.*, t.transaction_id, t.buyer_id, t.total_amount,
           pr.name AS product_name
    FROM payments p
    JOIN transactions t ON t.transaction_id = p.transaction_id
    JOIN products pr ON pr.product_id = t.product_id
    WHERE p.payment_reference = ?
    LIMIT 1
");
$stmt->execute([$payment_ref]);
$payment = $stmt->fetch(PDO::FETCH_ASSOC);

// Session user lives under $_SESSION['user']['user_id']; PDO returns buyer_id
// as a string, so compare on ints to avoid a false "not found" on every load.
$current_uid = (int) ($_SESSION['user']['user_id'] ?? 0);
if (!$payment || $current_uid === 0 || (int) $payment['buyer_id'] !== $current_uid) {
    $_SESSION['error_msg'] = 'Pembayaran tidak ditemui';
    header('Location: ../dashboard/buyer.php');
    exit;
}

$page_title = 'Status Pembayaran';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="container" style="max-width: 600px; margin: 40px auto; text-align: center;">
    
    <?php if ($payment['status'] === 'completed'): ?>
        <!-- Payment Success -->
        <div style="padding: 40px; background: #d4edda; border: 2px solid #28a745; border-radius: 12px;">
            <div style="font-size: 64px; margin-bottom: 20px;">✅</div>
            <h2 style="color: #155724; margin-bottom: 10px;">Pembayaran Berjaya!</h2>
            <p style="color: #155724; font-size: 18px;">
                Pembayaran anda sebanyak <strong>RM <?= number_format($payment['amount'], 2) ?></strong> 
                telah diterima.
            </p>
            
            <div style="margin: 30px 0; padding: 20px; background: white; border-radius: 8px; text-align: left;">
                <h4 style="margin-top: 0;">Butiran Pembayaran</h4>
                <dl>
                    <dt>Rujukan:</dt>
                    <dd><code><?= htmlspecialchars($payment['payment_reference']) ?></code></dd>
                    
                    <dt>Produk:</dt>
                    <dd><?= htmlspecialchars($payment['product_name']) ?></dd>
                    
                    <dt>Jumlah:</dt>
                    <dd>RM <?= number_format($payment['amount'], 2) ?></dd>
                    
                    <dt>Tarikh & Masa:</dt>
                    <dd><?= date('d/m/Y H:i', strtotime($payment['paid_at'])) ?></dd>
                    
                    <dt>Kaedah:</dt>
                    <dd><?= htmlspecialchars(get_payment_method_name($payment['payment_method'])) ?></dd>
                </dl>
            </div>
            
            <div style="margin-top: 30px;">
                <a href="../dashboard/buyer.php" class="btn btn-primary" style="margin: 5px;">
                    Lihat Pesanan Saya
                </a>
                <a href="../receipt.php?txn=<?= $payment['transaction_id'] ?>" class="btn btn-secondary" style="margin: 5px;" target="_blank">
                    📄 Cetak Resit
                </a>
            </div>
        </div>
        
    <?php elseif ($payment['status'] === 'failed' || $status === 'failed'): ?>
        <!-- Payment Failed -->
        <div style="padding: 40px; background: #f8d7da; border: 2px solid #dc3545; border-radius: 12px;">
            <div style="font-size: 64px; margin-bottom: 20px;">❌</div>
            <h2 style="color: #721c24; margin-bottom: 10px;">Pembayaran Gagal</h2>
            <p style="color: #721c24; font-size: 18px;">
                Maaf, pembayaran anda tidak dapat diproses.
            </p>
            
            <div style="margin: 30px 0; padding: 20px; background: white; border-radius: 8px;">
                <p style="margin: 0;">
                    Ini mungkin disebabkan oleh baki tidak mencukupi, masalah teknikal,
                    atau pembayaran yang dibatalkan.
                </p>
            </div>
            
            <div style="margin-top: 30px;">
                <a href="pay.php?txn=<?= $payment['transaction_id'] ?>" class="btn btn-primary" style="margin: 5px;">
                    Cuba Lagi
                </a>
                <a href="../dashboard/buyer.php" class="btn btn-secondary" style="margin: 5px;">
                    Kembali ke Papan Pemuka
                </a>
            </div>
        </div>
        
    <?php else: ?>
        <!-- Payment Pending -->
        <div style="padding: 40px; background: #fff3cd; border: 2px solid #ffc107; border-radius: 12px;">
            <div style="font-size: 64px; margin-bottom: 20px;">⏳</div>
            <h2 style="color: #856404; margin-bottom: 10px;">Pembayaran Dalam Proses</h2>
            <p style="color: #856404; font-size: 18px;">
                Pembayaran anda sedang diproses. Sila tunggu sebentar.
            </p>
            
            <div style="margin: 30px 0; padding: 20px; background: white; border-radius: 8px;">
                <p style="margin: 0;">
                    Status pembayaran akan dikemas kini secara automatik.
                    Sila semak dashboard anda dalam beberapa minit.
                </p>
            </div>
            
            <div style="margin-top: 30px;">
                <a href="../dashboard/buyer.php" class="btn btn-primary">
                    Kembali ke Papan Pemuka
                </a>
            </div>
        </div>
    <?php endif; ?>
    
</div>

<style>
    dl {
        margin: 10px 0;
    }
    dt {
        font-weight: 600;
        color: #666;
        margin-top: 10px;
    }
    dd {
        margin: 5px 0 0 0;
        color: #333;
    }
    code {
        padding: 4px 8px;
        background: #f1f3f5;
        border-radius: 4px;
        font-family: monospace;
        font-size: 14px;
    }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
