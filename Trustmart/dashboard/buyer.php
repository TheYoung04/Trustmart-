<?php
// ============================================================
// dashboard/buyer.php
// Main dashboard for logged-in buyers (pembeli).
// Fetches real orders, products, and stats from MySQL.
// Handles AJAX sub-actions posted to the same URL:
//   ?action=cancel_order
//   ?action=confirm_received
//   ?action=submit_review
//   ?action=get_products   (search + filter)
// ============================================================

require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/trust_score.php';
require_once __DIR__ . '/../includes/ai_trust.php';
require_once __DIR__ . '/../includes/notifications.php';
refresh_session_user($pdo);

// Sellers use the same single UTHM account to shop, so they can open this
// dashboard in buyer mode. Their own listings are hidden from browsing.
require_role(['pembeli', 'penjual']);

$user = current_user();
$is_seller_account = $user['role'] === 'penjual';

// A redirect that lands here (e.g. payment/pay.php closing an expired order)
// carries a one-off notice in $_SESSION['error_msg']. Show it on the full page
// load and CLEAR it, or it survives to the next page and is mis-displayed out
// of context. The AJAX router below must not consume it, hence the action guard.
$flash_msg = '';
if (!isset($_GET['action'])) {
    $flash_msg = (string) ($_SESSION['error_msg'] ?? '');
    if ($flash_msg !== '') unset($_SESSION['error_msg']);
}

// Buyer-side profile remains separate when a seller shops using the same
// UTHM account. Components come from the database scoring procedure.
$buyer_id = $user['user_id'];
$buyer_trust = trust_profile($pdo, $buyer_id, 'pembeli');
$buyer_score = (float)$buyer_trust['score'];
$seller_mode_trust = $is_seller_account
    ? trust_profile($pdo, $buyer_id, 'penjual')
    : null;

// ── AJAX action router ────────────────────────────────────────
// All fetch() calls from the front-end hit this same file
// with an ?action= query param and POST body.
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_validate();

    switch ($_GET['action']) {

        // ── Cancel an order (Menunggu only) ───────────────────
        // A buyer may freely cancel a not-yet-confirmed order. Once the seller
        // has confirmed/dispatched (Disahkan) the buyer must NOT be able to
        // self-cancel and auto-refund while keeping the goods — that path is
        // routed through the dispute flow instead (see submit_dispute).
        case 'cancel_order':
            $txn_id = (int) ($_POST['transaction_id'] ?? 0);

            try {
                $pdo->beginTransaction();

                // Serialize all status changes for this order so duplicate or
                // competing requests cannot restore the same stock twice.
                $stmt = $pdo->prepare(
                    'SELECT t.transaction_id, t.product_id, t.quantity, t.status,
                            t.payment_type, t.payment_status, t.payment_reference,
                            t.total_amount
                       FROM transactions t
                      WHERE t.transaction_id = ? AND t.buyer_id = ?
                      FOR UPDATE'
                );
                $stmt->execute([$txn_id, $user['user_id']]);
                $txn = $stmt->fetch();

                if (!$txn) {
                    $pdo->rollBack();
                    http_response_code(404);
                    die(json_encode(['success'=>false,'message'=>'Pesanan tidak dijumpai.']));
                }
                if (!in_array($txn['status'], ['Menunggu'], true)) {
                    $pdo->rollBack();
                    http_response_code(422);
                    $msg = ($txn['status'] === 'Disahkan')
                        ? 'Pesanan yang telah disahkan penjual tidak boleh dibatalkan sendiri. Sila failkan aduan atau hubungi penjual untuk pembatalan.'
                        : 'Pesanan ini tidak boleh dibatalkan.';
                    die(json_encode([
                        'success' => false,
                        'message' => $msg,
                    ]));
                }

                $pdo->prepare(
                    'UPDATE transactions
                        SET status = ?, cancelled_at = CURRENT_TIMESTAMP,
                            cancelled_by = \'buyer\'
                      WHERE transaction_id = ?'
                )->execute(['Dibatalkan', $txn_id]);

                // Close any still-open (pending) payment attempt so it cannot
                // later be completed and mark this cancelled order as paid.
                $pdo->prepare(
                    'UPDATE payments
                        SET status = \'failed\',
                            gateway_response = ?
                      WHERE transaction_id = ?
                        AND status = \'pending\''
                )->execute([
                    json_encode(['gateway' => 'internal', 'reason' => 'order_cancelled_before_payment', 'timestamp' => date('c')]),
                    $txn_id,
                ]);
                if ($txn['payment_status'] === 'pending') {
                    $pdo->prepare(
                        'UPDATE transactions SET payment_status = \'failed\' WHERE transaction_id = ?'
                    )->execute([$txn_id]);
                }

                // Return stock only after the locked status transition succeeds.
                $pdo->prepare(
                    'UPDATE products
                        SET stock = stock + ?, sold_count = GREATEST(sold_count - ?, 0)
                      WHERE product_id = ?'
                )->execute([$txn['quantity'], $txn['quantity'], $txn['product_id']]);

                // Refund only for orders actually paid online. process_payment_refund()
                // owns ALL refund state and flips transactions.payment_status to
                // 'refunded' only when a refund is CONFIRMED completed. Here we must
                // never claim a refund that did not actually happen.
                $cash_reconcile = false;
                if ($txn['payment_type'] === 'online' && $txn['payment_status'] === 'paid') {
                    // Import refund processing function
                    require_once __DIR__ . '/../config/payment.php';

                    // Fetch the payment record by reference (any status) so a state
                    // mismatch is recorded as an actionable refund item rather than
                    // being silently marked refunded.
                    $payment_stmt = $pdo->prepare(
                        'SELECT payment_id, status FROM payments
                          WHERE payment_reference = ?
                          LIMIT 1'
                    );
                    $payment_stmt->execute([$txn['payment_reference']]);
                    $payment = $payment_stmt->fetch();

                    if ($payment && $payment['status'] === 'completed') {
                        // Process the actual refund through the gateway. The helper
                        // records refund_status (completed/processing/failed) and only
                        // sets payment_status='refunded' on confirmed completion.
                        $refund_result = process_payment_refund(
                            $pdo,
                            $payment['payment_id'],
                            'Pesanan dibatalkan oleh pembeli',
                            'manual'
                        );

                        if (!$refund_result['success']) {
                            // Refund could NOT be completed: keep payment_status='paid'
                            // (money is still held). The helper already stamped
                            // payments.refund_status='failed' + refund_error, which
                            // surfaces this order in the admin refund queue.
                            error_log("[cancel_order] RECONCILE refund_failed txn={$txn_id} payment={$payment['payment_id']} err={$refund_result['error']}");
                        }
                    } elseif ($payment) {
                        // A payment row exists but is not 'completed' while the order
                        // shows paid — inconsistent state. Do NOT mark refunded; flag it
                        // as an actionable failed refund and leave payment_status='paid'.
                        $pdo->prepare(
                            "UPDATE payments
                                SET refund_status = 'failed',
                                    refund_error  = ?
                              WHERE payment_id = ? AND refund_status = 'none'"
                        )->execute([
                            'Cancellation requested but payment is not completed (status=' . $payment['status'] . ')',
                            $payment['payment_id']
                        ]);
                        error_log("[cancel_order] RECONCILE payment_not_completed txn={$txn_id} payment={$payment['payment_id']} status={$payment['status']} -> kept payment_status='paid'");
                    } else {
                        // No payment record at all: repayment cannot be evidenced, so we
                        // must NOT claim refunded. Keep payment_status='paid'; the order
                        // is then findable as (status='Dibatalkan' AND payment_status='paid')
                        // for manual reconciliation by an admin.
                        error_log("[cancel_order] RECONCILE missing_payment txn={$txn_id} ref={$txn['payment_reference']} -> kept payment_status='paid', manual refund required");
                    }
                }
                // L1: Buyer cancelled a cash order whose payment was already
                // confirmed received by the seller (paid_cash but the order was
                // still in Menunggu). No gateway can reverse physical cash, so
                // stamp the reconciliation obligation on cash_payments and warn
                // the buyer. The seller's dashboard already lists this order
                // under Dibatalkan + Bayar Tunai, and the audit trail is
                // visible to PTU via the [CASH_RECONCILE] tag.
                elseif ($txn['payment_type'] === 'cash' && $txn['payment_status'] === 'paid_cash') {
                    $cash_reconcile = true;
                    $pdo->prepare(
                        "UPDATE cash_payments
                            SET seller_notes = CONCAT(
                                    COALESCE(seller_notes, ''),
                                    '\n[CASH_RECONCILE ', NOW(), '] Pembatalan oleh pembeli selepas tunai diterima. Penjual WAJIB pulangkan RM', ?, ' kepada pembeli secara manual.')
                          WHERE transaction_id = ?"
                    )->execute([
                        number_format($txn['total_amount'], 2, '.', ''),
                        $txn_id,
                    ]);
                    error_log("[cancel_order] CASH_RECONCILE txn={$txn_id} buyer cancelled AFTER seller confirmed cash receipt — seller must return RM{$txn['total_amount']} to buyer");
                }

                $pdo->commit();
                $resp = ['success'=>true,'message'=>'Pesanan berjaya dibatalkan.'];
                if (!empty($cash_reconcile)) {
                    $resp['cash_reconcile_required'] = true;
                    $resp['warning'] = 'Amaran: Penjual telah menerima pembayaran tunai untuk pesanan ini. Sila uruskan pemulangan wang dengan penjual. Rekod ini akan dipantau oleh PTU.';
                    // Bake the warning into `message` so the existing success
                    // toast (which only reads data.message) surfaces it to the
                    // buyer without a frontend change.
                    $resp['message'] .= ' ⚠ ' . $resp['warning'];
                }
                echo json_encode($resp);
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[cancel_order] ' . $e->getMessage());
                http_response_code(500);
                echo json_encode(['success'=>false,'message'=>'Ralat pelayan.']);
            }
            exit;

        // ── Confirm received (Disahkan → Selesai) ─────────────
        case 'confirm_received':
            $txn_id = (int) ($_POST['transaction_id'] ?? 0);

            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare(
                    'SELECT transaction_id, status
                       FROM transactions
                      WHERE transaction_id = ? AND buyer_id = ?
                      FOR UPDATE'
                );
                $stmt->execute([$txn_id, $user['user_id']]);
                $txn = $stmt->fetch();

                if (!$txn || $txn['status'] !== 'Disahkan') {
                    $pdo->rollBack();
                    http_response_code(422);
                    die(json_encode([
                        'success' => false,
                        'message' => 'Pesanan tidak boleh ditanda selesai.',
                    ]));
                }

                $pdo->prepare(
                    'UPDATE transactions
                        SET status = ?, completed_at = CURRENT_TIMESTAMP,
                            completed_by = \'buyer\'
                      WHERE transaction_id = ?'
                )->execute(['Selesai', $txn_id]);

                $pdo->commit();
                echo json_encode([
                    'success' => true,
                    'message' => 'Pesanan ditanda selesai. Sila berikan ulasan!',
                ]);
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[confirm_received] ' . $e->getMessage());
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Ralat pelayan.']);
            }
            exit;

        // ── Submit a star review ───────────────────────────────
        case 'submit_review':
            $txn_id  = (int) ($_POST['transaction_id'] ?? 0);
            $rating  = (int) ($_POST['rating']         ?? 0);
            $comment = trim($_POST['comment']           ?? '');

            if ($rating < 1 || $rating > 5) {
                http_response_code(422);
                die(json_encode(['success'=>false,'message'=>'Rating mesti antara 1 dan 5.']));
            }
            if (mb_strlen($comment) > 2000) {
                http_response_code(422);
                die(json_encode(['success'=>false,'message'=>'Ulasan tidak boleh melebihi 2000 aksara.']));
            }

            // Verify ownership and that order is Selesai
            $stmt = $pdo->prepare(
                'SELECT t.transaction_id, t.seller_id
                   FROM transactions t
                  WHERE t.transaction_id = ?
                    AND t.buyer_id       = ?
                    AND t.status         = ?'
            );
            $stmt->execute([$txn_id, $user['user_id'], 'Selesai']);
            $txn = $stmt->fetch();

            if (!$txn) {
                http_response_code(422);
                die(json_encode([
                    'success' => false,
                    'message' => 'Anda hanya boleh mengulas pesanan yang telah selesai.',
                ]));
            }

            // Check if review already exists (UNIQUE constraint backup)
            $stmt = $pdo->prepare(
                'SELECT review_id FROM reviews WHERE transaction_id = ?'
            );
            $stmt->execute([$txn_id]);
            if ($stmt->fetch()) {
                http_response_code(409);
                die(json_encode([
                    'success' => false,
                    'message' => 'Anda sudah memberikan ulasan untuk pesanan ini.',
                ]));
            }

            try {
                $pdo->prepare(
                    'INSERT INTO reviews
                        (transaction_id, buyer_id, seller_id, rating, comment)
                     VALUES (?, ?, ?, ?, ?)'
                )->execute([
                    $txn_id,
                    $user['user_id'],
                    $txn['seller_id'],
                    $rating,
                    $comment ?: null,
                ]);
                // The after_review_insert trigger fires automatically here,
                // recalculating the seller's trust score in the database.
                //
                // The AI layer sits on top of that baseline, so the review has to
                // recompute it too or the score buyers see stays frozen at the last
                // cron run (audit #3b). Best-effort by design: the review is already
                // saved and must not be failed by a scoring problem.
                ai_trust_refresh_after_write($pdo, (int) $txn['seller_id'], 'penjual');

                echo json_encode([
                    'success' => true,
                    'message' => 'Ulasan berjaya dihantar. Terima kasih!',
                ]);
            } catch (PDOException $e) {
                error_log('[submit_review] ' . $e->getMessage());
                http_response_code(500);
                echo json_encode(['success'=>false,'message'=>'Ralat pelayan.']);
            }
            exit;

        // ── Submit a dispute ──────────────────────────────────
        case 'submit_dispute':
            $txn_id      = (int) ($_POST['transaction_id'] ?? 0);
            $title       = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $severity    = trim($_POST['severity'] ?? 'Sederhana');

            if ($title === '' || $description === '') {
                http_response_code(422);
                die(json_encode(['success' => false, 'message' => 'Tajuk dan butiran aduan diperlukan.']));
            }
            if (mb_strlen($title) > 200 || mb_strlen($description) > 5000) {
                http_response_code(422);
                die(json_encode([
                    'success' => false,
                    'message' => 'Tajuk dihadkan kepada 200 aksara dan butiran kepada 5000 aksara.',
                ]));
            }

            $allowed_severities = ['Rendah', 'Sederhana', 'Mendesak'];
            if (!in_array($severity, $allowed_severities, true)) {
                $severity = 'Sederhana';
            }

            try {
                $pdo->beginTransaction();

                // Lock the parent transaction so concurrent submissions for
                // the same order cannot both pass the duplicate check.
                $stmt = $pdo->prepare(
                    'SELECT transaction_id, status
                       FROM transactions
                      WHERE transaction_id = ? AND buyer_id = ?
                      FOR UPDATE'
                );
                $stmt->execute([$txn_id, $user['user_id']]);
                $txn = $stmt->fetch();

                if (!$txn) {
                    $pdo->rollBack();
                    http_response_code(404);
                    die(json_encode(['success' => false, 'message' => 'Pesanan tidak dijumpai.']));
                }

                if (!in_array($txn['status'], ['Disahkan', 'Selesai'], true)) {
                    $pdo->rollBack();
                    http_response_code(422);
                    die(json_encode(['success' => false, 'message' => 'Aduan hanya boleh difailkan untuk pesanan yang disahkan atau selesai sahaja.']));
                }

                $stmt = $pdo->prepare(
                    'SELECT dispute_id FROM disputes WHERE transaction_id = ?'
                );
                $stmt->execute([$txn_id]);
                if ($stmt->fetch()) {
                    $pdo->rollBack();
                    http_response_code(409);
                    die(json_encode(['success' => false, 'message' => 'Aduan telah difailkan sebelum ini untuk pesanan ini.']));
                }

                $stmt = $pdo->prepare(
                    'INSERT INTO disputes
                        (transaction_id, filed_by, title, description, severity, status)
                     VALUES (?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $txn_id,
                    $user['user_id'],
                    $title,
                    $description,
                    $severity,
                    'Terbuka'
                ]);

                $pdo->commit();
                echo json_encode([
                    'success' => true,
                    'message' => 'Aduan berjaya dihantar dan sedang disemak oleh pentadbir PTU.',
                ]);
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                    http_response_code(409);
                    echo json_encode(['success' => false, 'message' => 'Aduan telah difailkan sebelum ini untuk pesanan ini.']);
                } else {
                    error_log('[submit_dispute] ' . $e->getMessage());
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Ralat pelayan semasa menghantar aduan.']);
                }
            }
            exit;

        // ── Search + filter products (with pagination) ────────
        case 'get_products':
            $search   = trim($_GET['q']        ?? '');
            $category = trim($_GET['category'] ?? '');
            $sort     = $_GET['sort']           ?? 'terbaharu';
            $page     = max(1, (int)($_GET['page'] ?? 1));
            $per_page = max(1, min(50, (int)($_GET['per_page'] ?? 12)));
            $offset   = ($page - 1) * $per_page;

            // Never offer a seller their own listings while they shop.
            $params    = [$user['user_id']];
            $where     = ["p.status = 'Aktif'", "p.stock > 0", "u.is_verified = 1 AND u.is_active = 1", "p.seller_id <> ?"];

            if ($search !== '') {
                $clean_search = trim(preg_replace('/[+\-><\(\)~*\"@]/u', ' ', $search));
                $words = array_filter(explode(' ', $clean_search), fn($w) => mb_strlen($w) > 0);
                if (!empty($words)) {
                    $boolean_query = implode(' ', array_map(fn($w) => '+' . $w . '*', $words));
                    $where[]  = '(MATCH(p.name, p.description) AGAINST (? IN BOOLEAN MODE) OR p.name LIKE ? OR p.description LIKE ?)';
                    $params[] = $boolean_query;
                    $params[] = '%' . $search . '%';
                    $params[] = '%' . $search . '%';
                } else {
                    $where[]  = '(p.name LIKE ? OR p.description LIKE ?)';
                    $params[] = '%' . $search . '%';
                    $params[] = '%' . $search . '%';
                }
            }

            $allowed_cats = ['Elektronik','Buku','Pakaian','Makanan','Perkhidmatan','Lain-lain'];
            if ($category !== '' && in_array($category, $allowed_cats, true)) {
                $where[]  = 'p.category = ?';
                $params[] = $category;
            }

            $order = match($sort) {
                'harga-asc'   => 'p.price ASC',
                'harga-desc'  => 'p.price DESC',
                'trust-desc'  => 'COALESCE(tp.ai_trust_score, tp.score, 0) DESC',
                default       => 'p.created_at DESC',
            };

            $where_str = implode(' AND ', $where);

            // Total count query
            $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM products p JOIN users u ON p.seller_id = u.user_id WHERE {$where_str}");
            $count_stmt->execute($params);
            $total = (int)$count_stmt->fetchColumn();
            $total_pages = $total > 0 ? (int)ceil($total / $per_page) : 1;

            $sql = "SELECT p.product_id, p.name, p.category, p.description,
                           p.price, p.stock, p.sold_count, p.image_path,
                           u.full_name AS seller_name,
                           u.is_verified,
                           tp.score AS trust_baseline, tp.evidence_units AS trust_evidence,
                           tp.ai_trust_score, tp.ai_adjustment, tp.fraud_risk, tp.ai_hold
                      FROM products p
                      JOIN users u ON p.seller_id = u.user_id
                 LEFT JOIN trust_profiles tp ON tp.user_id = p.seller_id AND tp.profile_role = 'penjual'
                     WHERE {$where_str}
                     ORDER BY {$order}
                     LIMIT {$per_page} OFFSET {$offset}";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $products = $stmt->fetchAll();

            // Attach the unified trust label (same "Baharu" convention as the
            // product page and chat header) so the JS grid renders verbatim.
            foreach ($products as &$row) {
                $display = trust_display_from_row($row);
                $row['trust_display']       = $display['label'];
                $row['trust_display_color'] = $display['color'];
                $row['trust_has_score']     = $display['has_score'];
                // Audit #2: the hold has to be visible on the card, computed by
                // the same predicate the checkout gate uses — never re-derived in JS.
                $row['seller_hold']         = ai_trust_hold_state($row['fraud_risk'], $row['ai_hold'] ?? null);
            }
            unset($row);


            echo json_encode([
                'success'    => true,
                'products'   => $products,
                'pagination' => [
                    'page'        => $page,
                    'per_page'    => $per_page,
                    'total'       => $total,
                    'total_pages' => $total_pages,
                ]
            ]);
            exit;

        // ── Fetch buyer orders (with pagination) ───────────────
        case 'get_orders':
            $status_filter = trim($_GET['status'] ?? '');
            $page          = max(1, (int)($_GET['page'] ?? 1));
            $per_page      = max(1, min(50, (int)($_GET['per_page'] ?? 10)));
            $offset        = ($page - 1) * $per_page;

            $params = [$user['user_id']];
            $where  = 'WHERE t.buyer_id = ?';

            $allowed_statuses = ['Menunggu','Disahkan','Selesai','Dibatalkan'];
            if (in_array($status_filter, $allowed_statuses, true)) {
                $where   .= ' AND t.status = ?';
                $params[] = $status_filter;
            }

            // Total count
            $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM transactions t {$where}");
            $count_stmt->execute($params);
            $total = (int)$count_stmt->fetchColumn();
            $total_pages = $total > 0 ? (int)ceil($total / $per_page) : 1;

            $stmt = $pdo->prepare(
                "SELECT t.transaction_id, t.status, t.total_amount,
                        t.payment_method, t.payment_type, t.payment_status, 
                        t.paid_at, t.payment_reference, t.created_at,
                        p.name AS product_name, p.category, p.image_path,
                        u.full_name AS seller_name,
                        r.rating, r.review_id,
                        d.dispute_id, d.status AS dispute_status
                   FROM transactions t
                   JOIN products p ON t.product_id = p.product_id
                   JOIN users    u ON t.seller_id  = u.user_id
              LEFT JOIN reviews  r ON r.transaction_id = t.transaction_id
              LEFT JOIN disputes d ON d.transaction_id = t.transaction_id
                  {$where}
                  ORDER BY t.created_at DESC
                  LIMIT {$per_page} OFFSET {$offset}"
            );
            $stmt->execute($params);
            $orders = $stmt->fetchAll();

            echo json_encode([
                'success'    => true,
                'orders'     => $orders,
                'pagination' => [
                    'page'        => $page,
                    'per_page'    => $per_page,
                    'total'       => $total,
                    'total_pages' => $total_pages,
                ]
            ]);
            exit;

        default:
            http_response_code(400);
            die(json_encode(['success'=>false,'message'=>'Tindakan tidak diketahui.']));
    }
}

// ── Page data queries (for initial HTML render) ───────────────

// Orders summary counts
$stmt = $pdo->prepare(
    'SELECT status, COUNT(*) AS cnt
       FROM transactions
      WHERE buyer_id = ?
      GROUP BY status'
);
$stmt->execute([$user['user_id']]);
$counts_raw = $stmt->fetchAll();

$counts = ['Menunggu' => 0, 'Disahkan' => 0, 'Selesai' => 0, 'Dibatalkan' => 0];
foreach ($counts_raw as $row) {
    $counts[$row['status']] = (int) $row['cnt'];
}
$total_orders  = array_sum($counts);
$active_orders = $counts['Menunggu'] + $counts['Disahkan'];

// Pending-action badge (sidebar chip + topbar pill), same shape as the admin
// console. Every buyer mutation ends in location.reload(), so the page-load
// number is the only one that has to be right here.
$notify = pending_actions($pdo, (int) $user['user_id'], 'pembeli');

// Recent orders (last 20, newest first)
$stmt = $pdo->prepare(
    'SELECT t.transaction_id, t.status, t.total_amount,
            t.payment_method, t.payment_type, t.payment_status,
            t.paid_at, t.payment_reference, t.created_at,
            p.name AS product_name, p.category, p.image_path,
            u.full_name AS seller_name,
            r.rating, r.review_id,
            d.dispute_id, d.status AS dispute_status
       FROM transactions t
       JOIN products p ON t.product_id = p.product_id
       JOIN users    u ON t.seller_id  = u.user_id
  LEFT JOIN reviews  r ON r.transaction_id = t.transaction_id
  LEFT JOIN disputes d ON d.transaction_id = t.transaction_id
      WHERE t.buyer_id = ?
      ORDER BY t.created_at DESC
      LIMIT 20'
);
$stmt->execute([$user['user_id']]);
$orders = $stmt->fetchAll();

// First page of the explore grid. This has to agree with the controls around
// it: the sort dropdown defaults to 'terbaharu' and loadProducts() asks for 12
// per page. It used to be the top 6 by trust score, so the first paint showed a
// different list than the same page a second later, and count($featured) was
// printed as though it were the catalogue total ("6 produk tersedia" while 19
// exist). The real total comes from its own query and drives the label.
$PROD_PER_PAGE = 12;

$feat_count = $pdo->prepare(
    "SELECT COUNT(*)
       FROM products p
       JOIN users u ON p.seller_id = u.user_id
      WHERE p.status = 'Aktif' AND p.stock > 0 AND u.is_verified = 1 AND u.is_active = 1
        AND p.seller_id <> ?"
);
$feat_count->execute([$user['user_id']]);
$featured_total = (int) $feat_count->fetchColumn();

$stmt = $pdo->prepare(
    'SELECT p.product_id, p.name, p.category, p.description,
            p.price, p.stock, p.image_path,
            u.full_name AS seller_name, u.is_verified,
            tp.score AS trust_baseline, tp.evidence_units AS trust_evidence,
            tp.ai_trust_score, tp.ai_adjustment, tp.fraud_risk, tp.ai_hold
       FROM products p
       JOIN users u ON p.seller_id = u.user_id
  LEFT JOIN trust_profiles tp ON tp.user_id = p.seller_id AND tp.profile_role = \'penjual\'
      WHERE p.status = \'Aktif\' AND p.stock > 0 AND u.is_verified = 1 AND u.is_active = 1
        AND p.seller_id <> ?
      ORDER BY p.created_at DESC, p.product_id DESC
      LIMIT ' . (int) $PROD_PER_PAGE
);
$stmt->execute([$user['user_id']]);
$featured = $stmt->fetchAll();
foreach ($featured as &$frow) {
    $fdisplay = trust_display_from_row($frow);
    $frow['trust_display']       = $fdisplay['label'];
    $frow['trust_display_color'] = $fdisplay['color'];
    $frow['trust_has_score']     = $fdisplay['has_score'];
    $frow['seller_hold']         = ai_trust_hold_state($frow['fraud_risk'], $frow['ai_hold'] ?? null);
}
unset($frow);

// Helper: star string
function stars(int $n): string {
    return str_repeat('★', $n) . str_repeat('☆', 5 - $n);
}

// Helper: status badge class
function badge_class(string $status): string {
    return match($status) {
        'Menunggu'   => 'badge-gold',
        'Disahkan'   => 'badge-blue',
        'Selesai'    => 'badge-green',
        'Dibatalkan' => 'badge-red',
        default      => 'badge-gray',
    };
}

// Helper: trust score colour
function trust_color(float $score): string {
    if ($score >= 85) return 'var(--green)';
    if ($score >= 70) return 'var(--gold)';
    return 'var(--red)';
}
?>
<!DOCTYPE html>
<html lang="ms">
<head>
  <meta charset="UTF-8">
  <?= csrf_meta_tag() ?>
  <?= csrf_script() ?>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TrustMart — Papan Pemuka Pembeli</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
  <style>
    /* Paste your full TrustMart CSS variables and base styles here.
       Below are only the pieces specific to this dashboard page.   */
    :root {
      --gold:#C9A84C; --gold-pale:#FAF6E8;
      --ink:#1A1714;  --ink3:#6B6358; --ink4:#A09589;
      --cream:#FBF8F2; --cream2:#F3EFE5; --cream3:#EAE4D6;
      --green:#1D6B4A; --green-bg:#EAF5EE;
      --red:#B03A2E;   --red-bg:#FAEAE8;
      --blue:#1A4F8A;  --blue-bg:#EAF0FA;
      --r:10px; --r-sm:6px;
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'DM Sans', sans-serif; background: var(--cream);
           color: var(--ink); font-size: 14px; }

    /* Layout */
    .shell    { display: flex; height: 100vh; overflow: hidden; }
    .sidebar  { width: 220px; background: var(--ink); flex-shrink: 0;
                display: flex; flex-direction: column; padding: 24px 0; }
    .main     { flex: 1; overflow-y: auto; }
    .topbar   { height: 60px; background: #fff; border-bottom: 1px solid var(--cream3);
                display: flex; align-items: center; padding: 0 28px; gap: 16px;
                position: sticky; top: 0; z-index: 10; }
    .content  { padding: 28px; }

    /* Sidebar */
    .logo-text          { font-family: 'DM Serif Display', serif; font-size: 22px;
                          color: #fff; padding: 0 20px 24px;
                          border-bottom: 1px solid rgba(255,255,255,0.08); }
    .logo-text span     { color: var(--gold); }
    .nav-section        { padding: 20px 12px 8px; font-size: 10px; letter-spacing: 1.5px;
                          text-transform: uppercase; color: rgba(255,255,255,0.3); }
    .nav-item           { display: flex; align-items: center; gap: 10px;
                          padding: 9px 20px; color: rgba(255,255,255,0.55);
                          cursor: pointer; font-size: 13px;
                          border-left: 2px solid transparent; transition: all .15s; }
    .nav-item:hover     { color: #fff; background: rgba(255,255,255,0.06); }
    .nav-item.active    { color: #fff; background: rgba(201,168,76,0.15);
                          border-left-color: var(--gold); }
    .sidebar-footer     { margin-top: auto; padding: 16px 20px;
                          border-top: 1px solid rgba(255,255,255,0.08); }
    .avatar             { width: 32px; height: 32px; border-radius: 50%;
                          background: var(--gold); display: flex;
                          align-items: center; justify-content: center;
                          font-size: 12px; font-weight: 600; color: var(--ink); }

    /* Cards and tables */
    .stats-grid  { display: grid; grid-template-columns: repeat(4,1fr); gap: 14px;
                   margin-bottom: 28px; }
    .stat-card   { background: #fff; border: 1px solid var(--cream3);
                   border-radius: var(--r); padding: 18px; position: relative;
                   overflow: hidden; }
    .stat-card::before { content:''; position: absolute; top:0; left:0; right:0; height:2px; }
    .stat-card.gold::before  { background: var(--gold); }
    .stat-card.green::before { background: var(--green); }
    .stat-card.blue::before  { background: var(--blue); }
    .stat-card.red::before   { background: var(--red); }
    .stat-label { font-size: 11px; color: var(--ink3); letter-spacing:.5px;
                  text-transform: uppercase; margin-bottom: 8px; }
    .stat-val   { font-size: 26px; font-weight: 600; line-height: 1; margin-bottom: 4px; }
    .stat-sub   { font-size: 11px; color: var(--ink4); }

    .tbl-wrap { background:#fff; border:1px solid var(--cream3);
                border-radius:var(--r); overflow:hidden; margin-bottom:20px; }
    .tbl      { width:100%; border-collapse:collapse; }
    .tbl th   { background:var(--cream2); text-align:left; padding:10px 16px;
                font-size:11px; font-weight:500; letter-spacing:.5px;
                text-transform:uppercase; color:var(--ink3);
                border-bottom:1px solid var(--cream3); }
    .tbl td   { padding:12px 16px; font-size:13px;
                border-bottom:1px solid var(--cream2); }
    .tbl tr:last-child td { border-bottom:none; }
    .tbl tr:hover td      { background:var(--cream); }

    .badge       { display:inline-flex; align-items:center; gap:4px;
                   padding:3px 8px; border-radius:20px;
                   font-size:11px; font-weight:500; }
    .badge-dot   { width:5px; height:5px; border-radius:50%; background:currentColor; }
    .badge-gold  { background:var(--gold-pale);  color:#7A5A10; }
    .badge-blue  { background:var(--blue-bg);    color:var(--blue); }
    .badge-green { background:var(--green-bg);   color:var(--green); }
    .badge-red   { background:var(--red-bg);     color:var(--red); }
    .badge-gray  { background:var(--cream2);     color:var(--ink3); }

    .btn         { display:inline-flex; align-items:center; gap:6px;
                   padding:7px 14px; border-radius:var(--r-sm);
                   border:1px solid var(--cream3); background:#fff;
                   color:var(--ink); font-size:12px; font-weight:500;
                   cursor:pointer; transition:.15s; font-family:inherit; }
    .btn:hover   { border-color:var(--gold); color:var(--gold); }
    .btn-primary { background:var(--gold); border-color:var(--gold); color:var(--ink); }
    .btn-danger  { background:var(--red-bg); border-color:var(--red); color:var(--red); }
    .btn-sm      { padding:5px 10px; font-size:11px; }
    .btn:disabled{ opacity:.5; cursor:not-allowed; }

    .section-hd    { display:flex; align-items:center; justify-content:space-between;
                     margin-bottom:16px; }
    .section-title { font-size:15px; font-weight:500; }

    .filters     { display:flex; gap:8px; margin-bottom:20px; flex-wrap:wrap; }
    .filter-btn  { padding:5px 12px; border-radius:20px; border:1px solid var(--cream3);
                   background:#fff; color:var(--ink3); font-size:12px; cursor:pointer; }
    .filter-btn.active { border-color:var(--gold); color:var(--ink);
                          background:var(--gold-pale); }

    .prod-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(180px,1fr));
                 gap:16px; }
    .prod-card { background:#fff; border:1px solid var(--cream3); border-radius:var(--r);
                 overflow:hidden; cursor:pointer; transition:.15s; }
    .prod-card:hover { border-color:var(--gold); }
    .prod-img  { height:120px; background:var(--cream2); display:flex;
                 align-items:center; justify-content:center; position:relative; }
    .prod-cat  { position:absolute; top:8px; left:8px; font-size:10px; padding:2px 7px;
                 border-radius:20px; background:rgba(255,255,255,0.9);
                 color:var(--ink3); font-weight:500; }
    .prod-body { padding:12px; }
    .prod-name { font-size:13px; font-weight:500; white-space:nowrap;
                 overflow:hidden; text-overflow:ellipsis; margin-bottom:4px; }
    .prod-price{ font-size:15px; font-weight:600; margin-bottom:8px; }

    .search-box { display:flex; align-items:center; gap:8px; background:var(--cream);
                  border:1px solid var(--cream3); border-radius:var(--r);
                  padding:0 12px; height:34px; flex:1; max-width:320px; }
    .search-box input { border:none; background:transparent; outline:none;
                        font-size:13px; font-family:inherit; width:100%; }

    .toast { position:fixed; bottom:24px; right:24px; background:var(--ink); color:#fff;
             padding:12px 18px; border-radius:var(--r); font-size:13px; z-index:200;
             transform:translateY(100px); opacity:0; transition:all .3s; max-width:320px; }
    .toast.show    { transform:translateY(0); opacity:1; }
    .toast.success { background:var(--green); }
    .toast.error   { background:var(--red); }

    /* Modal */
    .modal-bg   { position:fixed; inset:0; background:rgba(26,23,20,0.5);
                  z-index:100; display:flex; align-items:center; justify-content:center; }
    .modal      { background:#fff; border-radius:16px; padding:28px;
                  width:480px; max-width:90vw; }
    .modal-title{ font-family:'DM Serif Display',serif; font-size:20px; margin-bottom:4px; }
    .modal-sub  { font-size:12px; color:var(--ink3); margin-bottom:20px; }
    .modal-footer{ display:flex; gap:8px; justify-content:flex-end;
                   margin-top:20px; padding-top:16px;
                   border-top:1px solid var(--cream2); }

    .star-row   { display:flex; gap:6px; justify-content:center; margin:16px 0; }
    .star       { font-size:28px; cursor:pointer; color:var(--cream3); transition:.1s; }
    .star.on    { color:var(--gold); }

    .empty-state { text-align:center; padding:40px; color:var(--ink3); }
    textarea.inp { width:100%; padding:10px 14px; border:1px solid var(--cream3);
                   border-radius:var(--r-sm); font-size:13px; font-family:inherit;
                   outline:none; resize:vertical; min-height:80px; }
    textarea.inp:focus { border-color:var(--gold); }

    /* Pagination */
    .pagination-bar { display:flex; align-items:center; justify-content:space-between;
                      margin-top:20px; padding-top:14px; border-top:1px solid var(--cream3);
                      font-size:12px; color:var(--ink3); flex-wrap:wrap; gap:12px; }
    .pagination-controls { display:flex; align-items:center; gap:6px; }
    .page-btn { min-width:30px; height:30px; padding:0 8px; display:inline-flex;
                align-items:center; justify-content:center; border-radius:var(--r-sm);
                border:1px solid var(--cream3); background:#fff; color:var(--ink);
                font-size:12px; font-weight:500; cursor:pointer; transition:.15s;
                font-family:inherit; }
    .page-btn:hover:not(:disabled) { border-color:var(--gold); color:var(--gold); }
    .page-btn.active { background:var(--gold); border-color:var(--gold); color:var(--ink); font-weight:600; }
    .page-btn:disabled { opacity:0.4; cursor:not-allowed; }

    @media (max-width: 768px) {
      .sidebar   { display:none; }
      .stats-grid{ grid-template-columns:1fr 1fr; }
      .prod-grid { grid-template-columns:1fr 1fr; }
    }
  </style>
  <meta name="theme-color" content="#171b26">
  <link rel="stylesheet" href="../public/assets/css/trustmart-ui.css">
  <script src="../public/assets/js/trustmart-ui.js" defer></script>
</head>
<body>
<div class="shell">

  <!-- SIDEBAR -->
  <aside class="sidebar">
    <div class="logo-text">Trust<span>Mart</span></div>

    <div class="nav-section"><?= $is_seller_account ? 'Mod Pembeli' : 'Pembeli' ?></div>
    <div class="nav-item active" onclick="showTab('explore')">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
           viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/>
        <path d="m21 21-4.35-4.35"/></svg>
      Jelajah Produk
    </div>
    <div class="nav-item" onclick="showTab('pesanan')">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
           viewBox="0 0 24 24">
        <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
        <line x1="3" y1="6" x2="21" y2="6"/>
        <path d="M16 10a4 4 0 0 1-8 0"/>
      </svg>
      Pesanan Saya
      <?= notify_count_chip_html($notify['action'], 'notif-nav-count') ?>
    </div>
    <div class="nav-item" id="nav-chat" onclick="showTab('chat')">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
           viewBox="0 0 24 24">
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
      </svg>
      Sembang
      <span id="chat-badge-global" class="badge-dot" style="display:none;background:var(--red);width:8px;height:8px;border-radius:50%;margin-left:auto;"></span>
    </div>
    <a class="nav-item" href="profile.php<?= $is_seller_account ? '?mode=pembeli' : '' ?>" style="text-decoration:none">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
           viewBox="0 0 24 24">
        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
        <circle cx="12" cy="7" r="4"/>
      </svg>Profil Saya
    </a>

    <?php if ($is_seller_account): ?>
    <div class="nav-section">Kedai Saya</div>
    <a class="nav-item" href="seller.php" style="text-decoration:none">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
           viewBox="0 0 24 24">
        <path d="m2 7 4.41-4.41A2 2 0 0 1 7.83 2h8.34a2 2 0 0 1 1.42.59L22 7"/>
        <path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/>
        <path d="M15 22v-4a2 2 0 0 0-2-2h-2a2 2 0 0 0-2 2v4"/>
        <path d="M2 7h20"/>
      </svg>Tukar ke Mod Penjual
    </a>
    <?php endif ?>

    <div class="sidebar-footer">
      <div style="display:flex;align-items:center;gap:10px">
        <div class="avatar"><?= htmlspecialchars(mb_substr($user['full_name'],0,1)) ?></div>
        <div>
          <div style="font-size:12px;color:rgba(255,255,255,0.8)">
            <?= htmlspecialchars($user['full_name']) ?>
          </div>
          <div style="font-size:10px;color:rgba(255,255,255,0.35)">
            <?= $is_seller_account ? 'Penjual (Mod Pembeli)' : 'Pembeli' ?><?= ($user['user_type'] ?? '') === 'staf' ? ' · Staf' : '' ?> · <?= htmlspecialchars($user['ident_no']) ?>
          </div>
        </div>
      </div>
      <div style="margin-top:12px">
        <a href="../auth/logout.php"
           style="display:flex;align-items:center;gap:8px;padding:7px 0;
                  font-size:12px;color:rgba(255,255,255,0.4);text-decoration:none">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"
               viewBox="0 0 24 24">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
            <polyline points="16 17 21 12 16 7"/>
            <line x1="21" y1="12" x2="9" y2="12"/>
          </svg>
          Log Keluar
        </a>
      </div>
    </div>
  </aside>

  <!-- MAIN -->
  <div class="main">

    <!-- TOPBAR -->
    <!-- .console-topbar: the shared stylesheet lets this bar grow to two rows on
         narrow screens instead of clipping the notification badge and the score. -->
    <div class="topbar console-topbar">
      <div class="search-box">
        <svg width="14" height="14" fill="none" stroke="var(--ink4)" stroke-width="2"
             viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/>
          <path d="m21 21-4.35-4.35"/></svg>
        <input type="text" id="search-input"
               placeholder="Cari produk, penjual..."
               oninput="searchProducts(this.value)">
      </div>
      <div style="margin-left:auto;display:flex;align-items:center;gap:12px;font-size:12px;color:var(--ink3)">
        <?= notify_topbar_html($notify, 'notif', 'pesanan') ?>
        <button type="button" onclick="openBuyerTrustModal()"
                style="cursor:pointer;display:flex;align-items:center;gap:4px;background:var(--cream2);padding:4px 8px;border:0;border-radius:20px;font:inherit;font-weight:500;color:inherit">
          <?= $is_seller_account ? 'Skor Pembeli' : 'Skor Pintar' ?>:
          <span style="color:<?= trust_score_color($buyer_trust) ?>"><?= htmlspecialchars(trust_score_display($buyer_trust)) ?></span>
          <span class="badge <?= trust_confidence_class($buyer_trust) ?>" style="font-size:9px;padding:1px 6px">Keyakinan <?= htmlspecialchars($buyer_trust['confidence_label']) ?></span>
          <span style="font-size:10px;opacity:0.7" aria-hidden="true">ⓘ</span>
        </button>
        <?php if ($is_seller_account): ?>
        <a href="seller.php" title="Skor anda sebagai penjual"
           style="display:flex;align-items:center;gap:4px;background:var(--cream2);padding:4px 8px;border-radius:20px;font-weight:500;color:inherit;text-decoration:none">
          Skor Penjual: <span style="color:<?= trust_score_color($seller_mode_trust) ?>"><?= htmlspecialchars(trust_score_display($seller_mode_trust)) ?></span>
        </a>
        <?php endif ?>
        <span><?= $is_seller_account ? 'Penjual (Mod Pembeli)' : 'Pembeli' ?><?= ($user['user_type'] ?? '') === 'staf' ? ' · Staf' : '' ?> · <?= htmlspecialchars($user['ident_no']) ?></span>
      </div>
    </div>

    <div class="content">

      <?php if ($is_seller_account): ?>
      <div role="note" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:20px;
                  padding:12px 16px;background:var(--gold-pale);border:1px solid var(--cream3);
                  border-radius:var(--r);font-size:13px;color:var(--ink2)">
        <span>Anda sedang membeli menggunakan akaun penjual. Pembelian anda dikira dalam Skor Pembeli dan tidak menjejaskan Skor Penjual. Produk anda sendiri tidak dipaparkan di sini.</span>
        <a href="seller.php" class="btn btn-sm" style="margin-left:auto;text-decoration:none">Kembali ke Mod Penjual</a>
      </div>
      <?php endif ?>

      <!-- ── STATS ─────────────────────────────────────────── -->
      <div class="stats-grid">
        <div class="stat-card gold">
          <div class="stat-label">Pesanan Aktif</div>
          <div class="stat-val"><?= $active_orders ?></div>
          <div class="stat-sub">Menunggu + disahkan</div>
        </div>
        <div class="stat-card green">
          <div class="stat-label">Selesai</div>
          <div class="stat-val"><?= $counts['Selesai'] ?></div>
          <div class="stat-sub">Jumlah pembelian</div>
        </div>
        <div class="stat-card blue">
          <div class="stat-label">Menunggu</div>
          <div class="stat-val"><?= $counts['Menunggu'] ?></div>
          <div class="stat-sub">Perlu pengesahan penjual</div>
        </div>
        <div class="stat-card red">
          <div class="stat-label">Dibatalkan</div>
          <div class="stat-val"><?= $counts['Dibatalkan'] ?></div>
          <div class="stat-sub">Jumlah dibatalkan</div>
        </div>
      </div>

      <!-- ── TAB: EXPLORE ──────────────────────────────────── -->
      <div id="tab-explore">
        <div class="section-hd" style="margin-bottom:20px">
          <div>
            <div style="font-family:'DM Serif Display',serif;font-size:20px">
              Jelajah Produk
            </div>
            <div style="font-size:12px;color:var(--ink3);margin-top:2px"
                 id="prod-count">
              <?= $featured_total ?> produk tersedia
            </div>
          </div>
        </div>

        <div class="filters" id="cat-filters">
          <div class="filter-btn active" onclick="filterCat(this,'')">Semua</div>
          <?php foreach(['Elektronik','Buku','Pakaian','Makanan','Perkhidmatan','Lain-lain'] as $cat): ?>
          <div class="filter-btn" onclick="filterCat(this,'<?= $cat ?>')"><?= $cat ?></div>
          <?php endforeach; ?>
          <select onchange="sortChange(this.value)"
                  style="margin-left:auto;padding:5px 12px;border:1px solid var(--cream3);
                         border-radius:20px;font-size:12px;background:#fff;
                         color:var(--ink3);cursor:pointer;font-family:inherit">
            <option value="terbaharu">Terbaharu</option>
            <option value="harga-asc">Harga ↑</option>
            <option value="harga-desc">Harga ↓</option>
            <option value="trust-desc">Skor Tertinggi</option>
          </select>
        </div>

        <div class="prod-grid" id="prod-grid">
          <?php foreach ($featured as $p): ?>
          <div class="prod-card" onclick="openProduct(<?= $p['product_id'] ?>)">
            <div class="prod-img">
              <div class="prod-cat"><?= htmlspecialchars($p['category']) ?></div>
              <?php if ($p['image_path']): ?>
                <img src="../uploads/<?= htmlspecialchars($p['image_path']) ?>"
                     alt="" style="width:100%;height:100%;object-fit:cover">
              <?php else:
                // Category icon instead of a faint "broken image" outline.
                $cat_icon = ['Elektronik'=>'💻','Buku'=>'📚','Pakaian'=>'👕',
                             'Makanan'=>'🍔','Perkhidmatan'=>'🛠️'][$p['category']] ?? '📦'; ?>
                <div style="width:100%;height:100%;display:flex;align-items:center;
                            justify-content:center;background:var(--cream2);font-size:42px"
                     aria-hidden="true"><?= $cat_icon ?></div>
              <?php endif ?>
            </div>
            <div class="prod-body">
              <div class="prod-name"><?= htmlspecialchars($p['name']) ?></div>
              <div class="prod-price">
                RM <?= number_format($p['price'], 2) ?>
              </div>
              <div style="display:flex;align-items:center;gap:6px;font-size:11px;
                          color:var(--ink3)">
                <?php
                // One number per card, unified with the product page and chat
                // header: "Baharu" until the seller has real evidence, then the
                // AI-adjusted score (worker) or the Bayesian baseline. The AI
                // badge only appears when the layer actually diverged.
                ?>
                <span><?= htmlspecialchars($p['seller_name']) ?></span>
                <?= ai_trust_badge_html([
                    'ai_trust_score' => $p['ai_trust_score'],
                    'ai_adjustment'  => $p['ai_adjustment'] ?? 0,
                    'fraud_risk'     => (float) $p['fraud_risk'],
                ]) ?>
                <?php if (!empty($p['seller_hold'])): ?>
                <span title="Penjual ini sedang dalam siasatan PTU (Lapisan AI) — pesanan baharu dihalang sementara"
                      style="font-size:10px;font-weight:700;color:#fff;background:var(--red);padding:2px 6px;border-radius:8px;white-space:nowrap">
                  🛡 Disiasat PTU
                </span>
                <?php endif ?>
                <span style="margin-left:auto;font-weight:500;
                             color:<?= $p['trust_display_color'] ?>">
                  Trust Score: <?= htmlspecialchars($p['trust_display']) ?>
                </span>
              </div>
            </div>
          </div>
          <?php endforeach ?>
        </div>
        <div id="products-pagination"></div>
      </div>

      <!-- ── TAB: PESANAN SAYA ─────────────────────────────── -->
      <div id="tab-pesanan" style="display:none">
        <div style="font-family:'DM Serif Display',serif;font-size:20px;
                    margin-bottom:6px">Pesanan Saya</div>
        <div style="font-size:12px;color:var(--ink3);margin-bottom:20px">
          <?= $active_orders ?> pesanan aktif · <?= $counts['Selesai'] ?> selesai
        </div>

        <div class="filters" id="order-filters">
          <?php foreach(['Semua','Menunggu','Disahkan','Selesai','Dibatalkan'] as $s): ?>
          <div class="filter-btn <?= $s==='Semua'?'active':'' ?>"
               onclick="filterOrders(this,'<?= $s ?>')"><?= $s ?></div>
          <?php endforeach ?>
        </div>

        <div id="orders-list">
          <?php if (empty($orders)): ?>
          <div class="empty-state">
            <div style="font-size:40px;opacity:.3;margin-bottom:12px">📦</div>
            <div style="font-size:15px;font-weight:500;margin-bottom:6px">
              Tiada pesanan lagi
            </div>
            <div>Mulakan pembelian anda sekarang</div>
          </div>
          <?php else: ?>
          <?php foreach ($orders as $o): ?>
          <div class="order-row" data-status="<?= $o['status'] ?>"
               style="background:#fff;border:1px solid var(--cream3);
                      border-radius:var(--r);padding:16px;margin-bottom:10px;
                      display:flex;align-items:center;gap:14px">
            <div style="width:48px;height:48px;border-radius:var(--r-sm);
                        background:var(--cream2);flex-shrink:0;display:flex;
                        align-items:center;justify-content:center;font-size:20px">
              <?= match($o['category']) {
                'Elektronik'   => '💻',
                'Buku'         => '📚',
                'Pakaian'      => '👕',
                'Makanan'      => '🍱',
                'Perkhidmatan' => '🛠️',
                default        => '📦',
              } ?>
            </div>
            <div style="flex:1;min-width:0">
              <div style="font-size:13px;font-weight:500;
                          white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                <?= htmlspecialchars($o['product_name']) ?>
              </div>
              <div style="font-size:11px;color:var(--ink3);margin-top:2px">
                Penjual: <?= htmlspecialchars($o['seller_name']) ?>
                · #<?= $o['transaction_id'] ?>
                · <?= date('d M Y', strtotime($o['created_at'])) ?>
              </div>
              <div style="margin-top:6px;display:flex;align-items:center;gap:6px">
                <span class="badge <?= badge_class($o['status']) ?>">
                  <span class="badge-dot"></span><?= $o['status'] ?>
                </span>
                <?php if ($o['payment_type'] === 'online'): ?>
                  <?php if ($o['payment_status'] === 'paid'): ?>
                    <span class="badge badge-green" style="font-size:10px">
                      ✓ Dibayar
                    </span>
                  <?php elseif ($o['payment_status'] === 'unpaid'): ?>
                    <span class="badge badge-red" style="font-size:10px">
                      Belum Bayar
                    </span>
                  <?php elseif ($o['payment_status'] === 'pending'): ?>
                    <span class="badge badge-gold" style="font-size:10px">
                      ⏳ Menunggu
                    </span>
                  <?php endif ?>
                <?php endif ?>
                <?php if ($o['rating']): ?>
                <span style="font-size:12px;color:var(--gold)">
                  <?= stars((int)$o['rating']) ?>
                </span>
                <?php endif ?>
                <?php if (!empty($o['dispute_id'])): ?>
                <span class="badge <?= $o['dispute_status'] === 'Selesai' ? 'badge-green' : ($o['dispute_status'] === 'Dalam Semakan' ? 'badge-blue' : 'badge-gold') ?>">
                  <span class="badge-dot"></span>Aduan: <?= htmlspecialchars($o['dispute_status']) ?>
                </span>
                <?php endif ?>
              </div>
            </div>
            <div style="text-align:right;flex-shrink:0">
              <div style="font-size:14px;font-weight:600">
                RM <?= number_format($o['total_amount'], 2) ?>
              </div>
              <div style="display:flex;flex-direction:column;gap:6px;
                          margin-top:10px;align-items:flex-end">
                <?php if ($o['status'] === 'Menunggu' && $o['payment_type'] === 'online'
                          && in_array($o['payment_status'], ['unpaid', 'pending', 'failed'], true)): ?>
                  <?php
                    // #8 abandoned-payment recovery:
                    //   unpaid  = first payment
                    //   pending = resume the still-open gateway bill
                    //   failed  = retry after a declined / timed-out attempt
                    $pay_label = [
                        'unpaid'  => '💳 Bayar Sekarang',
                        'pending' => '▶️ Teruskan Pembayaran',
                        'failed'  => '🔁 Cuba Semula Pembayaran',
                    ][$o['payment_status']];
                  ?>
                  <a href="../payment/pay.php?txn=<?= $o['transaction_id'] ?>" class="btn btn-primary btn-sm" style="text-decoration:none">
                    <?= $pay_label ?>
                  </a>
                  <button class="btn btn-danger btn-sm"
                    onclick="cancelOrder(<?= $o['transaction_id'] ?>)">
                    Batalkan
                  </button>
                  <a href="../receipt.php?txn=<?= $o['transaction_id'] ?>" class="btn btn-sm" style="text-decoration:none" target="_blank">📄 Lihat Resit</a>
                <?php elseif ($o['status'] === 'Disahkan'): ?>
                  <button class="btn btn-primary btn-sm"
                    onclick="confirmReceived(<?= $o['transaction_id'] ?>)">
                    Selesai Terima
                  </button>
                  <button class="btn btn-danger btn-sm"
                    onclick="cancelOrder(<?= $o['transaction_id'] ?>)">
                    Batalkan
                  </button>
                  <?php if (empty($o['dispute_id'])): ?>
                    <button class="btn btn-sm" style="border-color:var(--red);color:var(--red);"
                      onclick="openDisputeModal(<?= $o['transaction_id'] ?>)">
                      ⚠️ Laporkan Masalah
                    </button>
                  <?php endif ?>
                <?php elseif ($o['status'] === 'Menunggu'): ?>
                  <button class="btn btn-danger btn-sm"
                    onclick="cancelOrder(<?= $o['transaction_id'] ?>)">
                    Batalkan
                  </button>
                  <a href="../receipt.php?txn=<?= $o['transaction_id'] ?>" class="btn btn-sm" style="text-decoration:none" target="_blank">📄 Lihat Resit</a>
                <?php elseif ($o['status'] === 'Selesai'): ?>
                  <a href="../receipt.php?txn=<?= $o['transaction_id'] ?>" 
                     class="btn btn-sm" style="text-decoration:none" target="_blank">
                    📄 Lihat Resit
                  </a>
                  <?php if (!$o['review_id']): ?>
                    <button type="button" class="btn btn-sm"
                      data-transaction-id="<?= (int)$o['transaction_id'] ?>"
                      data-product="<?= htmlspecialchars($o['product_name'], ENT_QUOTES, 'UTF-8') ?>"
                      data-seller="<?= htmlspecialchars($o['seller_name'], ENT_QUOTES, 'UTF-8') ?>"
                      onclick="openReviewModal(this)">
                      Beri Ulasan
                    </button>
                  <?php else: ?>
                    <span style="font-size:11px;color:var(--ink4)">Ulasan diberikan</span>
                  <?php endif ?>
                  <?php if (empty($o['dispute_id'])): ?>
                    <button class="btn btn-sm" style="border-color:var(--red);color:var(--red);"
                      onclick="openDisputeModal(<?= $o['transaction_id'] ?>)">
                      ⚠️ Laporkan Masalah
                    </button>
                  <?php endif ?>
                <?php endif ?>
              </div>
            </div>
          </div>
          <?php endforeach ?>
          <?php endif ?>
        </div>
        <div id="orders-pagination"></div>
      </div>

      <!-- ── TAB: SEMBANG / CHAT ───────────────────────────── -->
      <div id="tab-chat" style="display:none">
        <div class="section-hd" style="margin-bottom:20px">
          <div>
            <div style="font-family:'DM Serif Display',serif;font-size:20px">Sembang</div>
            <div style="font-size:12px;color:var(--ink3);margin-top:2px">Hubungi penjual untuk bertanya mengenai produk.</div>
          </div>
        </div>

        <!-- Chat Container Layout -->
        <div style="background:#fff; border:1px solid var(--cream3); border-radius:var(--r); display:grid; grid-template-columns:220px 1fr; height:500px; overflow:hidden;">
          <!-- Left Panel: Contacts List -->
          <div style="border-right:1px solid var(--cream3); display:flex; flex-direction:column; background:var(--cream);">
            <div style="padding:12px; font-weight:600; border-bottom:1px solid var(--cream3); font-size:12px; color:var(--ink2);">Senarai Sembang</div>
            <div id="chat-contacts-list" style="flex:1; overflow-y:auto;">
              <!-- Contacts will be loaded dynamically -->
            </div>
          </div>

          <!-- Right Panel: Conversation Box -->
          <div id="chat-box-panel" style="display:flex; flex-direction:column; height:100%; position:relative;">
            <!-- Header (Info Recipient) -->
            <div id="chat-header" style="padding:14px 20px; border-bottom:1px solid var(--cream3); display:flex; align-items:center; gap:10px; background:#fff;">
              <div style="font-weight:600; font-size:13px;" id="chat-header-name">Pilih Sembang</div>
              <div id="chat-header-trust" style="font-size:11px; font-weight:500; background:var(--cream2); padding:3px 10px; border-radius:20px; display:none;"></div>
            </div>

            <!-- Messages Stream -->
            <div id="chat-messages-stream" style="flex:1; padding:20px; overflow-y:auto; background:var(--cream); display:flex; flex-direction:column; gap:10px;">
              <div class="empty-state" style="margin:auto;">
                <div style="font-size:40px; opacity:0.3; margin-bottom:10px;">💬</div>
                <div style="font-weight:500;">Sila pilih kenalan jualan untuk memulakan sembang</div>
              </div>
            </div>

            <!-- Input area -->
            <div id="chat-input-area" style="padding:14px 20px; border-top:1px solid var(--cream3); background:#fff; display:none; align-items:center; gap:10px;">
              <input type="text" id="chat-message-input" class="form-input" placeholder="Tulis mesej anda di sini..." style="flex:1;" onkeydown="if(event.key==='Enter')sendChatMessage()">
              <button class="btn btn-primary" onclick="sendChatMessage()">Hantar</button>
            </div>
          </div>
        </div>
      </div>

    </div><!-- end .content -->
  </div><!-- end .main -->
</div><!-- end .shell -->

<!-- REVIEW MODAL -->
<div id="review-modal" class="modal-bg" style="display:none">
  <div class="modal">
    <div class="modal-title">Beri Ulasan</div>
    <div class="modal-sub" id="review-modal-sub"></div>
    <div class="star-row" id="star-row">
      <?php for($i=1;$i<=5;$i++): ?>
      <span class="star" data-val="<?=$i?>" onclick="setStars(<?=$i?>)">★</span>
      <?php endfor ?>
    </div>
    <div style="text-align:center;font-size:12px;color:var(--ink3);
                margin-bottom:16px" id="star-label">Pilih rating</div>
    <textarea class="inp" id="review-comment"
              placeholder="Kongsi pengalaman anda (pilihan)..."></textarea>
    <div class="modal-footer">
      <button class="btn" onclick="closeModal()">Lewat</button>
      <button class="btn btn-primary" id="review-submit-btn"
              disabled title="Sila pilih sekurang-kurangnya 1 bintang dahulu"
              onclick="submitReview()">Hantar Ulasan</button>
    </div>
  </div>
</div>

<!-- DISPUTE MODAL -->
<div id="dispute-modal" class="modal-bg" style="display:none">
  <div class="modal">
    <div class="modal-title">Laporkan Masalah / Failkan Aduan</div>
    <div class="modal-sub">Pesanan #<span id="dispute-txn-id-label"></span></div>
    
    <input type="hidden" id="dispute-txn-id">
    
    <div class="form-group" style="margin-bottom:14px">
      <label class="form-label" style="display:block;font-size:12px;font-weight:500;margin-bottom:6px">Tajuk Aduan</label>
      <input type="text" id="dispute-title" class="form-input" placeholder="Contoh: Barang tidak diterima / rosak" style="width:100%">
    </div>

    <div class="form-group" style="margin-bottom:14px">
      <label class="form-label" style="display:block;font-size:12px;font-weight:500;margin-bottom:6px">Tahap Keseriusan</label>
      <select id="dispute-severity" class="form-input" style="width:100%;height:38px;padding:8px 12px">
        <option value="Rendah">Rendah (Kelewatan kecil, dll.)</option>
        <option value="Sederhana" selected>Sederhana (Barang berbeza, dll.)</option>
        <option value="Mendesak">Mendesak (Barang rosak teruk / tidak dihantar, dll.)</option>
      </select>
    </div>

    <div class="form-group" style="margin-bottom:14px">
      <label class="form-label" style="display:block;font-size:12px;font-weight:500;margin-bottom:6px">Butiran Aduan</label>
      <textarea class="inp" id="dispute-description" placeholder="Jelaskan masalah anda secara terperinci..."></textarea>
    </div>

    <div class="modal-footer">
      <button class="btn" onclick="closeDisputeModal()">Batal</button>
      <button class="btn btn-primary" id="dispute-submit-btn" onclick="submitDispute()">Hantar Aduan</button>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<script>
// ── Passed from PHP into JS safely ───────────────────────────
const PRODUCTS_INIT = <?= json_encode($featured) ?>;

// ── Reusable Pagination Component ─────────────────────────────
function renderPagination(containerId, currentPage, totalPages, totalCount, onPageChange, itemName = 'rekod') {
  const container = document.getElementById(containerId);
  if (!container) return;
  if (totalPages <= 1 && totalCount <= 0) {
    container.innerHTML = '';
    return;
  }

  let html = `<div class="pagination-bar">`;
  html += `<div>Menunjukkan halaman <b>${currentPage}</b> daripada <b>${totalPages || 1}</b> (${totalCount} ${itemName})</div>`;

  if (totalPages > 1) {
    html += `<div class="pagination-controls">`;
    html += `<button class="page-btn" ${currentPage <= 1 ? 'disabled' : ''} onclick="${onPageChange}(${currentPage - 1})">‹ Sebelumnya</button>`;

    let startPage = Math.max(1, currentPage - 2);
    let endPage   = Math.min(totalPages, currentPage + 2);

    if (startPage > 1) {
      html += `<button class="page-btn" onclick="${onPageChange}(1)">1</button>`;
      if (startPage > 2) html += `<span style="padding:0 4px">…</span>`;
    }

    for (let p = startPage; p <= endPage; p++) {
      html += `<button class="page-btn ${p === currentPage ? 'active' : ''}" onclick="${onPageChange}(${p})">${p}</button>`;
    }

    if (endPage < totalPages) {
      if (endPage < totalPages - 1) html += `<span style="padding:0 4px">…</span>`;
      html += `<button class="page-btn" onclick="${onPageChange}(${totalPages})">${totalPages}</button>`;
    }

    html += `<button class="page-btn" ${currentPage >= totalPages ? 'disabled' : ''} onclick="${onPageChange}(${currentPage + 1})">Seterusnya ›</button>`;
    html += `</div>`;
  }

  html += `</div>`;
  container.innerHTML = html;
}

// ── Tab switching ─────────────────────────────────────────────
function showTab(name) {
  document.getElementById('tab-explore').style.display  = name==='explore' ? 'block' : 'none';
  document.getElementById('tab-pesanan').style.display  = name==='pesanan' ? 'block' : 'none';
  document.getElementById('tab-chat').style.display     = name==='chat'    ? 'block' : 'none';
  document.querySelectorAll('.nav-item').forEach(el => {
    el.classList.toggle('active',
      el.getAttribute('onclick') === `showTab('${name}')` || el.id === `nav-${name}`);
  });
  if (name === 'chat') {
    loadChatContacts();
    startChatPolling();
  } else {
    stopChatPolling();
  }
  if (name === 'pesanan') {
    loadBuyerOrders(currentOrderStatus, currentOrderPage);
  }
}

// ── Order filter & pagination ─────────────────────────────────
let currentOrderPage   = 1;
let currentOrderStatus = '';

function filterOrders(el, status) {
  document.querySelectorAll('#order-filters .filter-btn')
    .forEach(b => b.classList.remove('active'));
  el.classList.add('active');
  currentOrderStatus = status === 'Semua' ? '' : status;
  currentOrderPage = 1;
  loadBuyerOrders(currentOrderStatus, 1);
}

function goToBuyerOrderPage(page) {
  currentOrderPage = page;
  loadBuyerOrders(currentOrderStatus, page);
}

async function loadBuyerOrders(status = currentOrderStatus, page = currentOrderPage) {
  const listEl = document.getElementById('orders-list');
  listEl.innerHTML = '<div class="empty-state"><div style="font-size:14px">Memuatkan pesanan...</div></div>';

  try {
    const params = new URLSearchParams({
      action: 'get_orders', status, page, per_page: 10
    });
    const res = await fetch(`?${params}`);
    const data = await res.json();
    if (!data.success) return;

    renderOrdersList(data.orders);
    renderPagination(
      'orders-pagination',
      data.pagination.page,
      data.pagination.total_pages,
      data.pagination.total,
      'goToBuyerOrderPage',
      'pesanan'
    );
  } catch (e) {
    listEl.innerHTML = '<div class="empty-state">Ralat memuatkan pesanan.</div>';
  }
}

function renderOrdersList(orders) {
  const listEl = document.getElementById('orders-list');
  if (!orders.length) {
    listEl.innerHTML = `
      <div class="empty-state">
        <div style="font-size:40px;opacity:.3;margin-bottom:12px">📦</div>
        <div style="font-size:15px;font-weight:500;margin-bottom:6px">Tiada pesanan dijumpai</div>
      </div>`;
    return;
  }

  const categoryIcons = {
    'Elektronik': '💻', 'Buku': '📚', 'Pakaian': '👕',
    'Makanan': '🍱', 'Perkhidmatan': '🛠️'
  };

  listEl.innerHTML = orders.map(o => {
    const icon = categoryIcons[o.category] || '📦';
    const badgeCls = {
      'Menunggu': 'badge-gold', 'Disahkan': 'badge-blue',
      'Selesai': 'badge-green', 'Dibatalkan': 'badge-red'
    }[o.status] || 'badge-gray';

    let buttons = '';
    if (o.status === 'Menunggu' && o.payment_type === 'online' && o.payment_status === 'unpaid') {
      buttons = `
        <a href="../payment/pay.php?txn=${o.transaction_id}" class="btn btn-primary btn-sm" style="text-decoration:none">💳 Bayar Sekarang</a>
        <button class="btn btn-danger btn-sm" onclick="cancelOrder(${o.transaction_id})">Batalkan</button>
        <a href="../receipt.php?txn=${o.transaction_id}" class="btn btn-sm" style="text-decoration:none" target="_blank">📄 Lihat Resit</a>
      `;
    } else if (o.status === 'Disahkan') {
      buttons = `
        <button class="btn btn-primary btn-sm" onclick="confirmReceived(${o.transaction_id})">Selesai Terima</button>
        <button class="btn btn-danger btn-sm" onclick="cancelOrder(${o.transaction_id})">Batalkan</button>
        ${!o.dispute_id ? `<button class="btn btn-sm" style="border-color:var(--red);color:var(--red);" onclick="openDisputeModal(${o.transaction_id})">⚠️ Laporkan Masalah</button>` : ''}
      `;
    } else if (o.status === 'Menunggu') {
      buttons = `<button class="btn btn-danger btn-sm" onclick="cancelOrder(${o.transaction_id})">Batalkan</button>
        <a href="../receipt.php?txn=${o.transaction_id}" class="btn btn-sm" style="text-decoration:none" target="_blank">📄 Lihat Resit</a>`;
    } else if (o.status === 'Selesai') {
      const productAttr = escapeHtml(o.product_name);
      const sellerAttr = escapeHtml(o.seller_name);
      buttons = `
        <a href="../receipt.php?txn=${o.transaction_id}" class="btn btn-sm" style="text-decoration:none" target="_blank">📄 Lihat Resit</a>
        ${!o.review_id ? `<button type="button" class="btn btn-sm"
          data-transaction-id="${parseInt(o.transaction_id)}"
          data-product="${productAttr}"
          data-seller="${sellerAttr}"
          onclick="openReviewModal(this)">Beri Ulasan</button>` : `<span style="font-size:11px;color:var(--ink4)">Ulasan diberikan</span>`}
        ${!o.dispute_id ? `<button class="btn btn-sm" style="border-color:var(--red);color:var(--red);" onclick="openDisputeModal(${o.transaction_id})">⚠️ Laporkan Masalah</button>` : ''}
      `;
    }

    const starsHtml = o.rating ? `<span style="font-size:12px;color:var(--gold);margin-left:4px">${'★'.repeat(o.rating)}${'☆'.repeat(5 - o.rating)}</span>` : '';
    const disputeHtml = o.dispute_id ? `<span class="badge ${o.dispute_status === 'Selesai' ? 'badge-green' : (o.dispute_status === 'Dalam Semakan' ? 'badge-blue' : 'badge-gold')}"><span class="badge-dot"></span>Aduan: ${escapeHtml(o.dispute_status)}</span>` : '';
    
    // Payment status badge for online orders
    let paymentBadge = '';
    if (o.payment_type === 'online') {
      if (o.payment_status === 'paid') {
        paymentBadge = '<span class="badge badge-green" style="font-size:10px">✓ Dibayar</span>';
      } else if (o.payment_status === 'unpaid') {
        paymentBadge = '<span class="badge badge-red" style="font-size:10px">Belum Bayar</span>';
      } else if (o.payment_status === 'pending') {
        paymentBadge = '<span class="badge badge-gold" style="font-size:10px">⏳ Menunggu</span>';
      }
    }

    return `
      <div class="order-row" data-status="${o.status}"
           style="background:#fff;border:1px solid var(--cream3);
                  border-radius:var(--r);padding:16px;margin-bottom:10px;
                  display:flex;align-items:center;gap:14px">
        <div style="width:48px;height:48px;border-radius:var(--r-sm);
                    background:var(--cream2);flex-shrink:0;display:flex;
                    align-items:center;justify-content:center;font-size:20px">
          ${icon}
        </div>
        <div style="flex:1;min-width:0">
          <div style="font-size:13px;font-weight:500;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
            ${escapeHtml(o.product_name)}
          </div>
          <div style="font-size:11px;color:var(--ink3);margin-top:2px">
            Penjual: ${escapeHtml(o.seller_name)} · #${o.transaction_id} · ${new Date(o.created_at).toLocaleDateString('ms-MY', {day:'2-digit',month:'short',year:'numeric'})}
          </div>
          <div style="margin-top:6px;display:flex;align-items:center;gap:6px">
            <span class="badge ${badgeCls}"><span class="badge-dot"></span>${o.status}</span>
            ${paymentBadge}
            ${starsHtml}
            ${disputeHtml}
          </div>
        </div>
        <div style="text-align:right;flex-shrink:0">
          <div style="font-size:14px;font-weight:600">RM ${parseFloat(o.total_amount).toFixed(2)}</div>
          <div style="display:flex;flex-direction:column;gap:6px;margin-top:10px;align-items:flex-end">
            ${buttons}
          </div>
        </div>
      </div>
    `;
  }).join('');
}

// ── Product category filter & pagination ──────────────────────
let currentCat         = '';
let currentSort        = 'terbaharu';
let currentProductPage = 1;
let searchTimer        = null;

function filterCat(el, cat) {
  document.querySelectorAll('#cat-filters .filter-btn')
    .forEach(b => b.classList.remove('active'));
  el.classList.add('active');
  currentCat = cat;
  currentProductPage = 1;
  loadProducts();
}

function sortChange(val) {
  currentSort = val;
  currentProductPage = 1;
  loadProducts();
}

function searchProducts(q) {
  clearTimeout(searchTimer);
  searchTimer = setTimeout(() => {
    currentProductPage = 1;
    loadProducts(q, 1);
  }, 350);
}

function goToProductPage(page) {
  currentProductPage = page;
  loadProducts(document.getElementById('search-input').value, page);
}

async function loadProducts(q = document.getElementById('search-input').value, page = currentProductPage) {
  const params = new URLSearchParams({
    action: 'get_products', q, category: currentCat, sort: currentSort, page, per_page: 12
  });
  const res  = await fetch(`?${params}`);
  const data = await res.json();
  if (!data.success) return;

  document.getElementById('prod-count').textContent =
    `${data.pagination ? data.pagination.total : data.products.length} produk tersedia`;
  renderProductGrid(data.products);
  if (data.pagination) {
    renderPagination(
      'products-pagination',
      data.pagination.page,
      data.pagination.total_pages,
      data.pagination.total,
      'goToProductPage',
      'produk'
    );
  }
}

function renderProductGrid(products) {
  const grid = document.getElementById('prod-grid');
  // Category icons for image-less products (mirrors the PHP card fallback).
  const CAT_ICON = {Elektronik:'💻', Buku:'📚', Pakaian:'👕', Makanan:'🍔', Perkhidmatan:'🛠️'};
  if (!products.length) {
    grid.innerHTML = '<div class="empty-state" style="grid-column:1/-1">' +
      '<div style="font-size:40px;opacity:.3;margin-bottom:12px">🔍</div>' +
      '<div style="font-size:15px;font-weight:500">Tiada produk dijumpai</div></div>';
    return;
  }
  grid.innerHTML = products.map(p => `
    <div class="prod-card" onclick="openProduct(${p.product_id})">
      <div class="prod-img">
        <div class="prod-cat">${escapeHtml(p.category)}</div>
        ${p.image_path
          ? `<img src="../uploads/${escapeHtml(p.image_path)}" alt=""
                  style="width:100%;height:100%;object-fit:cover">`
          : `<div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:var(--cream2);font-size:42px" aria-hidden="true">${CAT_ICON[p.category] || '📦'}</div>`}
      </div>
      <div class="prod-body">
        <div class="prod-name">${escapeHtml(p.name)}</div>
        <div class="prod-price">RM ${parseFloat(p.price).toFixed(2)}</div>
        <div style="display:flex;align-items:center;gap:6px;font-size:11px;
                    color:var(--ink3)">
          <span>${escapeHtml(p.seller_name)}</span>
          ${aiBadge(p)}
          <span style="margin-left:auto;font-weight:500;
                       color:${p.trust_display_color || 'var(--ink3)'}">Trust Score: ${escapeHtml(p.trust_display ?? '')}</span>
        </div>
      </div>
    </div>`).join('');
}

// Baseline numeric score for a row (AI-adjusted once the worker has run,
// otherwise the Bayesian profile score). The visible card label comes from
// the server via trust_display so it stays consistent with product.php/chat.
function effectiveScore(p) {
  return (p.ai_trust_score === null || p.ai_trust_score === undefined)
    ? parseFloat(p.trust_baseline) : parseFloat(p.ai_trust_score);
}

// AI Trust Layer badge for catalogue cards. Hidden while the AI agrees with
// the baseline (no penalty, low risk) — it would just repeat the Trust Score.
// Only a real divergence (penalty delta / flagged risk) earns a badge.
// A hold badge is shown whenever the SERVER says the seller is held (audit #2);
// the flag comes from ai_trust_hold_state() in PHP so this never re-implements
// the threshold and cannot disagree with the checkout gate.
function aiBadge(p) {
  const hold = p.seller_hold
    ? `<span title="Penjual ini sedang dalam siasatan PTU (Lapisan AI) — pesanan baharu dihalang sementara" style="font-size:10px;font-weight:700;color:#fff;background:var(--red);padding:2px 6px;border-radius:8px;white-space:nowrap">🛡 Disiasat PTU</span>`
    : '';
  if (p.ai_trust_score === null || p.ai_trust_score === undefined) return hold;
  const adj = parseFloat(p.ai_adjustment || 0);
  const risk = parseFloat(p.fraud_risk || 0);
  if (adj >= 0 && risk < 25) return hold;
  const color = risk >= 50 ? 'var(--red)' : 'var(--gold)';
  const label = adj < 0 ? '\u2212' + Math.abs(adj).toFixed(0) : '\u26a0';
  const title = adj < 0
    ? `Lapisan AI mengesan isyarat risiko \u2014 skor dilaras ${adj.toFixed(0)} daripada skor asas.`
    : 'Lapisan AI mengesan risiko penipuan yang perlu dipantau.';
  return `${hold}<span title="${title}" style="font-size:10px;font-weight:600;color:${color}">🛡 AI ${label}</span>`;
}

function trustColor(s) {
  return s >= 85 ? 'var(--green)' : s >= 70 ? 'var(--gold)' : 'var(--red)';
}

function openProduct(id) {
  window.location.href = `../product.php?id=${id}`;
}

// ── Order actions ─────────────────────────────────────────────
async function postAction(action, body) {
  const res  = await fetch(`?action=${action}`, {
    method:  'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body:    new URLSearchParams(body),
  });
  return res.json();
}

async function cancelOrder(txn_id) {
  if (!confirm('Batalkan pesanan ini?')) return;
  const data = await postAction('cancel_order', { transaction_id: txn_id });
  showToast(data.message, data.success ? 'success' : 'error');
  if (data.success) setTimeout(() => location.reload(), 1000);
}

async function confirmReceived(txn_id) {
  if (!confirm('Sahkan anda sudah terima produk ini?')) return;
  const data = await postAction('confirm_received', { transaction_id: txn_id });
  showToast(data.message, data.success ? 'success' : 'error');
  if (data.success) setTimeout(() => location.reload(), 800);
}

// ── Review modal ──────────────────────────────────────────────
let reviewTxnId = null;
let reviewStars = 0;

function openReviewModal(trigger) {
  const txn_id = parseInt(trigger?.dataset?.transactionId || '0', 10);
  const product = trigger?.dataset?.product || '';
  const seller = trigger?.dataset?.seller || '';
  if (!txn_id) {
    showToast('Maklumat pesanan tidak sah.', 'error');
    return;
  }
  reviewTxnId = txn_id;
  reviewStars = 0;
  document.getElementById('review-modal-sub').textContent =
    `${product} · Penjual: ${seller}`;
  document.querySelectorAll('.star').forEach(s => s.classList.remove('on'));
  document.getElementById('star-label').textContent = 'Klik bintang untuk menilai';
  document.getElementById('review-comment').value = '';
  document.getElementById('review-submit-btn').disabled = true;
  document.getElementById('review-modal').style.display = 'flex';
}

function closeModal() {
  document.getElementById('review-modal').style.display = 'none';
}

function setStars(n) {
  reviewStars = n;
  const labels = ['','Sangat Buruk','Buruk','Sederhana','Bagus','Sangat Bagus!'];
  document.getElementById('star-label').textContent = labels[n];
  document.querySelectorAll('.star').forEach(s => {
    s.classList.toggle('on', parseInt(s.dataset.val) <= n);
  });
  document.getElementById('review-submit-btn').disabled = false;
}

async function submitReview() {
  const btn = document.getElementById('review-submit-btn');
  btn.disabled = true;
  btn.textContent = 'Menghantar...';
  const data = await postAction('submit_review', {
    transaction_id: reviewTxnId,
    rating:         reviewStars,
    comment:        document.getElementById('review-comment').value,
  });
  showToast(data.message, data.success ? 'success' : 'error');
  if (data.success) {
    closeModal();
    setTimeout(() => location.reload(), 1000);
  } else {
    btn.disabled = false;
    btn.textContent = 'Hantar Ulasan';
  }
}

// ── Dispute modal ─────────────────────────────────────────────
function openDisputeModal(txn_id) {
  document.getElementById('dispute-txn-id').value = txn_id;
  document.getElementById('dispute-txn-id-label').textContent = txn_id;
  document.getElementById('dispute-title').value = '';
  document.getElementById('dispute-description').value = '';
  document.getElementById('dispute-severity').value = 'Sederhana';
  document.getElementById('dispute-submit-btn').disabled = false;
  document.getElementById('dispute-submit-btn').textContent = 'Hantar Aduan';
  document.getElementById('dispute-modal').style.display = 'flex';
}

function closeDisputeModal() {
  document.getElementById('dispute-modal').style.display = 'none';
}

async function submitDispute() {
  const txnId = document.getElementById('dispute-txn-id').value;
  const title = document.getElementById('dispute-title').value.trim();
  const desc  = document.getElementById('dispute-description').value.trim();
  const sev   = document.getElementById('dispute-severity').value;

  if (title === '' || desc === '') {
    showToast('Sila isi semua medan aduan.', 'error');
    return;
  }

  const btn = document.getElementById('dispute-submit-btn');
  btn.disabled = true;
  btn.textContent = 'Menghantar...';

  try {
    const data = await postAction('submit_dispute', {
      transaction_id: txnId,
      title: title,
      description: desc,
      severity: sev
    });

    showToast(data.message, data.success ? 'success' : 'error');
    if (data.success) {
      closeDisputeModal();
      setTimeout(() => location.reload(), 1000);
    } else {
      btn.disabled = false;
      btn.textContent = 'Hantar Aduan';
    }
  } catch (e) {
    showToast('Ralat rangkaian. Sila cuba lagi.', 'error');
    btn.disabled = false;
    btn.textContent = 'Hantar Aduan';
  }
}

// ── Toast ─────────────────────────────────────────────────────
function showToast(msg, type = '') {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className = `toast show ${type}`;
  setTimeout(() => t.classList.remove('show'), 3200);
}

// Close modal on backdrop click
document.getElementById('review-modal').addEventListener('click', function(e) {
  if (e.target === this) closeModal();
});

// ── Chat System Frontend ─────────────────────────────────────
window.activeContactId = null;
window.chatPollInterval = null;
window.activeProductId = null; // Store product_id if contact seller was clicked

function escapeHtml(str) {
  if (!str) return '';
  return str.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

async function loadChatContacts(selectedContactId = window.activeContactId, preloaded = null) {
  try {
    // The poller already holds the contact payload from the combined `sync`
    // call, so it passes it through instead of issuing a second request.
    const data = preloaded ? { success: true, contacts: preloaded }
                           : await (await fetch('../chat_api.php?action=get_contacts')).json();
    if (!data.success) return;

    const listEl = document.getElementById('chat-contacts-list');
    
    // Check if we have an active parameter for a seller who isn't in contacts list yet
    const params = new URLSearchParams(window.location.search);
    const sellerId = parseInt(params.get('seller_id'));
    const sellerName = params.get('seller_name');
    const productId = parseInt(params.get('product_id'));
    
    let contactsList = data.contacts;
    if (sellerId && !contactsList.some(c => parseInt(c.user_id, 10) === sellerId)) {
      // Brand-new chat partner: resolve their REAL trust score and
      // verification status from the server instead of placeholder values.
      // Profile data is static, so cache it — without this the poll re-fetched
      // get_contact_info every 4 seconds for the whole life of the session.
      let realContact = null;
      if (window._deepLinkContact && window._deepLinkContact.user_id == sellerId) {
        realContact = window._deepLinkContact;
      } else {
        try {
          const infoRes = await fetch(`../chat_api.php?action=get_contact_info&user_id=${sellerId}`);
          const infoData = await infoRes.json();
          if (infoData.success && infoData.contact) {
            realContact = infoData.contact;
            window._deepLinkContact = realContact;
          }
        } catch (_) { /* fall back to the URL-supplied name below */ }
      }
      contactsList.unshift(realContact ? {
        ...realContact,
        unread_count: 0,
        last_message: 'Mula bersembang mengenai produk...',
        last_message_time: new Date().toISOString()
      } : {
        user_id: sellerId,
        full_name: sellerName || 'Penjual',
        role: 'penjual',
        is_verified: 0,
        verification_status: null,
        trust_display: '',        // unknown = header badge hidden, never guessed
        trust_has_score: false,
        trust_display_color: 'var(--ink3)',
        unread_count: 0,
        last_message: 'Mula bersembang mengenai produk...',
        last_message_time: new Date().toISOString()
      });
      if (productId && !window.activeContactId) {
        window.activeProductId = productId;
      }
    }

    if (!contactsList.length) {
      listEl.innerHTML = '<div class="empty-state" style="padding: 20px; text-align: center; color: var(--ink3); font-size:12px;">Tiada sembang lagi.</div>';
      return;
    }

    // The poll rewrites this list every few seconds; without restoring focus,
    // a keyboard user gets thrown back to the top of the page mid-navigation.
    const focusedId = document.activeElement &&
      document.activeElement.closest('#chat-contacts-list')
      ? document.activeElement.closest('[data-contact-id]')?.dataset.contactId : null;

    listEl.innerHTML = contactsList.map(c => {
      const isSelected = c.user_id === selectedContactId;
      const unreadBadge = c.unread_count > 0 ? `<span style="background:var(--red); color:#fff; font-size:10px; font-weight:600; padding:1px 6px; border-radius:10px; margin-left:auto;">${c.unread_count}</span>` : '';
      const nameInitial = c.full_name.charAt(0);
      const verifiedTag = c.verification_status === 'Disahkan' || c.is_verified == 1 ? '<span style="color:var(--gold); font-size:10px; margin-left:2px;" title="Disahkan PTU">✓</span>' : '';
      
      return `
        <button type="button"
             data-contact-id="${parseInt(c.user_id)}"
             data-contact-name="${escapeHtml(c.full_name)}"
             data-trust-display="${escapeHtml(c.trust_display || '')}"
             data-trust-color="${escapeHtml(c.trust_display_color || 'var(--ink3)')}"
             data-trust-has-score="${c.trust_has_score ? 1 : 0}"
             data-is-verified="${c.is_verified || (c.verification_status === 'Disahkan' ? 1 : 0)}"
             onclick="selectChatContactFromElement(this)"
             style="width:100%; padding:10px 14px; border:0; border-bottom:1px solid var(--cream3); cursor:pointer; display:flex; align-items:center; gap:8px; transition:0.15s; background:${isSelected ? '#fff' : 'transparent'}; font-family:inherit;">
          <div class="avatar" style="width:28px; height:28px; font-size:11px; flex-shrink:0;">${escapeHtml(nameInitial)}</div>
          <div style="flex:1; min-width:0; text-align:left;">
            <div style="font-weight:500; font-size:12px; display:flex; align-items:center; color:var(--ink); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
              ${escapeHtml(c.full_name)}${verifiedTag}
            </div>
            <div style="font-size:10px; color:var(--ink3); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
              ${escapeHtml(c.last_message || '—')}
            </div>
          </div>
          ${unreadBadge}
        </button>
      `;
    }).join('');

    if (focusedId) {
      const refocus = listEl.querySelector(`[data-contact-id="${focusedId}"]`);
      if (refocus) refocus.focus();
    }

    // If activeContactId is set but not selected in UI, auto select it
    if (window.activeContactId && !window.chatHeaderInitialized) {
      // user_id arrives from JSON as a string, so compare numerically.
      const activeC = contactsList.find(c => parseInt(c.user_id, 10) === parseInt(window.activeContactId, 10));
      if (activeC) {
        selectChatContact(activeC.user_id, activeC.full_name, activeC.trust_display,
          activeC.is_verified, activeC.trust_display_color, activeC.trust_has_score);
      }
    }
  } catch (e) {
    console.error('loadChatContacts error:', e);
  }
}

function selectChatContactFromElement(element) {
  selectChatContact(
    parseInt(element.dataset.contactId || '0', 10),
    element.dataset.contactName || '',
    element.dataset.trustDisplay || '',
    parseInt(element.dataset.isVerified || '0', 10),
    element.dataset.trustColor || 'var(--ink3)',
    element.dataset.trustHasScore === '1'
  );
}

function selectChatContact(contactId, fullName, trustDisplay, isVerified, trustColor = 'var(--ink3)', hasScore = true) {
  window.activeContactId = contactId;
  window.chatHeaderInitialized = true;
  
  // Update header UI
  document.getElementById('chat-header-name').innerHTML = escapeHtml(fullName) + 
    (isVerified == 1 ? ' <span style="background:var(--gold-pale); color:#7A5A10; padding:1px 7px; border-radius:10px; font-size:9px; font-weight:normal; margin-left:4px;">✓ Disahkan PTU</span>' : '');
  
  const headerTrust = document.getElementById('chat-header-trust');
  if (trustDisplay) {
    // trustDisplay + hasScore come from the same server-side trust_profiles
    // source as the product page, so a new seller reads "Baharu" here too.
    headerTrust.style.color = trustColor;
    headerTrust.style.background = 'var(--cream2)';
    headerTrust.textContent = `Skor Kepercayaan: ${trustDisplay}${hasScore ? '/100' : ''}`;
    headerTrust.style.display = 'block';
  } else {
    // Score unknown (contact could not be resolved) — show nothing rather than a fake value.
    headerTrust.style.display = 'none';
  }

  // Show input area
  document.getElementById('chat-input-area').style.display = 'flex';

  // Highlight selected contact in list
  loadChatContacts(contactId);
  
  // Load messages
  loadChatMessages(contactId);
}

async function loadChatMessages(contactId) {
  if (window.activeContactId !== contactId) return;
  try {
    const res = await fetch(`../chat_api.php?action=get_messages&contact_id=${contactId}`);
    const data = await res.json();
    if (!data.success) return;

    const streamEl = document.getElementById('chat-messages-stream');
    if (!data.messages.length) {
      streamEl.innerHTML = `
        <div style="margin:auto; text-align:center; color:var(--ink3); font-size:12px;">
          Mula bersembang mengenai produk. Hantarkan mesej pertama anda di bawah!
        </div>
      `;
      return;
    }

    const myId = <?= $buyer_id ?>;
    streamEl.innerHTML = data.messages.map(m => {
      const isMe = m.sender_id === myId;
      const align = isMe ? 'align-self: flex-end; background: var(--gold-pale); border-bottom-right-radius: 2px;' : 'align-self: flex-start; background: #fff; border-bottom-left-radius: 2px;';
      const timeStr = new Date(m.created_at).toLocaleTimeString('ms-MY', { hour: '2-digit', minute: '2-digit', hour12: false });
      
      const productCard = m.product_name ? `
        <a href="../product.php?id=${m.product_id}" style="text-decoration:none; display:block; background:var(--cream2); padding:8px; border-radius:6px; border:1px solid var(--cream3); margin-bottom:6px; font-size:11px; color:var(--ink); text-align:left;">
          📦 <b>${escapeHtml(m.product_name)}</b><br>
          <span style="font-weight:600;">RM ${parseFloat(m.product_price).toFixed(2)}</span>
        </a>
      ` : '';

      return `
        <div style="max-width: 70%; padding: 10px 14px; border-radius: 12px; font-size: 13px; color: var(--ink); ${align} box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
          ${productCard}
          <div style="white-space: pre-wrap; word-break: break-word; text-align:left;">${escapeHtml(m.message_text)}</div>
          <div style="font-size: 9px; color: var(--ink4); text-align: right; margin-top: 4px; line-height: 1;">${timeStr}</div>
        </div>
      `;
    }).join('');

    // Scroll to bottom
    streamEl.scrollTop = streamEl.scrollHeight;
  } catch (e) {
    console.error('loadChatMessages error:', e);
  }
}

async function sendChatMessage() {
  const inputEl = document.getElementById('chat-message-input');
  const text = inputEl.value.trim();
  if (text === '' || !window.activeContactId) return;

  const bodyData = {
    receiver_id: window.activeContactId,
    message_text: text
  };
  
  if (window.activeProductId) {
    bodyData.product_id = window.activeProductId;
    // Clear product context once first message is sent
    window.activeProductId = null;
    
    // Clear URL parameters to clean up address bar
    const cleanUrl = window.location.protocol + "//" + window.location.host + window.location.pathname + "?tab=chat";
    window.history.replaceState({ path: cleanUrl }, '', cleanUrl);
  }

  inputEl.value = '';
  
  try {
    const res = await fetch('../chat_api.php?action=send_message', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(bodyData)
    });
    const data = await res.json();
    if (data.success) {
      // The badge dot only shows when the chat tab is hidden, and sending a
      // message marks the open thread as read, so it can only be cleared here.
      renderUnreadBadge(0);
      loadChatMessages(window.activeContactId);
      loadChatContacts(window.activeContactId);
    } else {
      showToast(data.message, 'error');
    }
  } catch (e) {
    showToast('Gagal menghantar mesej.', 'error');
  }
}

// Badge is driven from the combined `sync` payload (data.count) rather than a
// dedicated request — the three-per-tick pattern used to multiply load on the
// chat endpoint for every logged-in user.
function renderUnreadBadge(count) {
  const badge = document.getElementById('chat-badge-global');
  if (!badge) return;
  badge.style.display = count > 0 ? 'inline-block' : 'none';
}

// Lightweight badge-only refresh, used on first paint and while the user is
// sitting on another tab. Cheaper than the full sync (one COUNT, no contact
// list or message window).
async function refreshChatBadge() {
  if (document.hidden) return;
  try {
    const res  = await fetch('../chat_api.php?action=get_unread_count');
    const data = await res.json();
    if (data.success) renderUnreadBadge(parseInt(data.count, 10) || 0);
  } catch (e) {
    console.error('refreshChatBadge error:', e);
  }
}

async function pollChatSync() {
  // Pause when the tab is hidden (browser throttles the timer anyway, and a
  // hidden tab should not cost a server worker) or when the chat tab is not
  // on screen. Skip if the previous round-trip is still in flight, otherwise a
  // slow shared host stacks requests on top of each other.
  if (document.hidden || window._chatSyncBusy) return;
  if (document.getElementById('tab-chat').style.display !== 'block') return;

  window._chatSyncBusy = true;
  try {
    const res  = await fetch('../chat_api.php?action=sync');
    const data = await res.json();
    if (!data.success) return;

    renderUnreadBadge(parseInt(data.count, 10) || 0);
    // Reuse the already-fetched contact payload instead of refetching it.
    loadChatContacts(window.activeContactId, data.contacts);
    if (window.activeContactId) loadChatMessages(window.activeContactId);
  } catch (e) {
    console.error('pollChatSync error:', e);
  } finally {
    window._chatSyncBusy = false;
  }
}

const CHAT_POLL_MS = 4000;

function startChatPolling() {
  if (window.chatPollInterval) clearInterval(window.chatPollInterval);
  window.chatPollInterval = setInterval(pollChatSync, CHAT_POLL_MS);

  // Coming back to a hidden tab should refresh at once rather than wait out
  // the interval.
  if (!window._chatVisibilityBound) {
    window._chatVisibilityBound = true;
    document.addEventListener('visibilitychange', () => {
      if (!document.hidden &&
          document.getElementById('tab-chat').style.display === 'block') {
        pollChatSync();
      }
    });
  }
}

function stopChatPolling() {
  if (window.chatPollInterval) {
    clearInterval(window.chatPollInterval);
    window.chatPollInterval = null;
  }
}

// Initial chat check
document.addEventListener('DOMContentLoaded', () => {
  refreshChatBadge();
<?php if ($flash_msg !== ''): ?>
  showToast(<?= json_encode($flash_msg, JSON_UNESCAPED_UNICODE) ?>, 'error');
<?php endif; ?>

  // The server paints only the first page. If the catalogue is larger than
  // that, let the AJAX path redraw the grid on load so the page controls
  // exist immediately — otherwise the header says "N produk tersedia" while
  // only the first page is reachable until the visitor touches a filter.
  if (document.querySelectorAll('#prod-grid .prod-card').length < <?= (int) $featured_total ?>) {
    loadProducts('', 1);
  }

  // Cheap badge refresh for users sitting on another tab.
  setInterval(() => {
    if (document.getElementById('tab-chat').style.display !== 'block') {
      refreshChatBadge();
    }
  }, 10000);

  const params = new URLSearchParams(window.location.search);
  const tab = params.get('tab');
  if (tab === 'chat') {
    const sellerId = parseInt(params.get('seller_id'));
    const sellerName = params.get('seller_name');
    const productId = parseInt(params.get('product_id'));
    const productName = params.get('product_name');
    
    showTab('chat');
    if (sellerId) {
      window.activeContactId = sellerId;
      window.activeProductId = productId;

      // The header is populated by loadChatContacts()'s auto-select once the
      // seller's REAL trust score / verification resolve via the server —
      // never hardcode placeholder values here.

      // Prefill input box with product query
      if (productName) {
        document.getElementById('chat-message-input').value = `Hai, saya berminat dengan produk "${productName}" anda. Adakah masih ada stok?`;
      }
    }
  }
});
</script>

<!-- BUYER TRUST MODAL -->
<div id="buyer-trust-modal" class="modal-bg" style="display:none">
  <div class="modal" style="width:600px">
    <div class="modal-title">Skor Kepercayaan Pintar Pembeli</div>
    <div class="modal-sub">Algoritma Bayesian yang boleh diterangkan · <?= htmlspecialchars($buyer_trust['formula_version']) ?></div>

    <div style="background:var(--cream);padding:16px;border-radius:var(--r);margin-bottom:16px;text-align:center">
      <div style="font-size:11px;color:var(--ink3);text-transform:uppercase;margin-bottom:4px">Skor berasaskan rekod urus niaga</div>
      <div style="font-size:36px;font-weight:600;color:<?= trust_score_color($buyer_trust) ?>">
        <?= htmlspecialchars(trust_score_display($buyer_trust, 1)) ?>
      </div>
      <div style="font-size:12px;color:var(--ink3);margin-top:5px">
        <?= htmlspecialchars(trust_summary_text($buyer_trust)) ?> · Kualiti rekod <?= number_format($buyer_trust['raw_score'],1) ?>/100
      </div>
      <?php if ($is_seller_account): ?>
      <div style="font-size:11px;color:var(--ink3);margin-top:4px">
        Profil pembeli ini berasingan daripada profil penjual anda.
      </div>
      <?php endif ?>
    </div>

    <div style="font-size:13px;line-height:1.6;color:var(--ink2)">
      <?= trust_explained_html($buyer_trust, 'penjual') ?>
      <?= trust_components_html($buyer_trust) ?>
      <?= ai_trust_detail_html(ai_trust_fetch($pdo, (int)$buyer_id, 'pembeli')) ?>
      <div style="background:var(--gold-pale);padding:12px;border-radius:var(--r);font-size:11px;color:var(--ink3)">
        <b>Adil kepada pembeli:</b> pembatalan oleh penjual tidak menurunkan skor
        pembeli. Hanya pembatalan anda sendiri dan aduan selesai yang diputuskan
        memihak penjual dikira. Hanya 3 urus niaga pertama dengan penjual yang
        sama dikira — lebih daripada itu tidak menaikkan skor anda.
      </div>
    </div>

    <div class="modal-footer">
      <button class="btn btn-primary" onclick="closeBuyerTrustModal()">Tutup</button>
    </div>
  </div>
</div>

<script>
function openBuyerTrustModal() {
  document.getElementById('buyer-trust-modal').style.display = 'flex';
}
function closeBuyerTrustModal() {
  document.getElementById('buyer-trust-modal').style.display = 'none';
}

// Close modal on backdrop click
document.getElementById('buyer-trust-modal').addEventListener('click', function(e) {
  if (e.target === this) closeBuyerTrustModal();
});
document.getElementById('dispute-modal').addEventListener('click', function(e) {
  if (e.target === this) closeDisputeModal();
});

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    closeBuyerTrustModal();
    closeModal();
    closeDisputeModal();
  }
});
</script>
</body>
</html>