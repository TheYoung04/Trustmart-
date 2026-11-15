<?php
// ============================================================
// product.php
// Single product view — accessible to all logged-in users.
// Handles ?action=make_order POST for buyers.
// ============================================================
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/trust_score.php';
require_once __DIR__ . '/includes/ai_trust.php';
refresh_session_user($pdo);

require_role(['pembeli', 'penjual', 'pentadbir']);

$user       = current_user();
$product_id = (int) ($_GET['id'] ?? 0);

// Build the project base URL (works in any subfolder, e.g. /Trustmart)
$_base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$dashboard_url = match ($user['role']) {
    'pembeli'   => 'dashboard/buyer.php',
    'penjual'   => 'dashboard/seller.php',
    'pentadbir' => 'dashboard/admin.php',
    default     => 'public/index.php',
};

if (!$product_id) {
    header('Location: ' . $dashboard_url);
    exit;
}

// ── Handle order submission ───────────────────────────────
// Buyers and sellers can both shop: UTHM gives each student one email, so a
// seller uses the same account to buy. The self-purchase check below still
// stops anyone from ordering their own listing.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action'])
    && $_GET['action'] === 'make_order'
    && in_array($user['role'], ['pembeli', 'penjual'], true)) {

    header('Content-Type: application/json');
    csrf_validate(); // Protect against CSRF attacks

    $qty = (int) ($_POST['quantity'] ?? 1);
    $payment_type = trim($_POST['payment_type'] ?? 'cash'); // 'cash' or 'online'
    $allowed_payment_types = ['cash', 'online'];

    if ($qty < 1) {
        http_response_code(422);
        die(json_encode(['success' => false, 'message' => 'Kuantiti pesanan tidak sah.']));
    }
    if (!in_array($payment_type, $allowed_payment_types, true)) {
        http_response_code(422);
        die(json_encode(['success' => false, 'message' => 'Jenis pembayaran tidak sah.']));
    }

    // Payment method is derived from the type, not from a client value.
    // The old cash/online-agnostic "Kaedah pembayaran" dropdown let a
    // buyer pick "Pindahan Bank" while still on the cash radio, which
    // stored an impossible combination. Cash = Tunai (settled in person);
    // Online = E-Wallet as a coarse flag; the real gateway channel
    // (fpx / ewallet / toyyibpay) is recorded on payments.payment_method
    // once the buyer picks it at the secure gateway.
    $payment_method = ($payment_type === 'online') ? 'E-Wallet' : 'Tunai';

    try {
        $pdo->beginTransaction();

        // Lock the current product row before checking availability. This makes
        // the stock check and decrement one serialized operation.
        // IMPORTANT: Include status check IN the SELECT to prevent race condition
        $stmt = $pdo->prepare(
            'SELECT p.*, u.is_verified, u.is_active
               FROM products p
               JOIN users u ON p.seller_id = u.user_id
              WHERE p.product_id = ? AND p.status = ?
              FOR UPDATE'
        );
        $stmt->execute([$product_id, 'Aktif']);
        $p = $stmt->fetch();

        if (!$p || (int) $p['stock'] < $qty) {
            $pdo->rollBack();
            http_response_code(422);
            die(json_encode(['success' => false, 'message' => 'Stok tidak mencukupi.']));
        }

        // Prevent buying your own product (trust score manipulation).
        if ((int) $p['seller_id'] === (int) $user['user_id']) {
            $pdo->rollBack();
            http_response_code(403);
            die(json_encode(['success' => false, 'message' => 'Anda tidak boleh membeli produk anda sendiri.']));
        }

        if (!(int) $p['is_verified'] || !(int) $p['is_active']) {
            $pdo->rollBack();
            http_response_code(403);
            die(json_encode(['success' => false, 'message' => 'Penjual produk ini tidak aktif atau belum disahkan oleh PTU.']));
        }

        // AI Trust Layer gate: high fraud-risk sellers are held from new sales
        // until a PTU admin reviews and releases the hold (trust_profiles.ai_hold).
        if (ai_trust_seller_hold($pdo, (int) $p['seller_id'], 'penjual')) {
            $pdo->rollBack();
            http_response_code(403);
            die(json_encode(['success' => false, 'message' => 'Penjual ini sedang disiasat oleh pentadbir (Lapisan AI). Pesanan baharu ditahan sementara — pilih penjual lain atau cuba lagi kemudian.']));
        }

        $stock_stmt = $pdo->prepare(
            'UPDATE products
                SET stock = stock - ?, sold_count = sold_count + ?
              WHERE product_id = ? AND status = ? AND stock >= ?'
        );
        $stock_stmt->execute([$qty, $qty, $product_id, 'Aktif', $qty]);

        if ($stock_stmt->rowCount() !== 1) {
            $pdo->rollBack();
            http_response_code(409);
            die(json_encode(['success' => false, 'message' => 'Stok telah berubah sebentar tadi. Sila cuba lagi.']));
        }

        $total_amount = $p['price'] * $qty;
        
        // FIX H4: Set proper payment status for cash orders
        // Create transaction with payment type
        $stmt = $pdo->prepare(
            'INSERT INTO transactions
                (buyer_id, seller_id, product_id, quantity, total_amount, 
                 payment_method, payment_type, payment_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        
        // Set appropriate initial payment status
        if ($payment_type === 'online') {
            $payment_status = 'unpaid';
        } else {
            $payment_status = 'unpaid_cash';  // Cash orders need seller confirmation
        }
        
        $stmt->execute([
            $user['user_id'],
            $p['seller_id'],
            $product_id,
            $qty,
            $total_amount,
            $payment_method,
            $payment_type,
            $payment_status
        ]);
        
        $transaction_id = $pdo->lastInsertId();

        $pdo->commit();
        
        // Redirect to payment for online orders
        if ($payment_type === 'online') {
            echo json_encode([
                'success'  => true,
                'message'  => 'Pesanan dibuat! Sila teruskan pembayaran.',
                'redirect' => $_base . '/payment/pay.php?txn=' . $transaction_id,
            ]);
        } else {
            echo json_encode([
                'success'  => true,
                'message'  => 'Pesanan berjaya dibuat!',
                'redirect' => $_base . '/dashboard/buyer.php?tab=pesanan',
            ]);
        }
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('[make_order] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Ralat pelayan.']);
    }
    exit;
}

// ── Load product ──────────────────────────────────────────
// Trust is read from the role-specific Bayesian profile (trust_profiles),
// NOT the legacy users.trust_score mirror. A brand-new seller therefore
// shows "Baharu" (grey) here, exactly as in every dashboard — instead of a
// harsh, meaningless "0/100" in red that contradicts the rest of the app.
$stmt = $pdo->prepare(
    'SELECT p.*, u.full_name AS seller_name,
            u.is_verified, u.is_active,
            COALESCE(NULLIF(u.matric_no, \'\'), u.staff_no) AS seller_ident,
            tp.profile_role             AS trust_role,
            tp.score                    AS trust_score,
            tp.raw_score                AS trust_raw_score,
            tp.confidence               AS trust_confidence,
            tp.confidence_label         AS trust_confidence_label,
            tp.trust_level              AS trust_level,
            tp.evidence_units           AS trust_evidence,
            tp.distinct_counterparties  AS trust_counterparties,
            tp.completed_transactions   AS trust_completed
       FROM products p
       JOIN users u ON p.seller_id = u.user_id
       LEFT JOIN trust_profiles tp
              ON tp.user_id = p.seller_id AND tp.profile_role = ?
      WHERE p.product_id = ? AND p.status != ?'
);
$stmt->execute(['penjual', $product_id, 'Dipadam']);
$product = $stmt->fetch();

if (!$product) {
    header('Location: ' . $dashboard_url);
    exit;
}

// AI Trust Layer state for this seller ([] when the worker has not run yet).
$seller_ai = ai_trust_fetch($pdo, (int) $product['seller_id'], 'penjual');

// Audit #2: a held seller used to be invisible until "Buat Pesanan" answered
// HTTP 403 at checkout. The same predicate the gate uses is computed here so the
// page can say so up front, with the same words (ai_trust_hold_notice_html).
$seller_hold = ai_trust_held($seller_ai);

// Role-specific Bayesian profile for the seller (score/evidence/level).
$seller_trust = trust_profile_from_row($product, 'trust_');

// Suspended or unverified sellers must not retain a purchasable storefront.
if (!(int) $product['is_verified'] || !(int) $product['is_active']) {
    http_response_code(403);
    die(<<<HTML
    <!DOCTYPE html>
    <html lang="ms">
    <head>
      <meta charset="UTF-8">
      <title>403 — Penjual Belum Disahkan</title>
      <style>
        body { font-family: sans-serif; display: flex; align-items: center;
               justify-content: center; min-height: 100vh; margin: 0;
               background: #FBF8F2; color: #1A1714; }
        .box { text-align: center; max-width: 440px; padding: 40px; }
        h1   { font-size: 32px; margin: 0 0 16px; color: #B03A2E; }
        p    { color: #6B6358; margin-bottom: 24px; line-height: 1.6; }
        a    { display: inline-block; padding: 10px 24px; background: #1A1714;
               color: #fff; border-radius: 8px; text-decoration: none; }
      </style>
    </head>
    <body>
      <div class="box">
        <h1>Akses Dihalang</h1>
        <p>Penjual produk ini belum disahkan oleh PTU UTHM atau dalam proses penggantungan. Sebarang pembelian tidak dibenarkan buat masa ini.</p>
        <a href="{$dashboard_url}">Kembali ke Papan Pemuka</a>
      </div>
    </body>
    </html>
    HTML);
}

// ── Load reviews for this product ────────────────────────
$stmt = $pdo->prepare(
    'SELECT r.rating, r.comment, r.created_at,
            u.full_name AS buyer_name
       FROM reviews r
       JOIN transactions t ON r.transaction_id = t.transaction_id
       JOIN users u         ON r.buyer_id       = u.user_id
      WHERE t.product_id = ?
      ORDER BY r.created_at DESC
      LIMIT 10'
);
$stmt->execute([$product_id]);
$reviews = $stmt->fetchAll();

// Calculate the overall product rating independently from the ten comments
// displayed below, so the summary remains accurate for popular listings.
$stmt = $pdo->prepare(
    'SELECT COUNT(*) AS review_count, COALESCE(AVG(r.rating), 0) AS avg_rating
       FROM reviews r
       JOIN transactions t ON r.transaction_id = t.transaction_id
      WHERE t.product_id = ?'
);
$stmt->execute([$product_id]);
$review_summary = $stmt->fetch();
$review_count = (int)($review_summary['review_count'] ?? 0);
$avg_rating = round((float)($review_summary['avg_rating'] ?? 0), 1);

// Sellers may shop from other sellers, but never from their own listings.
$is_own_product = (int) $product['seller_id'] === (int) $user['user_id'];
$can_shop = in_array($user['role'], ['pembeli', 'penjual'], true) && !$is_own_product;
if ($user['role'] === 'penjual' && !$is_own_product) {
    $dashboard_url = 'dashboard/buyer.php';
}

$page_title = $product['name'];
require_once __DIR__ . '/includes/header.php';
?>

  <!-- Minimal sidebar for product page -->
  <aside class="sidebar">
    <div class="logo-text">Trust<span>Mart</span></div>
    <div class="sidebar-footer">
      <div style="display:flex;align-items:center;gap:10px">
        <div class="avatar"><?= mb_substr($user['full_name'], 0, 1) ?></div>
        <div>
          <div style="font-size:12px;color:rgba(255,255,255,.8)">
            <?= htmlspecialchars($user['full_name']) ?>
          </div>
          <div style="font-size:10px;color:rgba(255,255,255,.35)">
            <?= $user['role'] ?> · <?= htmlspecialchars($user['ident_no']) ?>
          </div>
        </div>
      </div>
      <div style="margin-top:10px">
        <a href="<?= htmlspecialchars($dashboard_url) ?>"
           style="display:flex;align-items:center;gap:8px;padding:7px 0;
                  font-size:12px;color:rgba(255,255,255,.65);text-decoration:none">
          ← Kembali ke Papan Pemuka
        </a>
      </div>
    </div>
  </aside>

  <div class="main">
    <div class="topbar">
      <div style="font-family:'DM Serif Display',serif;font-size:16px">
        <?= htmlspecialchars($product['name']) ?>
      </div>
      <div style="margin-left:auto;font-size:12px;color:var(--ink3)">
        <?= $user['role'] ?> · <?= htmlspecialchars($user['ident_no']) ?>
      </div>
    </div>

    <div class="content">

      <!-- Product detail card -->
      <div class="product-detail-card" style="background:#fff;border:1px solid var(--cream3);border-radius:var(--r-lg);
                  overflow:hidden;display:grid;grid-template-columns:280px 1fr;
                  margin-bottom:24px">
        <!-- Image -->
        <div style="background:var(--cream2);display:flex;align-items:center;
                    justify-content:center;min-height:220px;padding:24px">
          <?php if ($product['image_path']): ?>
            <img src="uploads/<?= htmlspecialchars($product['image_path']) ?>"
                 alt="<?= htmlspecialchars($product['name']) ?>"
                 style="max-width:100%;max-height:220px;object-fit:contain;border-radius:8px">
          <?php else:
            // Category icon instead of a faint "broken image" outline.
            $cat_icon = ['Elektronik'=>'💻','Buku'=>'📚','Pakaian'=>'👕',
                         'Makanan'=>'🍔','Perkhidmatan'=>'🛠️'][$product['category']] ?? '📦'; ?>
            <div style="font-size:64px" aria-hidden="true"><?= $cat_icon ?></div>
          <?php endif ?>
        </div>

        <!-- Info -->
        <div style="padding:28px">
          <div style="display:flex;align-items:flex-start;justify-content:space-between;
                      gap:8px;margin-bottom:10px">
            <span style="background:var(--cream2);color:var(--ink2);padding:3px 10px;
                         border-radius:20px;font-size:11px">
              <?= htmlspecialchars($product['category']) ?>
            </span>
            <span class="badge <?= $product['stock'] > 0 ? 'badge-green' : 'badge-red' ?>">
              <span class="badge-dot"></span>
              <?= $product['stock'] > 0 ? 'Stok: '.$product['stock'] : 'Stok Habis' ?>
            </span>
          </div>

          <h1 style="font-family:'DM Serif Display',serif;font-size:22px;
                     font-weight:400;margin:8px 0 4px">
            <?= htmlspecialchars($product['name']) ?>
          </h1>

          <div style="font-size:28px;font-weight:600;margin:8px 0 12px">
            RM <?= number_format($product['price'], 2) ?>
          </div>

          <p style="font-size:13px;color:var(--ink3);line-height:1.7;margin-bottom:16px">
            <?= nl2br(htmlspecialchars($product['description'] ?? '')) ?>
          </p>

          <!-- Seller info -->
          <div style="display:flex;align-items:center;gap:10px;margin-bottom:18px;
                      padding:12px;background:var(--cream);border-radius:var(--r)">
            <div class="avatar" style="background:var(--blue-bg);color:var(--blue)">
              <?= mb_substr($product['seller_name'], 0, 1) ?>
            </div>
            <div style="flex:1">
              <div style="font-size:13px;font-weight:500">
                <?= htmlspecialchars($product['seller_name']) ?>
                <?php if ($product['is_verified']): ?>
                <span style="background:var(--gold-pale);color:#7A5A10;padding:1px 7px;
                             border-radius:10px;font-size:10px;margin-left:6px">
                  ✓ Disahkan PTU
                </span>
                <?php endif ?>
              </div>
              <div style="font-size:11px;color:var(--ink3);margin-top:2px">
                Skor Kepercayaan:
                <b style="color:<?= trust_score_color($seller_trust) ?>"><?= htmlspecialchars(trust_score_display($seller_trust)) ?></b> ·
                Terjual: <?= $product['sold_count'] ?>
                <?php if (!empty($seller_ai)): ?>
                  · <?= ai_trust_badge_html($seller_ai) ?>
                <?php endif ?>
              </div>
            </div>
          </div>

          <?php if (!empty($seller_ai)): ?>
          <!-- Explainable AI breakdown — buyers can see exactly why. -->
          <details style="margin:-8px 0 16px;font-size:12px">
            <summary style="cursor:pointer;color:var(--ink3);font-size:11px;outline:none">
              Bagaimana kami mengesahkan penjual ini? (Lapisan AI Anti-Penipuan)
            </summary>
            <?= ai_trust_detail_html($seller_ai) ?>
          </details>
          <?php endif ?>

          <!-- Order controls (buyers, and sellers shopping from other sellers) -->
          <?php if ($can_shop && $product['stock'] > 0 && !$seller_hold): ?>
          
          <!-- Payment Type Selection -->
          <div style="margin-bottom:16px;padding:14px;background:var(--cream);border-radius:var(--r)">
            <div style="font-size:13px;font-weight:600;margin-bottom:10px;color:var(--ink2)">
              Pilih Jenis Pembayaran
            </div>
            <div style="display:flex;gap:10px;flex-wrap:wrap">
              <label style="flex:1;min-width:140px;padding:12px;border:2px solid var(--cream3);border-radius:var(--r);cursor:pointer;transition:all 0.2s;background:#fff" 
                     class="payment-type-option">
                <input type="radio" name="payment_type" value="cash" checked style="margin-right:8px">
                <strong style="font-size:13px">💵 Bayar Terus</strong>
                <div style="font-size:11px;color:var(--ink3);margin-top:4px">Bayaran secara tunai</div>
              </label>
              
              <label style="flex:1;min-width:140px;padding:12px;border:2px solid var(--cream3);border-radius:var(--r);cursor:pointer;transition:all 0.2s;background:#fff" 
                     class="payment-type-option">
                <input type="radio" name="payment_type" value="online" style="margin-right:8px">
                <strong style="font-size:13px">💳 Bayar Dalam Talian</strong>
                <div style="font-size:11px;color:var(--ink3);margin-top:4px">FPX / Dompet Digital (Selamat)</div>
              </label>
            </div>
          </div>
          
          <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
            <div role="group" aria-label="Pilih kuantiti" style="display:flex;align-items:center;border:1px solid var(--cream3);
                        border-radius:var(--r-sm);overflow:hidden">
              <button type="button" aria-label="Kurangkan kuantiti" onclick="chgQty(-1)" style="width:40px;height:40px;background:var(--cream);
                      border:none;font-size:18px;cursor:pointer;color:var(--ink)">−</button>
              <output id="qty-val" aria-live="polite" aria-label="Kuantiti" style="width:48px;text-align:center;font-size:13px;
                      font-weight:600;border-left:1px solid var(--cream3);
                      border-right:1px solid var(--cream3);line-height:40px">1</output>
              <button type="button" aria-label="Tambah kuantiti" onclick="chgQty(1)" style="width:40px;height:40px;background:var(--cream);
                      border:none;font-size:18px;cursor:pointer;color:var(--ink)">+</button>
            </div>

            <label class="tm-sr-only" for="qty-val">Kuantiti</label>

            <button type="button" id="place-order-btn" class="btn btn-primary" style="flex:1;min-width:140px;
                    justify-content:center;padding:9px" onclick="placeOrder(event)">
              Buat Pesanan
            </button>
          </div>
          <div style="font-size:11px;color:var(--ink4);margin-top:8px">
            Jumlah: RM <span id="total-price"><?= number_format($product['price'], 2) ?></span>
          </div>
          <?php elseif ($can_shop && $product['stock'] > 0 && $seller_hold): ?>
          <!-- Held by the AI layer: no order controls at all, and the reason. -->
          <?= ai_trust_hold_notice_html($seller_ai) ?>
          <?php elseif ($is_own_product): ?>
          <div style="font-size:13px;color:var(--ink3);padding:12px;background:var(--cream);
                      border-radius:var(--r)">
            Ini produk anda. Urus stok dan harga di
            <a href="dashboard/seller.php?tab=produk" style="color:var(--gold-strong);font-weight:600">Papan Pemuka Penjual</a>.
          </div>
          <?php elseif (!$can_shop): ?>
          <div style="font-size:13px;color:var(--ink3);padding:12px;background:var(--cream);
                      border-radius:var(--r)">
            Akaun pentadbir tidak boleh membuat pesanan.
          </div>
          <?php else: ?>
          <div style="font-size:13px;color:var(--red);padding:12px;
                      background:var(--red-bg);border-radius:var(--r)">
            Produk ini sudah kehabisan stok.
          </div>
          <?php endif ?>

          <?php if ($can_shop): ?>
          <div style="margin-top: 14px;">
            <button type="button" class="btn" style="width:100%; justify-content:center; padding:10px; border-color:var(--gold); color:var(--gold-strong); font-weight:600;" 
                    onclick="contactSeller()">
              💬 Hubungi Penjual
            </button>
          </div>
          <?php endif ?>
        </div>
      </div>

      <!-- Reviews section -->
      <div class="section-hd">
        <div class="section-title">
          Ulasan Pembeli
          <?php if ($avg_rating > 0): ?>
          <span style="font-size:13px;color:var(--gold);margin-left:8px">
            <?= str_repeat('★', (int)round($avg_rating)) ?><?= str_repeat('☆', 5-(int)round($avg_rating)) ?>
            <?= $avg_rating ?> / 5
          </span>
          <?php endif ?>
        </div>
        <span style="font-size:12px;color:var(--ink3)"><?= $review_count ?> ulasan</span>
      </div>

      <?php if (empty($reviews)): ?>
      <div class="empty-state">
        <div style="font-size:32px;opacity:.3;margin-bottom:10px">💬</div>
        <div style="font-weight:500">Belum ada ulasan</div>
        <div style="font-size:12px;margin-top:4px">Jadilah orang pertama memberikan ulasan</div>
      </div>
      <?php else: ?>
      <?php foreach ($reviews as $r): ?>
      <div style="background:#fff;border:1px solid var(--cream3);border-radius:var(--r);
                  padding:16px;margin-bottom:10px">
        <div style="display:flex;align-items:center;justify-content:space-between;
                    margin-bottom:6px">
          <div style="font-size:13px;font-weight:500">
            <?= htmlspecialchars($r['buyer_name']) ?>
          </div>
          <div style="display:flex;align-items:center;gap:8px">
            <span style="color:var(--gold)">
              <?= str_repeat('★', $r['rating']) ?><?= str_repeat('☆', 5-$r['rating']) ?>
            </span>
            <span style="font-size:11px;color:var(--ink4)">
              <?= date('d M Y', strtotime($r['created_at'])) ?>
            </span>
          </div>
        </div>
        <?php if ($r['comment']): ?>
        <div style="font-size:13px;color:var(--ink3);line-height:1.6">
          <?= htmlspecialchars($r['comment']) ?>
        </div>
        <?php endif ?>
      </div>
      <?php endforeach ?>
      <?php endif ?>

    </div><!-- end content -->
  </div><!-- end main -->

<script>
const UNIT_PRICE = <?= (float) $product['price'] ?>;
const MAX_STOCK  = <?= (int)   $product['stock']  ?>;
const CHAT_CONTEXT = <?= json_encode([
    'sellerId'   => (int)$product['seller_id'],
    'productId'  => (int)$product['product_id'],
    'sellerName' => $product['seller_name'],
    'productName'=> $product['name'],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

// Payment type selection styling. The single "Bayar Terus / Bayar Dalam
// Talian" radio pair is the only payment picker — the derived method
// (Tunai vs E-Wallet) is decided server-side from the chosen type, so
// there is no secondary dropdown to keep in sync anymore.
document.addEventListener('DOMContentLoaded', () => {
    const paymentTypeOptions = document.querySelectorAll('.payment-type-option');
    paymentTypeOptions.forEach(option => {
        const radio = option.querySelector('input[type="radio"]');
        radio.addEventListener('change', () => {
            paymentTypeOptions.forEach(opt => {
                opt.style.borderColor = 'var(--cream3)';
                opt.style.background = '#fff';
            });
            if (radio.checked) {
                option.style.borderColor = 'var(--blue)';
                option.style.background = 'var(--blue-bg)';
            }
        });
        // Set initial state
        if (radio.checked) {
            option.style.borderColor = 'var(--blue)';
            option.style.background = 'var(--blue-bg)';
        }
    });
});

function chgQty(delta) {
    const el  = document.getElementById('qty-val');
    const tot = document.getElementById('total-price');
    let v = parseInt(el.textContent) + delta;
    v = Math.max(1, Math.min(MAX_STOCK, v));
    el.textContent  = v;
    tot.textContent = (UNIT_PRICE * v).toLocaleString('ms-MY', {minimumFractionDigits:2});
}

async function placeOrder(event) {
    const qty     = parseInt(document.getElementById('qty-val').textContent);
    const typeEl  = document.querySelector('input[name="payment_type"]:checked');
    const paymentType = typeEl ? typeEl.value : 'cash';
    const btn     = event.currentTarget;

    btn.disabled     = true;
    btn.textContent  = 'Memproses...';

    try {
        const res  = await fetch('?action=make_order&id=<?= $product_id ?>', {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    new URLSearchParams({
                quantity: qty,
                payment_type: paymentType
            }),
        });
        const data = await res.json();

        if (data.success) {
            showToast(data.message, 'success');
            setTimeout(() => window.location.href = data.redirect, 1000);
        } else {
            showToast(data.message, 'error');
            btn.disabled    = false;
            btn.textContent = 'Buat Pesanan';
        }
    } catch (e) {
        showToast('Ralat rangkaian. Sila cuba lagi.', 'error');
        btn.disabled    = false;
        btn.textContent = 'Buat Pesanan';
    }
}

function contactSeller() {
    const params = new URLSearchParams({
        tab: 'chat',
        seller_id: String(CHAT_CONTEXT.sellerId),
        seller_name: CHAT_CONTEXT.sellerName,
        product_id: String(CHAT_CONTEXT.productId),
        product_name: CHAT_CONTEXT.productName,
    });
    window.location.href = `dashboard/buyer.php?${params.toString()}`;
}
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>