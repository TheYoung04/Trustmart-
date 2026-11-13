<?php
/**
 * Transaction Receipt - Printable
 * 
 * Professional receipt for completed transactions
 */

session_start();
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/includes/trust_score.php';
refresh_session_user($pdo);

require_role(['pembeli', 'penjual', 'pentadbir']);

$user = current_user();
$transaction_id = (int)($_GET['txn'] ?? 0);

/**
 * Where "Kembali ke Papan Pemuka" should land.
 * Role alone is not enough: a penjual also shops in Mod Pembeli, so sending
 * them to seller.php drops them out of the side of the app they came from
 * (same convention as dashboard/profile.php). Whoever the visitor is *in this
 * transaction* wins; then ?mode=; then the Referer.
 */
function receipt_dashboard_url(array $user, ?array $txn = null): string {
    if ($user['role'] === 'pentadbir') return 'dashboard/admin.php';

    if ($txn) {
        if ((int) ($txn['buyer_id'] ?? 0) === (int) $user['user_id'])  return 'dashboard/buyer.php';
        if ((int) ($txn['seller_id'] ?? 0) === (int) $user['user_id']) return 'dashboard/seller.php';
    }

    if ($user['role'] === 'penjual') {
        $mode = strtolower((string) ($_GET['mode'] ?? ''));
        if ($mode === '') {
            $ref = strtolower((string) ($_SERVER['HTTP_REFERER'] ?? ''));
            if (str_contains($ref, '/buyer.php'))       $mode = 'pembeli';
            elseif (str_contains($ref, '/seller.php')) $mode = 'penjual';
        }
        return $mode === 'pembeli' ? 'dashboard/buyer.php' : 'dashboard/seller.php';
    }

    return 'dashboard/buyer.php';
}

/**
 * Friendly stand-in for the old plain-text die(): same visual language as
 * the receipt itself, plus the real reason the receipt cannot be shown.
 */
function receipt_unavailable_page(string $title, string $msg, string $hint, array $user, ?array $txn = null): void {
    $dash = receipt_dashboard_url($user, $txn);
    $t = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $m = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
    // $hint is trusted internal copy (may contain <b>); callers pass literals.
    echo <<<HTML
<!DOCTYPE html>
<html lang="ms">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Resit Belum Tersedia - TrustMart</title>
<style>
  *{margin:0;padding:0;box-sizing:border-box}
  body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;
       background:#f5f5f5;padding:20px;color:#333}
  .wrap{max-width:520px;margin:60px auto;background:#fff;
        box-shadow:0 2px 8px rgba(0,0,0,.1);border-radius:8px;overflow:hidden}
  .head{background:linear-gradient(135deg,#2c3e50 0%,#34495e 100%);color:#fff;
        padding:26px 30px;text-align:center}
  .head .logo{font-size:22px;font-weight:700}
  .head .logo span{color:#f39c12}
  .body{padding:36px 30px;text-align:center}
  .icon{font-size:52px;margin-bottom:14px}
  h1{font-size:19px;color:#2c3e50;margin-bottom:10px}
  p{font-size:14px;color:#555;line-height:1.6;margin-bottom:8px}
  .hint{background:#f8f9fa;border-left:4px solid #f39c12;text-align:left;
        padding:12px 14px;font-size:13px;color:#666;border-radius:4px;margin:18px 0 26px}
  .btn{display:inline-block;padding:13px 28px;background:#2c3e50;color:#fff;
       text-decoration:none;border-radius:6px;font-size:14px;font-weight:600}
  .btn:hover{background:#34495e}
</style>
</head>
<body>
  <div class="wrap">
    <div class="head"><div class="logo">Trust<span>Mart</span></div></div>
    <div class="body">
      <div class="icon">🧾</div>
      <h1>{$t}</h1>
      <p>{$m}</p>
      <div class="hint">{$hint}</div>
      <a class="btn" href="{$dash}">Kembali ke Papan Pemuka</a>
    </div>
  </div>
</body>
</html>
HTML;
    exit;
}

if (!$transaction_id) {
    receipt_unavailable_page(
        'Pautan Tidak Sah',
        'Tiada nombor transaksi dihantar pada pautan ini.',
        'Buka resit anda melalui <b>Pesanan Saya</b> pada papan pemuka, atau semak semula pautan yang diberikan.',
        $user);
}

// Fetch transaction details
$stmt = $pdo->prepare("
    SELECT t.*, 
           p.name AS product_name, p.category, p.description,
           buyer.full_name AS buyer_name, buyer.email AS buyer_email,
           COALESCE(NULLIF(buyer.matric_no, ''), buyer.staff_no) AS buyer_ident,
           seller.full_name AS seller_name, seller.email AS seller_email,
           COALESCE(NULLIF(seller.matric_no, ''), seller.staff_no) AS seller_ident,
           pay.payment_method AS online_method, pay.paid_at AS online_paid_at,
           pay.payment_reference AS online_reference
    FROM transactions t
    JOIN products p ON p.product_id = t.product_id
    JOIN users buyer ON buyer.user_id = t.buyer_id
    JOIN users seller ON seller.user_id = t.seller_id
    LEFT JOIN payments pay ON pay.transaction_id = t.transaction_id AND pay.status = 'completed'
    WHERE t.transaction_id = ?
      AND (t.buyer_id = ? OR t.seller_id = ? OR ? = 'pentadbir')
      AND t.status IN ('Menunggu', 'Disahkan', 'Selesai')
    LIMIT 1
");
$stmt->execute([
    $transaction_id, 
    $user['user_id'], 
    $user['user_id'],
    $user['role']
]);
$transaction = $stmt->fetch();

if (!$transaction) {
    // Say *why*: not found vs still in progress vs not your transaction.
    $diag = $pdo->prepare("SELECT t.status, t.buyer_id, t.seller_id, t.payment_type
                             FROM transactions t WHERE t.transaction_id = ? LIMIT 1");
    $diag->execute([$transaction_id]);
    $d = $diag->fetch(PDO::FETCH_ASSOC);

    if (!$d) {
        receipt_unavailable_page(
            'Transaksi Tidak Dijumpai',
            "Tiada transaksi dengan nombor #{$transaction_id} dalam sistem.",
            'Semak semula nombor resit, atau buka Pesanan Saya pada papan pemuka untuk melihat senarai transaksi anda.',
            $user);
    }
    if ((int)$d['buyer_id'] !== (int)$user['user_id']
        && (int)$d['seller_id'] !== (int)$user['user_id']
        && $user['role'] !== 'pentadbir') {
        receipt_unavailable_page(
            'Akses Ditolak',
            'Transaksi ini bukan milik anda.',
            'Hanya pembeli, penjual berkaitan atau pentadbir PTU boleh melihat resit ini.',
            $user, $d);
    }
    // A live order (Menunggu/Disahkan/Selesai) always passes the query above, so
    // the only state reaching here is a cancelled/unknown one. Say so plainly.
    $next = ($d['status'] === 'Dibatalkan')
        ? 'Pesanan ini telah <b>dibatalkan</b>, jadi tiada resit dikeluarkan.'
        : 'Resit hanya tersedia untuk pesanan yang masih berjalan atau selesai.';
    receipt_unavailable_page(
        'Resit Belum Tersedia',
        // raw status: receipt_unavailable_page() escapes the message itself
        'Pesanan ini berstatus «' . $d['status'] . '».',
        $next,
        $user, $d);
}

$is_online_payment = ($transaction['payment_type'] === 'online');
$receipt_date = $transaction['completed_at'] ?? $transaction['confirmed_at'] ?? $transaction['created_at'];

// The receipt is now issued the moment a buyer places an order, so a
// 'Menunggu' order is rendered as a PROVISIONAL receipt flagged Pending
// instead of being withheld until the seller confirms it.
$txn_status   = $transaction['status'];
$is_pending   = ($txn_status === 'Menunggu');
$status_style = $txn_status === 'Selesai' ? 'completed' : ($is_pending ? 'pending' : 'confirmed');

// Truthful payment state, mirroring the dashboard vocabulary
// (paid | paid_cash | pending | unpaid | unpaid_cash | failed | refunded).
$pay_state       = (string) ($transaction['payment_status'] ?? '');
$online_paid_row = !empty($transaction['online_paid_at']); // a completed payment was captured
if ($is_online_payment) {
    if ($pay_state === 'paid' || $online_paid_row) {
        $pay_style = 'paid';      $pay_text = '✓ Dibayar';
    } elseif ($pay_state === 'pending') {
        $pay_style = 'pending';   $pay_text = '⏳ Pembayaran dalam proses';
    } elseif ($pay_state === 'failed') {
        $pay_style = 'unpaid';    $pay_text = '✗ Pembayaran gagal';
    } elseif ($pay_state === 'refunded') {
        $pay_style = 'confirmed'; $pay_text = 'Dipulangkan';
    } else { // unpaid
        $pay_style = 'unpaid';    $pay_text = 'Belum dibayar';
    }
} else { // cash / bayaran terus — settled only once the seller records receipt
    if ($pay_state === 'paid_cash') {
        $pay_style = 'paid';     $pay_text = '✓ Tunai diterima';
    } else {
        $pay_style = 'pending';  $pay_text = '💵 Tunai – akan dibayar semasa serah terima';
    }
}
?>
<!DOCTYPE html>
<html lang="ms">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Resit #<?= $transaction_id ?> - TrustMart</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            padding: 20px;
            color: #333;
        }
        
        .receipt-container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .receipt-header {
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            color: white;
            padding: 40px 30px;
            text-align: center;
        }
        
        .receipt-logo {
            font-size: 32px;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .receipt-logo span {
            color: #f39c12;
        }
        
        .receipt-subtitle {
            font-size: 14px;
            opacity: 0.9;
        }
        
        .receipt-body {
            padding: 30px;
        }
        
        .receipt-section {
            margin-bottom: 30px;
        }
        
        .receipt-section-title {
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            color: #7f8c8d;
            margin-bottom: 12px;
            letter-spacing: 0.5px;
        }
        
        .receipt-info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        .info-block {
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .info-label {
            font-size: 11px;
            font-weight: 600;
            color: #7f8c8d;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        
        .info-value {
            font-size: 14px;
            color: #2c3e50;
            font-weight: 500;
        }
        
        .receipt-items {
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            overflow: hidden;
        }
        
        .receipt-items-header {
            background: #f8f9fa;
            padding: 12px 20px;
            font-size: 12px;
            font-weight: 600;
            color: #7f8c8d;
            text-transform: uppercase;
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 10px;
        }
        
        .receipt-items-row {
            padding: 15px 20px;
            border-top: 1px solid #e0e0e0;
            display: grid;
            grid-template-columns: 2fr 1fr 1fr 1fr;
            gap: 10px;
            align-items: center;
        }
        
        .item-name {
            font-weight: 500;
        }
        
        .item-desc {
            font-size: 12px;
            color: #7f8c8d;
            margin-top: 4px;
        }
        
        .receipt-total-section {
            margin-top: 20px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            font-size: 14px;
        }
        
        .total-row.grand-total {
            border-top: 2px solid #2c3e50;
            margin-top: 10px;
            padding-top: 15px;
            font-size: 18px;
            font-weight: 700;
            color: #27ae60;
        }
        
        .receipt-footer {
            padding: 20px 30px;
            background: #f8f9fa;
            border-top: 1px solid #e0e0e0;
            text-align: center;
            font-size: 12px;
            color: #7f8c8d;
        }
        
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-paid {
            background: #d4edda;
            color: #155724;
        }
        
        .status-confirmed {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .status-completed {
            background: #d4edda;
            color: #155724;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .status-unpaid {
            background: #f8d7da;
            color: #721c24;
        }
        
        .pending-banner {
            background: #fff8e1;
            border: 1px solid #ffe082;
            border-left: 4px solid #f39c12;
            padding: 14px 18px;
            border-radius: 8px;
            font-size: 13px;
            color: #7a5c00;
            line-height: 1.6;
            margin-bottom: 26px;
        }
        
        .btn-print {
            display: inline-block;
            margin: 20px auto;
            padding: 12px 30px;
            background: #3498db;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-align: center;
        }
        
        .btn-print:hover {
            background: #2980b9;
        }
        
        @media print {
            body {
                background: white;
                padding: 0;
            }
            
            .receipt-container {
                box-shadow: none;
                max-width: 100%;
            }
            
            .btn-print, .no-print {
                display: none !important;
            }
        }
        
        @media (max-width: 600px) {
            .receipt-info-grid {
                grid-template-columns: 1fr;
            }
            
            .receipt-items-header,
            .receipt-items-row {
                grid-template-columns: 1fr;
                gap: 5px;
            }
            
            .receipt-items-header span:not(:first-child),
            .receipt-items-row span:not(:first-child) {
                text-align: left;
            }
        }
    </style>
</head>
<body>
    <div class="receipt-container">
        <!-- Header -->
        <div class="receipt-header">
            <div class="receipt-logo">Trust<span>Mart</span></div>
            <div class="receipt-subtitle">Marketplace Intra-Kampus UTHM</div>
        </div>
        
        <!-- Body -->
        <div class="receipt-body">
            <?php if ($is_pending): ?>
            <div class="pending-banner">
                ⏳ <strong>Resit Provisional — pesanan masih Menunggu (Pending).</strong>
                <?php if ($is_online_payment && $pay_style === 'paid'): ?>
                    Pembayaran telah diterima. Resit menjadi muktamad sebaik penjual menekan <b>Sahkan</b>.
                <?php elseif ($is_online_payment): ?>
                    Selesaikan pembayaran untuk mengesahkan pesanan ini.
                <?php else: ?>
                    Resit menjadi muktamad sebaik penjual mengesahkan penerimaan pesanan &amp; bayaran tunai.
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <!-- Receipt Info -->
            <div class="receipt-section">
                <div class="receipt-section-title">Maklumat Resit</div>
                <div class="receipt-info-grid">
                    <div class="info-block">
                        <div class="info-label">No. Resit</div>
                        <div class="info-value">#TM-<?= str_pad($transaction_id, 6, '0', STR_PAD_LEFT) ?></div>
                    </div>
                    <div class="info-block">
                        <div class="info-label">Tarikh & Masa</div>
                        <div class="info-value"><?= date('d/m/Y H:i', strtotime($receipt_date)) ?></div>
                    </div>
                    <div class="info-block">
                        <div class="info-label">Status</div>
                        <div class="info-value">
                            <span class="status-badge status-<?= $status_style ?>">
                                <?= htmlspecialchars($txn_status) ?><?= $is_pending ? ' (Pending)' : '' ?>
                            </span>
                        </div>
                    </div>
                    <div class="info-block">
                        <div class="info-label">Status Pembayaran</div>
                        <div class="info-value">
                            <span class="status-badge status-<?= $pay_style ?>"><?= $pay_text ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Buyer & Seller Info -->
            <div class="receipt-section">
                <div class="receipt-section-title">Butiran Pihak</div>
                <div class="receipt-info-grid">
                    <div class="info-block">
                        <div class="info-label">Pembeli</div>
                        <div class="info-value"><?= htmlspecialchars($transaction['buyer_name']) ?></div>
                        <div class="item-desc"><?= htmlspecialchars($transaction['buyer_ident']) ?></div>
                        <?php if ($transaction['buyer_email']): ?>
                        <div class="item-desc"><?= htmlspecialchars($transaction['buyer_email']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="info-block">
                        <div class="info-label">Penjual</div>
                        <div class="info-value"><?= htmlspecialchars($transaction['seller_name']) ?></div>
                        <div class="item-desc"><?= htmlspecialchars($transaction['seller_ident']) ?></div>
                        <?php if ($transaction['seller_email']): ?>
                        <div class="item-desc"><?= htmlspecialchars($transaction['seller_email']) ?></div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Items -->
            <div class="receipt-section">
                <div class="receipt-section-title">Butiran Produk</div>
                <div class="receipt-items">
                    <div class="receipt-items-header">
                        <span>Produk</span>
                        <span style="text-align: center;">Kuantiti</span>
                        <span style="text-align: right;">Harga Unit</span>
                        <span style="text-align: right;">Jumlah</span>
                    </div>
                    <div class="receipt-items-row">
                        <div>
                            <div class="item-name"><?= htmlspecialchars($transaction['product_name']) ?></div>
                            <div class="item-desc">Kategori: <?= htmlspecialchars($transaction['category']) ?></div>
                        </div>
                        <span style="text-align: center;"><?= $transaction['quantity'] ?></span>
                        <span style="text-align: right;">RM <?= number_format($transaction['total_amount'] / $transaction['quantity'], 2) ?></span>
                        <span style="text-align: right; font-weight: 600;">RM <?= number_format($transaction['total_amount'], 2) ?></span>
                    </div>
                </div>
                
                <!-- Total -->
                <div class="receipt-total-section">
                    <div class="total-row">
                        <span>Subtotal:</span>
                        <span>RM <?= number_format($transaction['total_amount'], 2) ?></span>
                    </div>
                    <div class="total-row">
                        <span>Yuran Pemprosesan:</span>
                        <span>RM 0.00</span>
                    </div>
                    <div class="total-row grand-total">
                        <span>JUMLAH KESELURUHAN:</span>
                        <span>RM <?= number_format($transaction['total_amount'], 2) ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Payment Info -->
            <div class="receipt-section">
                <div class="receipt-section-title">Maklumat Pembayaran</div>
                <div class="receipt-info-grid">
                    <div class="info-block">
                        <div class="info-label">Jenis Pembayaran</div>
                        <div class="info-value">
                            <?= $is_online_payment ? '💳 Dalam Talian' : '💵 Bayaran Terus' ?>
                        </div>
                    </div>
                    <div class="info-block">
                        <div class="info-label">Kaedah</div>
                        <div class="info-value"><?= htmlspecialchars($transaction['payment_method']) ?></div>
                    </div>
                    <?php if ($is_online_payment && $transaction['online_paid_at']): ?>
                    <div class="info-block">
                        <div class="info-label">Tarikh Bayaran</div>
                        <div class="info-value"><?= date('d/m/Y H:i', strtotime($transaction['online_paid_at'])) ?></div>
                    </div>
                    <div class="info-block">
                        <div class="info-label">Rujukan Pembayaran</div>
                        <div class="info-value" style="font-family: monospace; font-size: 12px;">
                            <?= htmlspecialchars($transaction['online_reference']) ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Print Button -->
            <div style="text-align: center;" class="no-print">
                <button class="btn-print" onclick="window.print()">🖨️ Cetak Resit</button>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="receipt-footer">
            <p><strong>TrustMart</strong> - Platform Jual Beli Pelajar UTHM</p>
            <p style="margin-top: 8px;"><?php if ($is_pending): ?>Resit provisional — sah sebagai pengesahan pesanan, belum muktamad sehingga pengesahan penjual.<?php else: ?>Resit ini dijana secara automatik dan sah tanpa tandatangan.<?php endif; ?></p>
            <p style="margin-top: 4px;">Untuk sebarang pertanyaan, hubungi penjual melalui sistem chat TrustMart.</p>
            <p style="margin-top: 12px; font-size: 11px; opacity: 0.7;">
                Dicetak pada: <?= date('d/m/Y H:i:s') ?>
            </p>
        </div>
    </div>
</body>
</html>
