<?php
// ============================================================
// dashboard/admin.php
// Dashboard for PTU admin (pentadbir).
// Handles AJAX sub-actions:
//   ?action=get_stats
//   ?action=get_sellers        (pending / all)
//   ?action=verify_seller      (approve / reject)
//   ?action=get_disputes
//   ?action=resolve_dispute
//   ?action=get_users
//   ?action=toggle_user_status
//   ?action=get_analytics
//   ?action=get_ai_risks        (AI Trust Layer review panel)
//   ?action=ai_set_hold         (release / re-apply AI order hold)
// ============================================================

require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/trust_score.php';
require_once __DIR__ . '/../includes/ai_trust.php';
refresh_session_user($pdo);

require_role('pentadbir');

$user = current_user();

// 
// AJAX ACTION ROUTER
// 
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_validate();

    switch ($_GET['action']) {

        // ── Seller list (pending or all with pagination) ─────
        case 'get_sellers':
            $filter   = trim($_GET['filter'] ?? 'pending');
            $page     = max(1, (int)($_GET['page'] ?? 1));
            $per_page = max(1, min(50, (int)($_GET['per_page'] ?? 15)));
            $offset   = ($page - 1) * $per_page;

            $params  = [];
            $where   = "WHERE u.role = 'penjual'";

            if ($filter === 'pending') {
                $where  .= " AND u.verification_status = 'Menunggu'";
            } elseif ($filter === 'verified') {
                $where  .= " AND u.verification_status = 'Disahkan'";
            } elseif ($filter === 'rejected') {
                $where  .= " AND u.verification_status = 'Ditolak'";
            }
            // 'all' → no extra clause

            // Count total sellers
            $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM users u {$where}");
            $count_stmt->execute($params);
            $total = (int)$count_stmt->fetchColumn();
            $total_pages = $total > 0 ? (int)ceil($total / $per_page) : 1;

            $stmt = $pdo->prepare(
                "SELECT u.user_id, u.full_name, u.matric_no, u.staff_no, u.user_type,
                        COALESCE(NULLIF(u.matric_no, ''), u.staff_no) AS ident_no,
                        u.email,
                        u.faculty, u.is_verified, u.verification_status, u.trust_score, u.created_at,
                        COUNT(DISTINCT p.product_id)      AS product_count,
                        COUNT(DISTINCT t.transaction_id)  AS order_count
                   FROM users u
              LEFT JOIN products     p ON p.seller_id   = u.user_id
                                      AND p.status      != 'Dipadam'
              LEFT JOIN transactions t ON t.seller_id   = u.user_id
                   {$where}
                   GROUP BY u.user_id
                   ORDER BY u.created_at DESC
                   LIMIT {$per_page} OFFSET {$offset}"
            );
            $stmt->execute($params);

            echo json_encode([
                'success'    => true,
                'sellers'    => $stmt->fetchAll(),
                'pagination' => [
                    'page'        => $page,
                    'per_page'    => $per_page,
                    'total'       => $total,
                    'total_pages' => $total_pages,
                ]
            ]);
            exit;

        //  Verify or reject a seller 
        case 'verify_seller':
            $target_id = (int)   ($_POST['user_id'] ?? 0);
            $action_v  = trim($_POST['action_type'] ?? ''); // 'approve' or 'reject'

            if (!$target_id || !in_array($action_v, ['approve','reject'], true)) {
                http_response_code(422);
                die(json_encode([
                    'success' => false,
                    'message' => 'Parameter tidak sah.',
                ]));
            }

            $is_verified = $action_v === 'approve' ? 1 : 0;
            $verification_status = $action_v === 'approve' ? 'Disahkan' : 'Ditolak';

            try {
                $pdo->beginTransaction();

                // Lock the seller while verification, listing visibility and
                // trust score are updated as one all-or-nothing operation.
                $stmt = $pdo->prepare(
                    "SELECT user_id, full_name, is_verified
                       FROM users
                      WHERE user_id = ? AND role = 'penjual'
                      FOR UPDATE"
                );
                $stmt->execute([$target_id]);
                $target = $stmt->fetch();

                if (!$target) {
                    $pdo->rollBack();
                    http_response_code(404);
                    die(json_encode([
                        'success' => false,
                        'message' => 'Penjual tidak dijumpai.',
                    ]));
                }

                $pdo->prepare(
                    'UPDATE users SET is_verified = ?, verification_status = ? WHERE user_id = ?'
                )->execute([$is_verified, $verification_status, $target_id]);

                // Revocation hides active listings without deleting their state
                // permanently. A re-approved seller can review and reactivate them.
                if ($action_v === 'reject') {
                    $pdo->prepare(
                        "UPDATE products SET status = 'Tidak Aktif'
                          WHERE seller_id = ? AND status = 'Aktif'"
                    )->execute([$target_id]);
                }

                $score_stmt = $pdo->prepare('CALL recalculate_trust_score(?)');
                $score_stmt->execute([$target_id]);
                $score_stmt->closeCursor();

                $pdo->commit();
                $msg = $action_v === 'approve'
                    ? "{$target['full_name']} telah diterima sebagai penjual PTU."
                    : "Pendaftaran penjual {$target['full_name']} telah ditolak dan produk aktif disembunyikan.";

                echo json_encode(['success' => true, 'message' => $msg]);
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[verify_seller] ' . $e->getMessage());
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => 'Status penjual tidak dapat dikemaskini. Sila cuba lagi.',
                ]);
            }
            exit;

        // ── Dispute list (with pagination) ───────────────────
        case 'get_disputes':
            $filter   = trim($_GET['filter'] ?? 'open');
            $page     = max(1, (int)($_GET['page'] ?? 1));
            $per_page = max(1, min(50, (int)($_GET['per_page'] ?? 10)));
            $offset   = ($page - 1) * $per_page;
            $where    = '';

            if ($filter === 'open') {
                $where = "AND d.status = 'Terbuka'";
            } elseif ($filter === 'review') {
                $where = "AND d.status = 'Dalam Semakan'";
            } elseif ($filter === 'closed') {
                $where = "AND d.status = 'Selesai'";
            }
            // 'all' → no clause

            // Count total disputes
            $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM disputes d WHERE 1=1 {$where}");
            $count_stmt->execute();
            $total = (int)$count_stmt->fetchColumn();
            $total_pages = $total > 0 ? (int)ceil($total / $per_page) : 1;

            $stmt = $pdo->prepare(
                "SELECT d.dispute_id, d.title, d.description,
                        d.severity, d.status, d.resolution,
                        d.created_at, d.resolved_at,
                        t.transaction_id, t.total_amount,
                        buyer.full_name  AS buyer_name,
                        -- A staff account has no matric number, so the identifier
                        -- shown for a person is whichever one that account has.
                        COALESCE(NULLIF(buyer.matric_no, ''), buyer.staff_no) AS buyer_ident,
                        seller.full_name AS seller_name,
                        COALESCE(NULLIF(seller.matric_no, ''), seller.staff_no) AS seller_ident,
                        p.name           AS product_name,
                        admin.full_name  AS resolved_by_name
                   FROM disputes d
                   JOIN transactions t  ON d.transaction_id = t.transaction_id
                   JOIN users buyer     ON t.buyer_id       = buyer.user_id
                   JOIN users seller    ON t.seller_id      = seller.user_id
                   JOIN products p      ON t.product_id     = p.product_id
              LEFT JOIN users admin     ON d.resolved_by    = admin.user_id
                  WHERE 1=1 {$where}
                  ORDER BY
                    FIELD(d.severity,'Mendesak','Sederhana','Rendah'),
                    d.created_at DESC
                  LIMIT {$per_page} OFFSET {$offset}"
            );
            $stmt->execute();

            echo json_encode([
                'success'    => true,
                'disputes'   => $stmt->fetchAll(),
                'pagination' => [
                    'page'        => $page,
                    'per_page'    => $per_page,
                    'total'       => $total,
                    'total_pages' => $total_pages,
                ]
            ]);
            exit;

        //  Resolve a dispute 
        case 'resolve_dispute':
            $dispute_id = (int) ($_POST['dispute_id'] ?? 0);
            $resolution = trim($_POST['resolution'] ?? '');
            $new_status = trim($_POST['status'] ?? 'Selesai');

            $allowed_res = ['Memihak Pembeli','Memihak Penjual','Neutral'];
            $allowed_sts = ['Dalam Semakan','Selesai'];

            if (!$dispute_id
                || !in_array($new_status, $allowed_sts, true)
                || ($new_status === 'Selesai'
                    && !in_array($resolution, $allowed_res, true))) {
                http_response_code(422);
                die(json_encode([
                    'success' => false,
                    'message' => 'Parameter tidak sah.',
                ]));
            }

            // An under-review dispute has no final decision and therefore must
            // not carry a resolution that can affect either party's trust score.
            if ($new_status === 'Dalam Semakan') {
                $resolution = null;
            }

            $settlement_note = null;

            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare(
                    'SELECT dispute_id, status
                       FROM disputes
                      WHERE dispute_id = ?
                      FOR UPDATE'
                );
                $stmt->execute([$dispute_id]);
                $dispute = $stmt->fetch();

                if (!$dispute) {
                    $pdo->rollBack();
                    http_response_code(404);
                    die(json_encode([
                        'success' => false,
                        'message' => 'Aduan tidak dijumpai.',
                    ]));
                }

                if ($dispute['status'] === 'Selesai') {
                    $pdo->rollBack();
                    http_response_code(422);
                    die(json_encode([
                        'success' => false,
                        'message' => 'Aduan ini sudah diselesaikan.',
                    ]));
                }

                $resolver_id = $new_status === 'Selesai'
                    ? $user['user_id']
                    : null;

                $pdo->prepare(
                    'UPDATE disputes
                        SET status      = ?,
                            resolution  = ?,
                            resolved_by = ?,
                            resolved_at = CASE
                                WHEN ? = \'Selesai\' THEN CURRENT_TIMESTAMP
                                ELSE NULL
                            END
                      WHERE dispute_id  = ?'
                )->execute([
                    $new_status,
                    $resolution,
                    $resolver_id,
                    $new_status,
                    $dispute_id,
                ]);

                // ── Buyer-favourable financial settlement (Issue #7) ──────────
                // "Memihak Pembeli" on a paid order must create an EXPLICIT,
                // persisted settlement decision (refund amount / return need /
                // fulfilment) and enqueue the money movement, instead of only
                // touching the dispute record. Idempotent via settlement_status,
                // and it NEVER auto-restocks (goods may already be delivered).
                if ($new_status === 'Selesai' && $resolution === 'Memihak Pembeli') {
                    $ord_stmt = $pdo->prepare(
                        'SELECT t.transaction_id, t.total_amount, t.status AS order_status,
                                t.payment_type, t.payment_status, d.settlement_status
                           FROM disputes d
                           JOIN transactions t ON t.transaction_id = d.transaction_id
                          WHERE d.dispute_id = ?
                            FOR UPDATE'
                    );
                    $ord_stmt->execute([$dispute_id]);
                    $order = $ord_stmt->fetch(PDO::FETCH_ASSOC);

                    if ($order && $order['settlement_status'] === 'none') {
                        // Refund amount: explicit value clamped to the order total,
                        // otherwise the full order total.
                        $ap = $_POST['settle_refund_amount'] ?? '';
                        $refund_amount = ($ap !== '' && is_numeric($ap))
                            ? max(0.0, min((float)$order['total_amount'], round((float)$ap, 2)))
                            : round((float)$order['total_amount'], 2);

                        // Return requirement: default YES for buyer-favourable cases.
                        $rr = (string)($_POST['settle_return_required'] ?? '1');
                        $return_required = ($rr === '1' || strtolower($rr) === 'ya') ? 1 : 0;

                        // Fulfilment decision: default keeps the order (no auto-void).
                        $fulfil_posted = $_POST['settle_fulfilment'] ?? 'Kekal Selesai';
                        $fulfilment = in_array($fulfil_posted, ['Kekal Selesai', 'Dibatalkan'], true)
                            ? $fulfil_posted : 'Kekal Selesai';

                        $notes = trim($_POST['settle_notes'] ?? '');

                        // Persist the settlement decision idempotently.
                        $pdo->prepare(
                            "UPDATE disputes
                                SET settlement_status          = 'queued',
                                    settlement_refund_amount   = ?,
                                    settlement_return_required = ?,
                                    settlement_fulfilment      = ?,
                                    settlement_notes           = ?,
                                    settled_by                 = ?,
                                    settled_at                 = CURRENT_TIMESTAMP
                              WHERE dispute_id = ? AND settlement_status = 'none'"
                        )->execute([
                            $refund_amount, $return_required, $fulfilment,
                            ($notes !== '' ? $notes : null), $user['user_id'], $dispute_id,
                        ]);

                        // Enqueue the money movement onto the linked COMPLETED
                        // payment so it surfaces in the admin refund queue. No
                        // auto-payout and no restock happen here.
                        $pay_stmt = $pdo->prepare(
                            "SELECT payment_id, refund_status FROM payments
                              WHERE transaction_id = ? AND status = 'completed'
                              ORDER BY payment_id DESC LIMIT 1
                              FOR UPDATE"
                        );
                        $pay_stmt->execute([$order['transaction_id']]);
                        $pay = $pay_stmt->fetch(PDO::FETCH_ASSOC);

                        if ($pay && $pay['refund_status'] === 'none') {
                            // FIX H3: Hold refund if return is required
                            $refund_status_to_set = $return_required ? 'pending_return' : 'requested';
                            
                            $pdo->prepare(
                                "UPDATE payments
                                    SET refund_status       = ?,
                                        refund_method       = 'admin',
                                        refund_requested_at = CURRENT_TIMESTAMP,
                                        refund_error        = NULL
                                  WHERE payment_id = ? AND refund_status = 'none'"
                            )->execute([$refund_status_to_set, $pay['payment_id']]);
                            
                            // Create return shipment record if return required
                            if ($return_required) {
                                $pdo->prepare(
                                    "INSERT INTO return_shipments 
                                        (dispute_id, transaction_id, status, notes, return_requested_at)
                                    VALUES (?, ?, 'pending', ?, CURRENT_TIMESTAMP)"
                                )->execute([
                                    $dispute_id,
                                    $order['transaction_id'],
                                    'Pulangan dikehendaki: ' . ($notes ?: 'Tiada nota tambahan')
                                ]);
                                
                                $settlement_note = 'Pulangan RM' . number_format($refund_amount, 2) . 
                                    ' DITAHAN sehingga barang dipulangkan dan disahkan. Return ID: ' . $pdo->lastInsertId();
                            } else {
                                $settlement_note = 'Pulangan RM' . number_format($refund_amount, 2) . 
                                    ' telah didaftarkan ke senarai pulangan untuk diproses (tiada keperluan pulangan barang).';
                            }
                        } elseif (in_array($order['payment_status'], ['paid', 'paid_cash'], true)) {
                            // Money changed hands but there is no completed gateway
                            // payment to refund: a cash order, or a legacy row.
                            // Testing only for 'paid' (audit #4) told a buyer who had
                            // paid CASH that the order was "belum dibayar" and queued
                            // nothing at all - 'paid' has always meant "the gateway
                            // confirmed it", which cash cannot claim. The decision
                            // stays queued in disputes.settlement_status and is worked
                            // from the manual list on the Pulangan tab.
                            $settlement_note = 'Tiada pembayaran gateway selesai - pulangan RM' . number_format($refund_amount, 2) . ' perlu diselesaikan secara manual oleh PTU (bukan bayaran dalam talian).';
                            error_log('[resolve_dispute] RECONCILE dispute=' . $dispute_id . ' txn=' . $order['transaction_id'] . ' payment=' . $order['payment_status'] . '/' . $order['payment_type'] . ' but no completed payment; manual settlement queued');
                        } else {
                            $settlement_note = 'Order belum dibayar; keputusan settlement dirakam tanpa pulangan.';
                        }

                        // Apply the fulfilment decision WITHOUT restocking goods.
                        // L2: the admin EXPLICITLY chose 'Dibatalkan' in the settlement
                        // form. Silently dropping that decision when the order is
                        // already 'Selesai' left a paid+delivered order showing
                        // "completed" alongside a refunded dispute. Honour the intent
                        // for all reachable states, still with NO stock restore
                        // (goods may already be delivered).
                        if ($fulfilment === 'Dibatalkan' && in_array($order['order_status'], ['Menunggu', 'Disahkan', 'Selesai'], true)) {
                            $pdo->prepare(
                                "UPDATE transactions
                                    SET status = 'Dibatalkan',
                                        cancelled_at = CURRENT_TIMESTAMP,
                                        cancelled_by = 'admin'
                                  WHERE transaction_id = ?"
                            )->execute([$order['transaction_id']]);
                            // Deliberately NO product restock: goods may already be delivered.
                            if ($order['order_status'] === 'Selesai') {
                                $settlement_note = ($settlement_note ? $settlement_note . ' ' : '')
                                    . 'Pesanan Selesai telah dibatalkan oleh PTU (tiada restock automatik).';
                            }
                        } elseif ($fulfilment === 'Dibatalkan') {
                            // Defensive branch: unknown order state. Record the intent
                            // in the settlement rather than drop it silently, so the
                            // admin sees the mismatch and can investigate.
                            $settlement_note = ($settlement_note ? $settlement_note . ' ' : '')
                                . 'Amaran: keputusan "Dibatalkan" tidak dapat diterapkan pada pesanan berstatus ' . $order['order_status'] . '.';
                            error_log('[resolve_dispute] fulfilment_dibatalkan_not_applicable dispute=' . $dispute_id . ' txn=' . $order['transaction_id'] . ' order_status=' . $order['order_status']);
                        }
                    }
                }

                // L8: A "Memihak Penjual" ruling rejects the buyer's claim, so the
                // transaction stands as fulfilled. Disputes can only be filed on
                // 'Disahkan'/'Selesai' orders (see buyer submit_dispute); when the
                // buyer used a dispute to avoid confirming a 'Disahkan' order and
                // the admin sides with the seller, the order was previously left
                // stranded in 'Disahkan' forever. Complete it instead. The
                // completed_by ENUM has no 'admin' value, so the completion is
                // attributed to 'system' (admin acting through the platform).
                if ($new_status === 'Selesai' && $resolution === 'Memihak Penjual') {
                    $sp = $pdo->prepare(
                        'SELECT t.transaction_id, t.status
                           FROM disputes d
                           JOIN transactions t ON t.transaction_id = d.transaction_id
                          WHERE d.dispute_id = ?
                            FOR UPDATE'
                    );
                    $sp->execute([$dispute_id]);
                    $so = $sp->fetch(PDO::FETCH_ASSOC);
                    if ($so && $so['status'] === 'Disahkan') {
                        $pdo->prepare(
                            "UPDATE transactions
                                SET status = 'Selesai',
                                    completed_at = CURRENT_TIMESTAMP,
                                    completed_by = 'system'
                              WHERE transaction_id = ?"
                        )->execute([$so['transaction_id']]);
                        $settlement_note = 'Aduan memihak penjual: pesanan disempurnakan (Disahkan ke Selesai).';
                    }
                }

                $pdo->commit();

                // Audit #3b: a ruling changes what the AI layer should be reporting
                // right now, but only the cron worker used to recompute it — so the
                // trust score kept showing the pre-ruling number. Refreshed after the
                // commit so a scoring failure can never undo a saved ruling.
                $aff_stmt = $pdo->prepare(
                    'SELECT t.buyer_id, t.seller_id
                       FROM disputes d
                       JOIN transactions t ON t.transaction_id = d.transaction_id
                      WHERE d.dispute_id = ?'
                );
                $aff_stmt->execute([$dispute_id]);
                if ($aff = $aff_stmt->fetch(PDO::FETCH_ASSOC)) {
                    ai_trust_refresh_after_write($pdo, (int) $aff['seller_id'], 'penjual');
                    ai_trust_refresh_after_write($pdo, (int) $aff['buyer_id'], 'pembeli');
                }

                echo json_encode([
                    'success' => true,
                    'message' => ($new_status === 'Selesai'
                        ? "Aduan diselesaikan: {$resolution}."
                        : 'Aduan dikemaskini ke Dalam Semakan.')
                        . ($settlement_note ? ' ' . $settlement_note : ''),
                ]);
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[resolve_dispute] ' . $e->getMessage());
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Ralat pelayan.']);
            }
            exit;

        // ── Full user list (with pagination) ─────────────────
        case 'get_users':
            $role     = trim($_GET['role']   ?? '');
            $search   = trim($_GET['search'] ?? '');
            $page     = max(1, (int)($_GET['page'] ?? 1));
            $per_page = max(1, min(50, (int)($_GET['per_page'] ?? 15)));
            $offset   = ($page - 1) * $per_page;

            $params = [];
            $where  = 'WHERE 1=1';

            $allowed_roles = ['pembeli','penjual','pentadbir'];
            if (in_array($role, $allowed_roles, true)) {
                $where   .= ' AND u.role = ?';
                $params[] = $role;
            }

            if ($search !== '') {
                // staff_no is searched too: an account type whose identifier
                // PTU cannot look up is an account type PTU cannot manage.
                $where   .= ' AND (u.full_name LIKE ? OR u.matric_no LIKE ? OR u.staff_no LIKE ?)';
                $params[] = "%{$search}%";
                $params[] = "%{$search}%";
                $params[] = "%{$search}%";
            }

            // Count total users
            $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM users u {$where}");
            $count_stmt->execute($params);
            $total = (int)$count_stmt->fetchColumn();
            $total_pages = $total > 0 ? (int)ceil($total / $per_page) : 1;

            $stmt = $pdo->prepare(
                "SELECT u.user_id, u.full_name, u.matric_no, u.staff_no, u.user_type,
                        COALESCE(NULLIF(u.matric_no, ''), u.staff_no) AS ident_no,
                        u.email,
                        u.role, u.faculty, u.is_verified, u.verification_status,
                        u.is_active, u.trust_score, u.created_at
                   FROM users u
                  {$where}
                  ORDER BY u.created_at DESC
                  LIMIT {$per_page} OFFSET {$offset}"
            );
            $stmt->execute($params);

            echo json_encode([
                'success'    => true,
                'users'      => $stmt->fetchAll(),
                'pagination' => [
                    'page'        => $page,
                    'per_page'    => $per_page,
                    'total'       => $total,
                    'total_pages' => $total_pages,
                ]
            ]);
            exit;

        // ── Transaction history (with filters and pagination) ──
        case 'get_transactions':
            $status_filter = trim($_GET['status'] ?? '');
            $payment_type = trim($_GET['payment_type'] ?? '');
            $date_from = trim($_GET['date_from'] ?? '');
            $date_to = trim($_GET['date_to'] ?? '');
            $search = trim($_GET['search'] ?? '');
            $page = max(1, (int)($_GET['page'] ?? 1));
            $per_page = max(1, min(100, (int)($_GET['per_page'] ?? 20)));
            $offset = ($page - 1) * $per_page;

            $params = [];
            $where = 'WHERE 1=1';

            $allowed_statuses = ['Menunggu','Disahkan','Selesai','Dibatalkan'];
            if (in_array($status_filter, $allowed_statuses, true)) {
                $where .= ' AND t.status = ?';
                $params[] = $status_filter;
            }

            if ($payment_type === 'cash' || $payment_type === 'online') {
                $where .= ' AND t.payment_type = ?';
                $params[] = $payment_type;
            }

            if ($date_from !== '') {
                $where .= ' AND DATE(t.created_at) >= ?';
                $params[] = $date_from;
            }

            if ($date_to !== '') {
                $where .= ' AND DATE(t.created_at) <= ?';
                $params[] = $date_to;
            }

            if ($search !== '') {
                $where .= ' AND (buyer.full_name LIKE ? OR seller.full_name LIKE ? OR p.name LIKE ?)';
                $params[] = "%{$search}%";
                $params[] = "%{$search}%";
                $params[] = "%{$search}%";
            }

            // Count total — must carry the same joins as the detail/summary
            // queries, because {$where} can reference the buyer/seller/p aliases
            // added by the transaction search. Without them a non-empty search
            // produced invalid SQL (unknown alias) and the request failed.
            $count_stmt = $pdo->prepare(
                "SELECT COUNT(*)
                   FROM transactions t
                   JOIN products p     ON t.product_id = p.product_id
                   JOIN users buyer    ON t.buyer_id   = buyer.user_id
                   JOIN users seller   ON t.seller_id  = seller.user_id
                  {$where}"
            );
            $count_stmt->execute($params);
            $total = (int)$count_stmt->fetchColumn();
            $total_pages = $total > 0 ? (int)ceil($total / $per_page) : 1;

            // Get transactions with details
            $stmt = $pdo->prepare(
                "SELECT t.transaction_id, t.status, t.total_amount,
                        t.payment_method, t.payment_type, t.payment_status,
                        t.created_at, t.confirmed_at, t.completed_at, t.cancelled_at,
                        p.name AS product_name, p.category,
                        buyer.full_name AS buyer_name,
                        COALESCE(NULLIF(buyer.matric_no, ''), buyer.staff_no) AS buyer_ident,
                        seller.full_name AS seller_name,
                        COALESCE(NULLIF(seller.matric_no, ''), seller.staff_no) AS seller_ident
                   FROM transactions t
                   JOIN products p ON t.product_id = p.product_id
                   JOIN users buyer ON t.buyer_id = buyer.user_id
                   JOIN users seller ON t.seller_id = seller.user_id
                  {$where}
                  ORDER BY t.created_at DESC
                  LIMIT {$per_page} OFFSET {$offset}"
            );
            $stmt->execute($params);
            $transactions = $stmt->fetchAll();

            // Calculate summary stats for current filter
            $summary_stmt = $pdo->prepare(
                "SELECT 
                    COUNT(*) as total_count,
                    SUM(t.total_amount) as total_amount,
                    SUM(CASE WHEN t.status = 'Selesai' THEN t.total_amount ELSE 0 END) as completed_amount,
                    SUM(CASE WHEN t.payment_type = 'online' AND t.payment_status = 'paid' THEN t.total_amount ELSE 0 END) as online_paid_amount
                 FROM transactions t
                 JOIN products p   ON t.product_id = p.product_id
                 JOIN users buyer  ON t.buyer_id   = buyer.user_id
                 JOIN users seller ON t.seller_id  = seller.user_id
                 {$where}"
            );
            $summary_stmt->execute($params);
            $summary = $summary_stmt->fetch();

            echo json_encode([
                'success' => true,
                'transactions' => $transactions,
                'summary' => $summary,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $per_page,
                    'total' => $total,
                    'total_pages' => $total_pages,
                ]
            ]);
            exit;

        // ── Refund management (get failed/pending refunds) ────
        case 'get_refunds':
            $status_filter = trim($_GET['status'] ?? '');
            $page = max(1, (int)($_GET['page'] ?? 1));
            $per_page = max(1, min(50, (int)($_GET['per_page'] ?? 20)));
            $offset = ($page - 1) * $per_page;

            $params = [];
            // Pulangan Wang owns the money side only. Payments parked in
            // 'pending_return' are physically waiting for the item to come
            // back and are handled exclusively on admin_returns.php
            // ("Pulangan Barang"). Once the return is verified or waived,
            // refund_status flips to 'requested' and the row naturally
            // appears here for retry / mark-completed.
            $where = "WHERE p.refund_status NOT IN ('none','pending_return')";

            $allowed_statuses = ['requested','processing','completed','failed'];
            if (in_array($status_filter, $allowed_statuses, true)) {
                $where .= ' AND p.refund_status = ?';
                $params[] = $status_filter;
            }

            // Count total
            $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM payments p {$where}");
            $count_stmt->execute($params);
            $total = (int)$count_stmt->fetchColumn();
            $total_pages = $total > 0 ? (int)ceil($total / $per_page) : 1;

            // Get refund records with transaction details
            $stmt = $pdo->prepare(
                "SELECT p.payment_id, p.payment_reference, p.payment_method,
                        p.amount, p.refund_amount, p.status as payment_status,
                        p.refund_status, p.refund_method, p.refund_attempts,
                        p.refund_requested_at, p.refund_completed_at,
                        p.refund_reference, p.refund_error,
                        t.transaction_id, t.status as transaction_status,
                        pr.name AS product_name,
                        buyer.full_name AS buyer_name, buyer.email AS buyer_email,
                        seller.full_name AS seller_name
                   FROM payments p
                   JOIN transactions t ON p.transaction_id = t.transaction_id
                   JOIN products pr ON t.product_id = pr.product_id
                   JOIN users buyer ON t.buyer_id = buyer.user_id
                   JOIN users seller ON t.seller_id = seller.user_id
                  {$where}
                  ORDER BY p.refund_requested_at DESC
                  LIMIT {$per_page} OFFSET {$offset}"
            );
            $stmt->execute($params);
            $refunds = $stmt->fetchAll();

            // ── Manual (non-gateway) settlements, audit #4 ─────────────────
            // A cash order never gets a payments row, so the queue above can
            // structurally not contain it. A buyer-favourable ruling on a cash
            // order therefore had no owner and no way to be closed. These rows
            // are kept OUT of the gateway list (different key, own table) so the
            // filtering/pagination above stays exactly as it was.
            $ms_stmt = $pdo->prepare(
                "SELECT d.dispute_id, d.transaction_id, d.settlement_status,
                        d.settlement_refund_amount, d.settlement_return_required,
                        d.settlement_notes, d.settled_at,
                        t.total_amount, t.payment_type, t.payment_status, t.status AS order_status,
                        pr.name AS product_name,
                        buyer.full_name AS buyer_name, buyer.email AS buyer_email,
                        seller.full_name AS seller_name
                   FROM disputes d
                   JOIN transactions t ON t.transaction_id = d.transaction_id
                   LEFT JOIN products pr ON pr.product_id = t.product_id
                   JOIN users buyer ON buyer.user_id = t.buyer_id
                   JOIN users seller ON seller.user_id = t.seller_id
                  WHERE d.settlement_status IN ('queued','completed')
                    AND NOT EXISTS (SELECT 1 FROM payments p
                                     WHERE p.transaction_id = d.transaction_id
                                       AND p.status = 'completed')
                  ORDER BY FIELD(d.settlement_status, 'queued', 'completed'), d.settled_at DESC
                  LIMIT 100"
            );
            $ms_stmt->execute();
            $manual_all = $ms_stmt->fetchAll(PDO::FETCH_ASSOC);

            // queued behaves like 'requested' to the filters; completed like
            // 'completed'; processing/failed are gateway-only states.
            $manual_settlements = array_values(array_filter($manual_all, function ($m) use ($status_filter) {
                if ($status_filter === '') return true;
                if ($status_filter === 'requested') return $m['settlement_status'] === 'queued';
                if ($status_filter === 'completed') return $m['settlement_status'] === 'completed';
                return false;
            }));

            // Summary stats — same scope as the visible list (Wang tab only;
            // pending_return lives on the Barang page).
            $summary_stmt = $pdo->prepare(
                "SELECT 
                    COUNT(*) as total_refunds,
                    SUM(CASE WHEN p.refund_status = 'failed' THEN 1 ELSE 0 END) as failed_count,
                    SUM(CASE WHEN p.refund_status = 'processing' THEN 1 ELSE 0 END) as processing_count,
                    SUM(CASE WHEN p.refund_status = 'completed' THEN 1 ELSE 0 END) as completed_count,
                    SUM(CASE WHEN p.refund_status = 'completed' THEN p.amount ELSE 0 END) as completed_amount,
                    SUM(CASE WHEN p.refund_status = 'failed' THEN p.amount ELSE 0 END) as failed_amount
                 FROM payments p
                 WHERE p.refund_status NOT IN ('none','pending_return')"
            );
            $summary_stmt->execute();
            $summary = $summary_stmt->fetch();

            echo json_encode([
                'success' => true,
                'refunds' => $refunds,
                'manual_settlements' => $manual_settlements,
                'summary' => $summary,
                'pagination' => [
                    'page' => $page,
                    'per_page' => $per_page,
                    'total' => $total,
                    'total_pages' => $total_pages,
                ]
            ]);
            exit;

        // ── Retry failed refund ────────────────────────────────
        case 'retry_refund':
            $payment_id = (int)($_POST['payment_id'] ?? 0);

            if (!$payment_id) {
                http_response_code(422);
                die(json_encode(['success' => false, 'message' => 'ID pembayaran tidak sah']));
            }

            // Enforce the authoritative return-gate (DEFECT-002): a refund whose
            // linked dispute requires a physical return must not be re-driven to
            // the gateway until that return is verified. Legitimate release goes
            // through admin_returns.php (verify/waive), which clears the hold first.
            $gate = refund_can_proceed($pdo, $payment_id);
            if (!$gate['can_proceed']) {
                http_response_code(409);
                error_log("[refund_gate] Blocked retry_refund payment={$payment_id}: {$gate['reason']}");
                die(json_encode([
                    'success' => false,
                    'message' => 'Pulangan belum boleh diproses: ' . $gate['reason']
                               . '. Sahkan pulangan barang di halaman Pulangan Barang dahulu.',
                ]));
            }

            // Import refund processing function
            require_once __DIR__ . '/../config/payment.php';

            // Retry the refund
            $refund_result = process_payment_refund(
                $pdo,
                $payment_id,
                'Cuba semula oleh pentadbir',
                'admin'
            );

            echo json_encode($refund_result);
            exit;

        // ── Mark refund as manually completed ──────────────────
        case 'mark_refund_completed':
            $payment_id = (int)($_POST['payment_id'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');

            if (!$payment_id) {
                http_response_code(422);
                die(json_encode(['success' => false, 'message' => 'ID pembayaran tidak sah']));
            }

            try {
                $pdo->beginTransaction();

                // Enforce the authoritative return-gate (DEFECT-002) before a
                // manual completion. This is stronger than the refund_status
                // allowlist below: it consults return_shipments directly, so a
                // 'requested' refund still held by an unverified return is blocked.
                $gate = refund_can_proceed($pdo, $payment_id);
                if (!$gate['can_proceed']) {
                    $pdo->rollBack();
                    http_response_code(409);
                    error_log("[refund_gate] Blocked mark_refund_completed payment={$payment_id}: {$gate['reason']}");
                    die(json_encode([
                        'success' => false,
                        'message' => 'Pulangan belum boleh ditanda selesai: ' . $gate['reason']
                                   . '. Sahkan pulangan barang di halaman Pulangan Barang dahulu.',
                    ]));
                }

                // Update refund status to completed
                require_once __DIR__ . '/../config/payment.php';

                // #5: a manual sign-off must record the amount PTU actually
                // decided, not an assumed full refund, and may only call the
                // payment 'refunded' when the whole of it really went back.
                $pr = $pdo->prepare("SELECT payment_id, amount, transaction_id FROM payments WHERE payment_id = ?");
                $pr->execute([$payment_id]);
                $pay_rec = $pr->fetch(PDO::FETCH_ASSOC);
                if (!$pay_rec) {
                    $pdo->rollBack();
                    http_response_code(404);
                    die(json_encode(['success' => false, 'message' => 'Pembayaran tidak dijumpai']));
                }
                $rr = resolve_refund_amount($pdo, $pay_rec);

                $stmt = $pdo->prepare("
                    UPDATE payments
                    SET refund_status = 'completed',
                        refund_amount = ?,
                        refund_completed_at = CURRENT_TIMESTAMP,
                        refund_method = 'admin',
                        refund_error = CONCAT(COALESCE(refund_error, ''), ' | Manual completion: ', ?),
                        status = CASE WHEN ? = 0 THEN 'refunded' ELSE status END
                    WHERE payment_id = ? AND refund_status IN ('failed', 'requested', 'processing')
                ");
                $stmt->execute([$rr['amount'], $notes ?: 'Marked by admin', $rr['is_partial'] ? 1 : 0, $payment_id]);

                if ($stmt->rowCount() === 0) {
                    $pdo->rollBack();
                    http_response_code(422);
                    die(json_encode(['success' => false, 'message' => 'Pembayaran tidak dijumpai atau sudah selesai']));
                }

                // Update transaction
                $stmt = $pdo->prepare("
                    UPDATE transactions
                    SET payment_status = CASE WHEN ? = 0 THEN 'refunded' ELSE payment_status END
                    WHERE transaction_id = ?
                ");
                $stmt->execute([$rr['is_partial'] ? 1 : 0, $pay_rec['transaction_id']]);

                // #5 lifecycle: a manual sign-off IS the money leaving, so the
                // settlement decision it belongs to is now closed instead of
                // sitting at 'queued' forever. Only a full return makes the
                // buyer whole, so a partial one leaves the settlement queued.
                if (!$rr['is_partial']) {
                    $pdo->prepare(
                        "UPDATE disputes
                            SET settlement_status = 'completed'
                          WHERE transaction_id = ? AND settlement_status = 'queued'"
                    )->execute([$pay_rec['transaction_id']]);
                }

                $pdo->commit();

                echo json_encode([
                    'success' => true,
                    'message' => $rr['is_partial']
                        ? 'Sebahagian pulangan RM' . number_format($rr['amount'], 2) . ' ditanda selesai'
                        : 'Pulangan wang RM' . number_format($rr['amount'], 2) . ' ditanda sebagai selesai'
                ]);
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[mark_refund_completed] ' . $e->getMessage());
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Ralat pelayan']);
            }
            exit;

        // ── Close a manual (non-gateway) settlement ──────────────────────
        // Audit #4/#5: a cash order has no payments row, so it can never appear
        // in the gateway refund queue above, and nothing could close its
        // settlement — a buyer-favourable ruling on cash left
        // disputes.settlement_status at 'queued' permanently. Same return gate as
        // the gateway path, via the twin stored procedure so the two rules cannot
        // drift apart.
        case 'complete_manual_settlement':
            $dispute_id = (int)($_POST['dispute_id'] ?? 0);
            $notes = trim($_POST['notes'] ?? '');

            if (!$dispute_id) {
                http_response_code(422);
                die(json_encode(['success' => false, 'message' => 'ID pertikaian tidak sah']));
            }

            try {
                $pdo->beginTransaction();

                $ms = $pdo->prepare(
                    "SELECT d.dispute_id, d.transaction_id, d.settlement_status,
                            d.settlement_refund_amount, d.settlement_return_required,
                            t.payment_type, t.payment_status, t.total_amount, t.status AS order_status
                       FROM disputes d
                       JOIN transactions t ON t.transaction_id = d.transaction_id
                      WHERE d.dispute_id = ?
                        FOR UPDATE"
                );
                $ms->execute([$dispute_id]);
                $settle = $ms->fetch(PDO::FETCH_ASSOC);

                if (!$settle) {
                    $pdo->rollBack();
                    http_response_code(404);
                    die(json_encode(['success' => false, 'message' => 'Pertikaian tidak dijumpai']));
                }

                if ($settle['settlement_status'] !== 'queued') {
                    $pdo->rollBack();
                    http_response_code(422);
                    die(json_encode([
                        'success' => false,
                        'message' => 'Tiada pulangan manual tertunggak untuk keputusan ini (status: '
                                   . $settle['settlement_status'] . ').'
                    ]));
                }

                // A settlement that demands the goods back may not be signed off
                // before that return is verified — same rule as online refunds.
                $gate = settlement_can_proceed($pdo, $dispute_id);
                if (!$gate['can_proceed']) {
                    $pdo->rollBack();
                    http_response_code(409);
                    error_log("[settlement_gate] Blocked complete_manual_settlement dispute={$dispute_id}: {$gate['reason']}");
                    die(json_encode([
                        'success' => false,
                        'message' => 'Pulangan belum boleh ditanda selesai: ' . $gate['reason']
                                   . '. Sahkan pulangan barang di halaman Pulangan Barang dahulu.',
                    ]));
                }

                // What PTU decided, falling back to the order total when the
                // ruling predates the settlement form (settlement_refund_amount
                // NULL) — the buyer is owed something, and 'nothing' is not it.
                $amount = $settle['settlement_refund_amount'] !== null
                    ? (float)$settle['settlement_refund_amount']
                    : (float)$settle['total_amount'];
                $amount = round($amount, 2);

                $upd = $pdo->prepare(
                    "UPDATE disputes
                        SET settlement_status = 'completed',
                            settlement_refund_amount = ?,
                            settlement_notes  = CONCAT(COALESCE(settlement_notes, ''), ' | ', ?),
                            settled_by        = ?,
                            settled_at        = CURRENT_TIMESTAMP
                      WHERE dispute_id = ? AND settlement_status = 'queued'"
                );
                $upd->execute([
                    $amount,
                    ($notes !== '' ? $notes : 'Pulangan disahkan telah diserahkan oleh PTU'),
                    $user['user_id'],
                    $dispute_id,
                ]);

                if ($upd->rowCount() === 0) {
                    $pdo->rollBack();
                    http_response_code(422);
                    die(json_encode(['success' => false, 'message' => 'Settlement sudah ditutup.']));
                }

                // The buyer has been made whole, so the order must stop claiming
                // the money is still with the seller. Only non-gateway rows reach
                // here, and 'refunded' is the same word the online rail uses.
                $pdo->prepare("UPDATE transactions SET payment_status = 'refunded' WHERE transaction_id = ?")
                    ->execute([$settle['transaction_id']]);

                $pdo->commit();

                error_log('[manual_settlement] CLOSED dispute=' . $dispute_id . ' txn=' . $settle['transaction_id']
                        . ' amount=' . number_format($amount, 2) . ' type=' . $settle['payment_type']
                        . ' by=' . $user['user_id']);

                echo json_encode([
                    'success' => true,
                    'message' => 'Pulangan RM' . number_format($amount, 2) . ' (' . (($settle['payment_type'] === 'cash') ? 'tunai' : 'manual') . ') ditanda selesai',
                    'refund_amount' => $amount,
                ]);
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[complete_manual_settlement] ' . $e->getMessage());
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Ralat pelayan']);
            }
            exit;

        //  Dashboard summary stats (for reactive refresh) 
        case 'get_stats':
            echo json_encode(array_merge(
                ['success' => true],
                fetch_admin_stats($pdo)
            ));
            exit;

        //  Suspend or re-activate a user account 
        case 'toggle_user_status':
            $target_id = (int) ($_POST['user_id'] ?? 0);

            if (!$target_id) {
                http_response_code(422);
                die(json_encode(['success' => false, 'message' => 'user_id diperlukan.']));
            }

            // Prevent admin from suspending themselves
            if ($target_id === $user['user_id']) {
                http_response_code(422);
                die(json_encode(['success' => false, 'message' => 'Anda tidak boleh menggantung akaun anda sendiri.']));
            }

            $stmt = $pdo->prepare('SELECT user_id, full_name, is_active, role FROM users WHERE user_id = ?');
            $stmt->execute([$target_id]);
            $target = $stmt->fetch();

            if (!$target) {
                http_response_code(404);
                die(json_encode(['success' => false, 'message' => 'Pengguna tidak dijumpai.']));
            }

            // Prevent deactivating another admin
            if ($target['role'] === 'pentadbir') {
                http_response_code(422);
                die(json_encode(['success' => false, 'message' => 'Akaun pentadbir tidak boleh digantung.']));
            }

            $new_status = $target['is_active'] ? 0 : 1;
            $pdo->prepare('UPDATE users SET is_active = ? WHERE user_id = ?')
                ->execute([$new_status, $target_id]);

            $action_lbl = $new_status ? 'diaktifkan semula' : 'digantung';
            echo json_encode([
                'success'    => true,
                'message'    => "Akaun {$target['full_name']} telah {$action_lbl}.",
                'new_status' => $new_status,
            ]);
            exit;

        //  Analytics data (for charts) 
        case 'get_analytics':
            // Transactions per day, last 30 days
            $txn_trend = $pdo->query(
                "SELECT DATE(created_at) AS day,
                        COUNT(*)         AS orders,
                        SUM(total_amount) AS revenue
                   FROM transactions
                  WHERE status = 'Selesai'
                    AND created_at >= CURDATE() - INTERVAL 29 DAY
                  GROUP BY DATE(created_at)
                  ORDER BY day ASC"
            )->fetchAll();

            // Sales by category
            $by_cat = $pdo->query(
                "SELECT p.category,
                        COUNT(t.transaction_id) AS orders,
                        SUM(t.total_amount)     AS revenue
                   FROM transactions t
                   JOIN products p ON t.product_id = p.product_id
                  WHERE t.status = 'Selesai'
                  GROUP BY p.category
                  ORDER BY revenue DESC"
            )->fetchAll();

            // Top sellers by trust score.
            // Ranks on the SAME number every buyer-facing surface shows —
            // COALESCE(tp.ai_trust_score, tp.score) — not the legacy
            // users.trust_score mirror. That mirror is kept in sync with the
            // PRE-AI baseline by the recalculation procedures, so ranking on it
            // hid the AI penalty: a seller the AI layer had just held for
            // circular trading appeared first here while the product card on the
            // same screen showed the penalised score (audit #3a). Field is
            // aliased trust_score so the chart renderer is unchanged.
            $top_sellers = $pdo->query(
                "SELECT u.full_name,
                        COALESCE(tp.ai_trust_score, tp.score, 0) AS trust_score,
                        u.is_verified,
                        COUNT(t.transaction_id) AS sales
                   FROM users u
              LEFT JOIN trust_profiles tp ON tp.user_id  = u.user_id
                                         AND tp.profile_role = 'penjual'
              LEFT JOIN transactions t ON t.seller_id = u.user_id
                                      AND t.status    = 'Selesai'
                  WHERE u.role = 'penjual'
                  GROUP BY u.user_id
                  ORDER BY trust_score DESC
                  LIMIT 5"
            )->fetchAll();

            echo json_encode([
                'success'     => true,
                'txn_trend'   => $txn_trend,
                'by_cat'      => $by_cat,
                'top_sellers' => $top_sellers,
            ]);
            exit;

        // ── AI Trust Layer: high-risk profiles for review ──────
        case 'get_ai_risks':
            $stmt = $pdo->query(
                "SELECT tp.user_id, tp.profile_role, tp.fraud_risk, tp.ai_adjustment,
                        tp.ai_trust_score, tp.ai_flags, tp.ai_hold, tp.ai_calculated_at,
                        tp.ai_hold_released_at, u.full_name,
                        COALESCE(NULLIF(u.matric_no, ''), u.staff_no) AS ident_no,
                        u.is_active,
                        (SELECT GROUP_CONCAT(CONCAT(s.signal_code, ' (-', ABS(s.penalty), ')')
                                   ORDER BY s.penalty ASC SEPARATOR ', ')
                           FROM trust_ai_signals s
                          WHERE s.user_id = tp.user_id AND s.profile_role = tp.profile_role
                        ) AS signals
                   FROM trust_profiles tp
                   JOIN users u ON u.user_id = tp.user_id
                  WHERE tp.fraud_risk > 0
                    AND tp.profile_role = 'penjual'
                  ORDER BY tp.fraud_risk DESC, tp.user_id
                  LIMIT 25"
            );
            echo json_encode([
                'success'   => true,
                'hold_risk' => AI_TRUST_HOLD_RISK,
                'rows'      => $stmt->fetchAll(),
            ]);
            exit;

        // ── AI Trust Layer: release or re-apply the order hold ─
        case 'ai_set_hold':
            $target_id = (int) ($_POST['user_id'] ?? 0);
            $role      = trim($_POST['profile_role'] ?? 'penjual');
            $hold      = $_POST['hold'] ?? '';

            if (!$target_id || !in_array($role, TRUST_PROFILE_ROLES, true) || !in_array((string)$hold, ['0','1'], true)) {
                http_response_code(422);
                die(json_encode(['success' => false, 'message' => 'Parameter tidak sah (user_id, profile_role, hold).']));
            }
            $hold = (int) $hold;

            $stmt = $pdo->prepare('SELECT user_id, fraud_risk, ai_hold FROM trust_profiles WHERE user_id=? AND profile_role=?');
            $stmt->execute([$target_id, $role]);
            if (!$stmt->fetch()) {
                http_response_code(404);
                die(json_encode(['success' => false, 'message' => 'Profil kepercayaan tidak dijumpai.']));
            }

            if ($hold === 0) {
                $pdo->prepare(
                    'UPDATE trust_profiles
                        SET ai_hold = 0, ai_hold_released_by = ?, ai_hold_released_at = CURRENT_TIMESTAMP
                      WHERE user_id = ? AND profile_role = ?'
                )->execute([$user['user_id'], $target_id, $role]);
                $msg = 'Tahanan AI dilepaskan — pengguna ini boleh berurus niaga semula.';
            } else {
                $pdo->prepare(
                    'UPDATE trust_profiles
                        SET ai_hold = 1, ai_hold_released_by = NULL, ai_hold_released_at = NULL
                      WHERE user_id = ? AND profile_role = ?'
                )->execute([$target_id, $role]);
                $msg = 'Tahanan AI dikenakan semula — pesanan baharu dihalang.';
            }
            echo json_encode(['success' => true, 'message' => $msg]);
            exit;

        default:
            http_response_code(400);
            die(json_encode([
                'success' => false,
                'message' => 'Tindakan tidak diketahui.',
            ]));
    }
}

// 
// HELPERS
// 
/**
 * Authoritative refund gate (DEFECT-002).
 *
 * Consults the can_proceed_refund() stored procedure so a refund tied to a
 * dispute that requires a physical product return cannot be released or
 * retried until that return reaches status='verified'. The admin "Pulangan
 * Barang" page (admin_returns.php) is the only legitimate way to lift the
 * hold (verify or waive), and it clears refund_status='pending_return' first,
 * so gating the two manual refund actions here adds no dead-end — it only
 * closes the crafted/bypass path. Returns ['can_proceed'=>bool,'reason'=>string].
 */
function refund_can_proceed(PDO $pdo, int $payment_id): array {
    $stmt = $pdo->prepare('CALL can_proceed_refund(?, @p_can, @p_reason)');
    $stmt->execute([$payment_id]);
    $stmt->closeCursor();
    $row = $pdo->query('SELECT @p_can AS can_proceed, @p_reason AS reason')->fetch(PDO::FETCH_ASSOC);
    return [
        'can_proceed' => (int) ($row['can_proceed'] ?? 1) === 1,
        'reason'      => (string) ($row['reason'] ?? ''),
    ];
}

/**
 * Authoritative gate for a manual (non-gateway) settlement — the cash twin of
 * refund_can_proceed() above. Same rule, asked of the database rather than
 * re-typed in PHP, so the online and cash rails cannot drift apart: if the
 * ruling requires the goods back, the money may not be signed off until that
 * return is verified. Returns ['can_proceed'=>bool,'reason'=>string].
 */
function settlement_can_proceed(PDO $pdo, int $dispute_id): array {
    $stmt = $pdo->prepare('CALL can_proceed_settlement(?, @ms_can, @ms_reason)');
    $stmt->execute([$dispute_id]);
    $stmt->closeCursor();
    $row = $pdo->query('SELECT @ms_can AS can_proceed, @ms_reason AS reason')->fetch(PDO::FETCH_ASSOC);
    return [
        'can_proceed' => (int) ($row['can_proceed'] ?? 1) === 1,
        'reason'      => (string) ($row['reason'] ?? ''),
    ];
}

function fetch_admin_stats(PDO $pdo): array
{
    // User counts
    $users = $pdo->query(
        "SELECT role, COUNT(*) AS cnt FROM users GROUP BY role"
    )->fetchAll();
    $user_counts = array_column($users, 'cnt', 'role');

    // Transaction counts + revenue
    $txns = $pdo->query(
        "SELECT status,
                COUNT(*)          AS cnt,
                SUM(total_amount) AS revenue
           FROM transactions
          GROUP BY status"
    )->fetchAll();
    $txn_counts = [];
    $revenue    = 0;
    foreach ($txns as $t) {
        $txn_counts[$t['status']] = (int) $t['cnt'];
        if ($t['status'] === 'Selesai') $revenue = (float) $t['revenue'];
    }

    // Pending verifications
    $pending_sellers = (int) $pdo->query(
        "SELECT COUNT(*) FROM users WHERE role='penjual' AND verification_status='Menunggu'"
    )->fetchColumn();

    // Open disputes
    $open_disputes = (int) $pdo->query(
        "SELECT COUNT(*) FROM disputes WHERE status != 'Selesai'"
    )->fetchColumn();

    // Urgent disputes
    $urgent = (int) $pdo->query(
        "SELECT COUNT(*) FROM disputes
          WHERE status != 'Selesai' AND severity = 'Mendesak'"
    )->fetchColumn();

    // This month's new users
    $new_users = (int) $pdo->query(
        "SELECT COUNT(*) FROM users
          WHERE MONTH(created_at) = MONTH(CURDATE())
            AND YEAR(created_at)  = YEAR(CURDATE())"
    )->fetchColumn();

    return [
        'user_counts'     => $user_counts,
        'txn_counts'      => $txn_counts,
        'revenue'         => $revenue,
        'pending_sellers' => $pending_sellers,
        'open_disputes'   => $open_disputes,
        'urgent_disputes' => $urgent,
        'new_users_month' => $new_users,
        'total_users'     => array_sum($user_counts),
    ];
}

//  Initial page data 
$stats = fetch_admin_stats($pdo);

// Pending sellers for quick-view
$stmt = $pdo->prepare(
    "SELECT u.user_id, u.full_name, u.matric_no, u.staff_no, u.user_type,
            COALESCE(NULLIF(u.matric_no, ''), u.staff_no) AS ident_no,
            u.email,
            u.faculty, u.created_at,
            COUNT(DISTINCT p.product_id) AS product_count
       FROM users u
  LEFT JOIN products p ON p.seller_id = u.user_id AND p.status != 'Dipadam'
      WHERE u.role = 'penjual' AND u.verification_status = 'Menunggu'
      GROUP BY u.user_id
      ORDER BY u.created_at ASC
      LIMIT 5"
);
$stmt->execute();
$pending_sellers = $stmt->fetchAll();

// Urgent disputes for quick-view
$stmt = $pdo->query(
    // transaction_id is required by the "Selesaikan" button below: openDisputeModal
    // takes (dispute_id, transaction_id, total) and the AJAX list passes all three.
    // Omitting it here made the quick-view render "Pesanan #undefined" with a
    // 0.00 refund ceiling.
    "SELECT d.dispute_id, d.title, d.severity, d.status,
            d.created_at, t.transaction_id, t.total_amount,
            buyer.full_name  AS buyer_name,
            seller.full_name AS seller_name,
            p.name           AS product_name
       FROM disputes d
       JOIN transactions t ON d.transaction_id = t.transaction_id
       JOIN users buyer    ON t.buyer_id        = buyer.user_id
       JOIN users seller   ON t.seller_id       = seller.user_id
       JOIN products p     ON t.product_id      = p.product_id
      WHERE d.status != 'Selesai'
      ORDER BY FIELD(d.severity,'Mendesak','Sederhana','Rendah'), d.created_at
      LIMIT 5"
);
$urgent_disputes = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ms">
<head>
  <meta charset="UTF-8">
  <?= csrf_meta_tag() ?>
  <?= csrf_script() ?>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TrustMart — Pentadbir PTU</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:wght@300;400;500;600&display=swap" rel="stylesheet">
  <style>
    :root{
      --gold:#C9A84C;--gold-pale:#FAF6E8;--gold-light:#F0DFA0;
      --ink:#1A1714;--ink2:#3D3830;--ink3:#6B6358;--ink4:#A09589;
      --cream:#FBF8F2;--cream2:#F3EFE5;--cream3:#EAE4D6;
      --green:#1D6B4A;--green-bg:#EAF5EE;
      --red:#B03A2E;--red-bg:#FAEAE8;
      --blue:#1A4F8A;--blue-bg:#EAF0FA;
      --r:10px;--r-sm:6px;
    }
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
    body{font-family:'DM Sans',sans-serif;background:var(--cream);
         color:var(--ink);font-size:14px}

    /*  Layout  */
    .shell  {display:flex;height:100vh;overflow:hidden}
    .sidebar{width:220px;background:var(--ink);flex-shrink:0;
             display:flex;flex-direction:column;padding:24px 0}
    .main   {flex:1;overflow-y:auto}
    .topbar {height:60px;background:#fff;border-bottom:1px solid var(--cream3);
             display:flex;align-items:center;padding:0 28px;gap:16px;
             position:sticky;top:0;z-index:10}
    .content{padding:28px}

    /*  Sidebar  */
    .logo-text{font-family:'DM Serif Display',serif;font-size:22px;color:#fff;
               padding:0 20px 24px;border-bottom:1px solid rgba(255,255,255,.08)}
    .logo-text span{color:var(--gold)}
    .nav-section{padding:20px 12px 8px;font-size:10px;letter-spacing:1.5px;
                 text-transform:uppercase;color:rgba(255,255,255,.3)}
    .nav-item{display:flex;align-items:center;gap:10px;padding:9px 20px;
              color:rgba(255,255,255,.55);cursor:pointer;font-size:13px;
              border-left:2px solid transparent;transition:all .15s}
    .nav-item:hover{color:#fff;background:rgba(255,255,255,.06)}
    .nav-item.active{color:#fff;background:rgba(201,168,76,.15);
                     border-left-color:var(--gold)}
    .sidebar-footer{margin-top:auto;padding:16px 20px;
                    border-top:1px solid rgba(255,255,255,.08)}
    .avatar{width:32px;height:32px;border-radius:50%;background:var(--gold);
            display:flex;align-items:center;justify-content:center;
            font-size:12px;font-weight:600;color:var(--ink)}
    .badge-ptu{display:inline-flex;align-items:center;gap:4px;margin-top:6px;
               background:rgba(201,168,76,.15);color:var(--gold);
               padding:3px 10px;border-radius:20px;font-size:10px;font-weight:500}

    /*  Stat cards  */
    .stats-grid{display:grid;grid-template-columns:repeat(4,1fr);
                gap:14px;margin-bottom:28px}
    .stat-card{background:#fff;border:1px solid var(--cream3);
               border-radius:var(--r);padding:18px;position:relative;overflow:hidden}
    .stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px}
    .stat-card.gold::before{background:var(--gold)}
    .stat-card.green::before{background:var(--green)}
    .stat-card.blue::before{background:var(--blue)}
    .stat-card.red::before{background:var(--red)}
    .stat-label{font-size:11px;color:var(--ink3);letter-spacing:.5px;
                text-transform:uppercase;margin-bottom:8px}
    .stat-val{font-size:26px;font-weight:600;line-height:1;margin-bottom:4px}
    .stat-sub{font-size:11px;color:var(--ink4)}
    .stat-urgent{position:absolute;top:14px;right:14px;background:var(--red-bg);
                 color:var(--red);font-size:10px;font-weight:600;
                 padding:2px 7px;border-radius:20px}

    /*  Two-col grid  */
    .two-col{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:28px}
    .three-col{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:20px}
    .card-box{background:#fff;border:1px solid var(--cream3);
              border-radius:var(--r);padding:20px}

    /*  Table  */
    .tbl-wrap{background:#fff;border:1px solid var(--cream3);
              border-radius:var(--r);overflow:hidden;margin-bottom:20px}
    .tbl{width:100%;border-collapse:collapse}
    .tbl th{background:var(--cream2);text-align:left;padding:10px 16px;
            font-size:11px;font-weight:500;letter-spacing:.5px;
            text-transform:uppercase;color:var(--ink3);
            border-bottom:1px solid var(--cream3)}
    .tbl td{padding:12px 16px;font-size:13px;
            border-bottom:1px solid var(--cream2)}
    .tbl tr:last-child td{border-bottom:none}
    .tbl tr:hover td{background:var(--cream)}

    /*  Badges & Buttons  */
    .badge{display:inline-flex;align-items:center;gap:4px;padding:3px 8px;
           border-radius:20px;font-size:11px;font-weight:500}
    .badge-dot{width:5px;height:5px;border-radius:50%;background:currentColor}
    .badge-gold{background:var(--gold-pale);color:#7A5A10}
    .badge-blue{background:var(--blue-bg);color:var(--blue)}
    .badge-green{background:var(--green-bg);color:var(--green)}
    .badge-red{background:var(--red-bg);color:var(--red)}
    .badge-gray{background:var(--cream2);color:var(--ink3)}
    .btn{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;
         border-radius:var(--r-sm);border:1px solid var(--cream3);background:#fff;
         color:var(--ink);font-size:12px;font-weight:500;cursor:pointer;
         transition:.15s;font-family:inherit}
    .btn:hover{border-color:var(--gold);color:var(--gold)}
    .btn-primary{background:var(--gold);border-color:var(--gold);color:var(--ink)}
    .btn-primary:hover{background:#b8942f;border-color:#b8942f}
    .btn-danger{background:var(--red-bg);border-color:var(--red);color:var(--red)}
    .btn-success{background:var(--green-bg);border-color:var(--green);color:var(--green)}
    .btn-info{background:var(--blue-bg);border-color:var(--blue);color:var(--blue)}
    .btn-sm{padding:5px 10px;font-size:11px}
    .btn:disabled{opacity:.5;cursor:not-allowed}

    /*  Filters  */
    .filters{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;align-items:center}
    .filter-btn{padding:5px 12px;border-radius:20px;border:1px solid var(--cream3);
                background:#fff;color:var(--ink3);font-size:12px;cursor:pointer;
                transition:.15s;font-family:inherit}
    .filter-btn.active{border-color:var(--gold);color:var(--ink);background:var(--gold-pale)}
    .search-input{padding:6px 14px;border:1px solid var(--cream3);border-radius:20px;
                  font-size:12px;font-family:inherit;outline:none;
                  background:#fff;color:var(--ink);margin-left:auto;width:200px}
    .search-input:focus{border-color:var(--gold)}

    /*  Dispute card  */
    .dispute-card{background:#fff;border:1px solid var(--cream3);
                  border-radius:var(--r);padding:16px;margin-bottom:10px}
    .dispute-card.urgent{border-left:3px solid var(--red)}
    .dispute-card.sederhana{border-left:3px solid var(--gold)}
    .dispute-card.rendah{border-left:3px solid var(--ink4)}
    .dispute-hd{display:flex;justify-content:space-between;align-items:flex-start;
                margin-bottom:8px;gap:10px}
    .dispute-title{font-size:13px;font-weight:500}
    .dispute-meta{font-size:11px;color:var(--ink3);line-height:1.6;margin-bottom:10px}
    .dispute-footer{display:flex;align-items:center;gap:8px;flex-wrap:wrap;
                    padding-top:10px;border-top:1px solid var(--cream2)}

    /*  Section header  */
    .section-hd{display:flex;align-items:center;justify-content:space-between;
                margin-bottom:16px}
    .section-title{font-size:15px;font-weight:500}
    .divider{height:1px;background:var(--cream2);margin:16px 0}

    /*  Mini bar chart  */
    .bar-row{display:flex;align-items:center;gap:8px;margin-bottom:8px;font-size:12px}
    .bar-label{width:100px;color:var(--ink3);flex-shrink:0;
               white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .bar-track{flex:1;height:8px;background:var(--cream2);border-radius:4px;overflow:hidden}
    .bar-fill{height:100%;border-radius:4px;background:var(--gold);transition:width .5s}
    .bar-val{width:52px;text-align:right;font-size:11px;font-weight:500;flex-shrink:0}

    /*  Modal  */
    .modal-bg{position:fixed;inset:0;background:rgba(26,23,20,.5);z-index:100;
              display:flex;align-items:center;justify-content:center}
    .modal{background:#fff;border-radius:16px;padding:28px;width:560px;
           max-width:92vw;max-height:88vh;overflow-y:auto}
    .modal-title{font-family:'DM Serif Display',serif;font-size:20px;margin-bottom:4px}
    .modal-sub{font-size:12px;color:var(--ink3);margin-bottom:20px}
    .modal-footer{display:flex;gap:8px;justify-content:flex-end;margin-top:20px;
                  padding-top:16px;border-top:1px solid var(--cream2)}
    .info-row{display:flex;gap:8px;margin-bottom:8px;font-size:13px}
    .info-label{width:130px;color:var(--ink3);flex-shrink:0;font-size:12px}
    .info-val{font-weight:500}
    .form-group{margin-bottom:14px}
    .form-label{display:block;font-size:12px;font-weight:500;
                color:var(--ink2);margin-bottom:6px}
    .form-input{width:100%;padding:10px 14px;border:1px solid var(--cream3);
                border-radius:var(--r-sm);font-size:13px;color:var(--ink);
                font-family:inherit;outline:none;background:#fff}
    .form-input:focus{border-color:var(--gold)}
    textarea.form-input{resize:vertical;min-height:70px}

    /*  Toast  */
    .toast{position:fixed;bottom:24px;right:24px;background:var(--ink);color:#fff;
           padding:12px 18px;border-radius:var(--r);font-size:13px;z-index:200;
           transform:translateY(100px);opacity:0;transition:all .3s;max-width:320px}
    .toast.show{transform:translateY(0);opacity:1}
    .toast.success{background:var(--green)}
    .toast.error{background:var(--red)}

    .empty-state{text-align:center;padding:32px;color:var(--ink3)}
    .spinner{display:inline-block;width:14px;height:14px;
             border:2px solid rgba(0,0,0,.15);border-top-color:var(--ink);
             border-radius:50%;animation:spin .6s linear infinite;
             vertical-align:middle;margin-right:6px}
    @keyframes spin{to{transform:rotate(360deg)}}

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

    @media(max-width:768px){
      .sidebar{display:none}
      .stats-grid{grid-template-columns:1fr 1fr}
      .two-col,.three-col{grid-template-columns:1fr}
    }
  </style>
  <meta name="theme-color" content="#171b26">
  <link rel="stylesheet" href="../public/assets/css/trustmart-ui.css">
  <script src="../public/assets/js/trustmart-ui.js" defer></script>
</head>
<body>
<div class="shell">

  <!-- 
       SIDEBAR
   -->
  <aside class="sidebar">
    <div class="logo-text">Trust<span>Mart</span></div>

    <div class="nav-section">Pentadbir PTU</div>

    <div class="nav-item active" onclick="showTab('dash')">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
           viewBox="0 0 24 24">
        <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
        <rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
      </svg>Papan Pemuka
    </div>
    <div class="nav-item" onclick="showTab('pengesahan')">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
           viewBox="0 0 24 24">
        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
      </svg>Pengesahan Penjual
      <?php if ($stats['pending_sellers']): ?>
      <span style="margin-left:auto;background:var(--gold);color:var(--ink);
                   font-size:10px;font-weight:600;padding:1px 6px;border-radius:10px">
        <?= $stats['pending_sellers'] ?>
      </span>
      <?php endif ?>
    </div>
    <div class="nav-item" onclick="showTab('aduan')">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
           viewBox="0 0 24 24">
        <circle cx="12" cy="12" r="10"/>
        <line x1="12" y1="8" x2="12" y2="12"/>
        <line x1="12" y1="16" x2="12.01" y2="16"/>
      </svg>Urus Aduan
      <?php if ($stats['urgent_disputes']): ?>
      <span style="margin-left:auto;background:var(--red);color:#fff;
                   font-size:10px;font-weight:600;padding:1px 6px;border-radius:10px">
        <?= $stats['urgent_disputes'] ?>
      </span>
      <?php endif ?>
    </div>
    <div class="nav-item" onclick="showTab('pengguna')">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
           viewBox="0 0 24 24">
        <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/>
        <circle cx="9" cy="7" r="4"/>
        <path d="M23 21v-2a4 4 0 0 0-3-3.87"/>
        <path d="M16 3.13a4 4 0 0 1 0 7.75"/>
      </svg>Semua Pengguna
    </div>
    <div class="nav-item" onclick="showTab('transaksi')">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
           viewBox="0 0 24 24">
        <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
        <line x1="1" y1="10" x2="23" y2="10"/>
      </svg>Transaksi
    </div>
    <div class="nav-item" onclick="showTab('pulangan')">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
           viewBox="0 0 24 24">
        <rect x="1" y="4" width="22" height="16" rx="2" ry="2"/>
        <circle cx="12" cy="12" r="4"/>
        <line x1="6" y1="12" x2="6.01" y2="12"/>
        <line x1="18" y1="12" x2="18.01" y2="12"/>
      </svg>Pulangan Wang
    </div>
    <div class="nav-item" onclick="showTab('barang')">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
           viewBox="0 0 24 24">
        <polyline points="9 14 4 9 9 4"/>
        <path d="M20 20v-7a4 4 0 0 0-4-4H4"/>
      </svg>Pulangan Barang
    </div>
    <div class="nav-item" onclick="showTab('analitik')">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
           viewBox="0 0 24 24">
        <line x1="18" y1="20" x2="18" y2="10"/>
        <line x1="12" y1="20" x2="12" y2="4"/>
        <line x1="6"  y1="20" x2="6"  y2="14"/>
      </svg>Analitik Platform
    </div>
    <a class="nav-item" href="profile.php" style="text-decoration:none">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
           viewBox="0 0 24 24">
        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
        <circle cx="12" cy="7" r="4"/>
      </svg>Profil Saya
    </a>

    <div class="sidebar-footer">
      <div style="display:flex;align-items:center;gap:10px">
        <div class="avatar"><?= htmlspecialchars(mb_substr($user['full_name'],0,1)) ?></div>
        <div>
          <div style="font-size:12px;color:rgba(255,255,255,.8)">
            <?= htmlspecialchars($user['full_name']) ?>
          </div>
          <div style="font-size:10px;color:rgba(255,255,255,.35)">
            PTU  <?= htmlspecialchars($user['ident_no']) ?>
          </div>
        </div>
      </div>
      <div class="badge-ptu"> Pentadbir PTU</div>
      <div style="margin-top:10px">
        <a href="../auth/logout.php"
           style="display:flex;align-items:center;gap:8px;padding:7px 0;
                  font-size:12px;color:rgba(255,255,255,.4);text-decoration:none">
          <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2"
               viewBox="0 0 24 24">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/>
            <polyline points="16 17 21 12 16 7"/>
            <line x1="21" y1="12" x2="9" y2="12"/>
          </svg>Log Keluar
        </a>
      </div>
    </div>
  </aside>

  <!-- 
       MAIN
   -->
  <div class="main">
    <div class="topbar">
      <div style="font-family:'DM Serif Display',serif;font-size:16px" id="topbar-title">
        Papan Pemuka
      </div>
      <div style="font-size:12px;color:var(--ink3);margin-left:8px">
        <?= date('d M Y, H:i') ?>
      </div>
      <?php if ($stats['urgent_disputes']): ?>
      <div style="margin-left:auto;display:flex;align-items:center;gap:6px;
                  font-size:12px;background:var(--red-bg);color:var(--red);
                  padding:5px 12px;border-radius:20px;cursor:pointer"
           onclick="showTab('aduan')">
         <?= $stats['urgent_disputes'] ?> aduan mendesak
      </div>
      <?php else: ?>
      <div style="margin-left:auto;font-size:12px;color:var(--ink4)">
        Sistem beroperasi normal
      </div>
      <?php endif ?>
    </div>

    <div class="content">

      <!-- 
           TAB: PAPAN PEMUKA
       -->
      <div id="tab-dash">

        <!-- Stat cards -->
        <div class="stats-grid">
          <div class="stat-card gold">
            <div class="stat-label">Jumlah Pengguna</div>
            <div class="stat-val"><?= $stats['total_users'] ?></div>
            <div class="stat-sub">+<?= $stats['new_users_month'] ?> bulan ini</div>
          </div>
          <div class="stat-card green">
            <div class="stat-label">Nilai Transaksi</div>
            <div class="stat-val">
              RM <?= number_format($stats['revenue'], 0) ?>
            </div>
            <div class="stat-sub">
              <?= $stats['txn_counts']['Selesai'] ?? 0 ?> transaksi selesai
            </div>
          </div>
          <div class="stat-card blue">
            <div class="stat-label">Penjual Menunggu</div>
            <div class="stat-val"><?= $stats['pending_sellers'] ?></div>
            <div class="stat-sub">Perlu pengesahan PTU</div>
          </div>
          <div class="stat-card red">
            <div class="stat-label">Aduan Terbuka</div>
            <div class="stat-val"><?= $stats['open_disputes'] ?></div>
            <?php if ($stats['urgent_disputes']): ?>
            <div class="stat-urgent"><?= $stats['urgent_disputes'] ?> mendesak</div>
            <?php endif ?>
            <!-- The urgent count already has its corner chip; repeating it here
                 showed "N mendesak" twice on the same card. -->
            <div class="stat-sub">Menunggu keputusan PTU</div>
          </div>
        </div>

        <!-- Three mini stats -->
        <div class="three-col">
          <div class="card-box" style="text-align:center">
            <div style="font-size:22px;font-weight:600;color:var(--ink)">
              <?= $stats['user_counts']['pembeli'] ?? 0 ?>
            </div>
            <div style="font-size:11px;color:var(--ink3);margin-top:4px">Pembeli Berdaftar</div>
          </div>
          <div class="card-box" style="text-align:center">
            <div style="font-size:22px;font-weight:600;color:var(--ink)">
              <?= $stats['user_counts']['penjual'] ?? 0 ?>
            </div>
            <div style="font-size:11px;color:var(--ink3);margin-top:4px">Penjual Berdaftar</div>
          </div>
          <div class="card-box" style="text-align:center">
            <div style="font-size:22px;font-weight:600;color:var(--ink)">
              <?= ($stats['txn_counts']['Menunggu'] ?? 0) +
                  ($stats['txn_counts']['Disahkan'] ?? 0) ?>
            </div>
            <div style="font-size:11px;color:var(--ink3);margin-top:4px">Pesanan Aktif</div>
          </div>
        </div>

        <!-- Two-col: pending sellers + urgent disputes -->
        <div class="two-col">

          <!-- Pending sellers -->
          <div>
            <div class="section-hd">
              <div class="section-title">Penjual Menunggu Pengesahan</div>
              <button class="btn btn-sm" onclick="showTab('pengesahan')">
                Lihat Semua
              </button>
            </div>
            <?php if (empty($pending_sellers)): ?>
            <div class="empty-state">
              <div style="font-size:28px;opacity:.3;margin-bottom:8px"></div>
              <div style="font-weight:500">Tiada permohonan menunggu</div>
            </div>
            <?php else: ?>
            <div class="tbl-wrap">
              <table class="tbl">
                <thead>
                  <tr>
                    <th>Penjual</th><th>Produk</th><th>Daftar</th><th>Tindakan</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($pending_sellers as $s): ?>
                  <tr id="seller-row-<?= $s['user_id'] ?>">
                    <td>
                      <div style="font-weight:500">
                        <?= htmlspecialchars($s['full_name']) ?>
                      </div>
                      <div style="font-size:11px;color:var(--ink3)">
                        <?= htmlspecialchars($s['ident_no']) ?>
                        <?= $s['faculty'] ? '  '.$s['faculty'] : '' ?>
                        <?= ($s['user_type'] ?? '') === 'staf' ? '  Staf' : '' ?>
                      </div>
                    </td>
                    <td style="font-size:12px"><?= $s['product_count'] ?> produk</td>
                    <td style="font-size:11px;color:var(--ink3)">
                      <?= date('d M', strtotime($s['created_at'])) ?>
                    </td>
                    <td>
                      <div style="display:flex;gap:5px">
                        <button class="btn btn-success btn-sm"
                          onclick="verifySeller(<?= $s['user_id'] ?>,'approve',
                                  '<?= addslashes($s['full_name']) ?>')">
                           Terima
                        </button>
                        <button class="btn btn-danger btn-sm"
                          onclick="verifySeller(<?= $s['user_id'] ?>,'reject',
                                  '<?= addslashes($s['full_name']) ?>')">
                           Tolak
                        </button>
                      </div>
                    </td>
                  </tr>
                  <?php endforeach ?>
                </tbody>
              </table>
            </div>
            <?php endif ?>
          </div>

          <!-- Urgent disputes -->
          <div>
            <div class="section-hd">
              <div class="section-title">Aduan Memerlukan Perhatian</div>
              <button class="btn btn-sm" onclick="showTab('aduan')">Lihat Semua</button>
            </div>
            <?php if (empty($urgent_disputes)): ?>
            <div class="empty-state">
              <div style="font-size:28px;opacity:.3;margin-bottom:8px"></div>
              <div style="font-weight:500">Tiada aduan mendesak</div>
            </div>
            <?php else: ?>
            <?php foreach ($urgent_disputes as $d):
              $sev_class = match($d['severity']) {
                'Mendesak' => 'urgent',
                'Sederhana'=> 'sederhana',
                default    => 'rendah',
              };
            ?>
            <div class="dispute-card <?= $sev_class ?>">
              <div class="dispute-hd">
                <div class="dispute-title"><?= htmlspecialchars($d['title']) ?></div>
                <span class="badge <?= $d['severity']==='Mendesak'?'badge-red':'badge-gold' ?>">
                  <?= $d['severity'] ?>
                </span>
              </div>
              <div class="dispute-meta">
                Pembeli: <?= htmlspecialchars($d['buyer_name']) ?> 
                Penjual: <?= htmlspecialchars($d['seller_name']) ?> 
                RM <?= number_format($d['total_amount'],2) ?> 
                <?= date('d M Y', strtotime($d['created_at'])) ?>
              </div>
              <div class="dispute-footer">
                <button class="btn btn-sm btn-primary"
                  onclick="openDisputeModal(<?= (int)$d['dispute_id'] ?>, <?= (int)$d['transaction_id'] ?>, <?= number_format($d['total_amount'], 2, '.', '') ?>)">
                  Selesaikan
                </button>
              </div>
            </div>
            <?php endforeach ?>
            <?php endif ?>
          </div>
        </div>

        <!-- 🛡 AI Trust Layer — fraud-risk review queue -->
        <div style="margin-top:26px">
          <div class="section-hd">
            <div class="section-title">🛡 Lapisan AI — Semakan Risiko Penipuan</div>
            <button class="btn btn-sm" onclick="loadAiRisks()">Muat Semula</button>
          </div>
          <div class="card-box" id="ai-risk-panel">
            <div style="text-align:center;color:var(--ink3)">
              <span class="spinner"></span> Memuatkan...
            </div>
          </div>
        </div>
      </div><!-- end tab-dash -->


      <!-- 
           TAB: PENGESAHAN PENJUAL
       -->
      <div id="tab-pengesahan" style="display:none">
        <div class="section-hd">
          <div>
            <div style="font-family:'DM Serif Display',serif;font-size:20px">
              Pengesahan Penjual
            </div>
            <div style="font-size:12px;color:var(--ink3);margin-top:2px"
                 id="pengesahan-sub">
              <?= $stats['pending_sellers'] ?> menunggu kelulusan PTU
            </div>
          </div>
        </div>

        <div class="filters">
          <button class="filter-btn active"
                  onclick="loadSellers('pending',this)">
            Menunggu
            <?php if ($stats['pending_sellers']): ?>
            <span style="background:var(--gold);color:var(--ink);
                         font-size:10px;padding:1px 5px;border-radius:8px;
                         margin-left:4px"><?= $stats['pending_sellers'] ?></span>
            <?php endif ?>
          </button>
          <button class="filter-btn" onclick="loadSellers('verified',this)">Disahkan</button>
          <button class="filter-btn" onclick="loadSellers('rejected',this)">Ditolak</button>
          <button class="filter-btn" onclick="loadSellers('all',this)">Semua Penjual</button>
        </div>

        <div class="tbl-wrap">
          <table class="tbl">
            <thead>
              <tr>
                <th>Penjual</th><th>Emel</th><th>Fakulti</th>
                <th>Produk</th><th>Pesanan</th><th>Skor</th>
                <th>Status</th><th>Tindakan</th>
              </tr>
            </thead>
            <tbody id="sellers-tbody">
              <tr>
                <td colspan="8" style="text-align:center;padding:20px;color:var(--ink3)">
                  <span class="spinner"></span> Memuatkan...
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div id="admin-sellers-pagination"></div>
      </div><!-- end tab-pengesahan -->


      <!-- 
           TAB: URUS ADUAN
       -->
      <div id="tab-aduan" style="display:none">
        <div class="section-hd">
          <div style="font-family:'DM Serif Display',serif;font-size:20px">
            Pengurusan Aduan
          </div>
        </div>

        <!-- Dispute summary cards -->
        <div class="three-col" id="dispute-summary">
          <div class="card-box" style="text-align:center">
            <div style="font-size:24px;font-weight:600;color:var(--red)">
              <?= $stats['open_disputes'] ?>
            </div>
            <div style="font-size:11px;color:var(--ink3);margin-top:4px">Aduan Terbuka</div>
          </div>
          <div class="card-box" style="text-align:center">
            <div style="font-size:24px;font-weight:600;color:var(--gold)">
              <?= $stats['urgent_disputes'] ?>
            </div>
            <div style="font-size:11px;color:var(--ink3);margin-top:4px">Mendesak</div>
          </div>
          <div class="card-box" style="text-align:center">
            <div style="font-size:24px;font-weight:600;color:var(--green)">
               <!-- loaded via JS -->
            </div>
            <div style="font-size:11px;color:var(--ink3);margin-top:4px">Selesai Bulan Ini</div>
          </div>
        </div>

        <div class="filters">
          <button class="filter-btn active" onclick="loadDisputes('open',this)">
            Terbuka
          </button>
          <button class="filter-btn" onclick="loadDisputes('review',this)">
            Dalam Semakan
          </button>
          <button class="filter-btn" onclick="loadDisputes('closed',this)">Selesai</button>
          <button class="filter-btn" onclick="loadDisputes('all',this)">Semua</button>
        </div>

        <div id="disputes-list">
          <div class="empty-state">
            <span class="spinner"></span> Memuatkan aduan...
          </div>
        </div>
        <div id="admin-disputes-pagination"></div>
      </div><!-- end tab-aduan -->


      <!-- 
           TAB: SEMUA PENGGUNA
       -->
      <div id="tab-pengguna" style="display:none">
        <div class="section-hd">
          <div style="font-family:'DM Serif Display',serif;font-size:20px">
            Semua Pengguna
          </div>
        </div>

        <div class="filters">
          <button class="filter-btn active" onclick="loadUsers('',this)">Semua</button>
          <button class="filter-btn" onclick="loadUsers('pembeli',this)">Pembeli</button>
          <button class="filter-btn" onclick="loadUsers('penjual',this)">Penjual</button>
          <button class="filter-btn" onclick="loadUsers('pentadbir',this)">Pentadbir</button>
          <input type="text" class="search-input" id="user-search"
                 placeholder="Cari nama / no. matrik atau pegawai..."
                 oninput="searchUsers(this.value)">
        </div>

        <div class="tbl-wrap">
          <table class="tbl">
            <thead>
              <tr>
                <th>Nama</th><th>No. Identiti</th><th>Emel</th>
                <th>Peranan</th><th>Fakulti</th><th>Skor</th>
                <th>Disahkan</th><th>Status Akaun</th><th>Daftar</th><th>Tindakan</th>
              </tr>
            </thead>
            <tbody id="users-tbody">
              <tr>
                <td colspan="10" style="text-align:center;padding:20px;color:var(--ink3)">
                  <span class="spinner"></span> Memuatkan...
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div id="admin-users-pagination"></div>
      </div><!-- end tab-pengguna -->


      <!-- 
           TAB: SEJARAH TRANSAKSI
       -->
      <div id="tab-transaksi" style="display:none">
        <div class="section-hd">
          <div style="font-family:'DM Serif Display',serif;font-size:20px">
            Sejarah Transaksi
          </div>
        </div>

        <!-- Filters -->
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px;align-items:flex-end">
          <div style="flex:1;min-width:150px">
            <label class="form-label" for="txn-search">Cari</label>
            <input type="text" class="search-input" id="txn-search" 
                   placeholder="Nama pembeli/penjual, produk..." 
                   onkeyup="if(event.key==='Enter')loadTransactions('')">
          </div>
          
          <div style="min-width:140px">
            <label class="form-label" for="txn-status-filter">Status</label>
            <select class="form-input" id="txn-status-filter" onchange="loadTransactions('')">
              <option value="">Semua</option>
              <option value="Menunggu">Menunggu</option>
              <option value="Disahkan">Disahkan</option>
              <option value="Selesai">Selesai</option>
              <option value="Dibatalkan">Dibatalkan</option>
            </select>
          </div>
          
          <div style="min-width:140px">
            <label class="form-label" for="txn-payment-filter">Jenis Bayaran</label>
            <select class="form-input" id="txn-payment-filter" onchange="loadTransactions('')">
              <option value="">Semua</option>
              <option value="cash">Tunai</option>
              <option value="online">Dalam Talian</option>
            </select>
          </div>
          
          <div style="min-width:130px">
            <label class="form-label" for="txn-date-from">Dari</label>
            <input type="date" class="form-input" id="txn-date-from" onchange="loadTransactions('')">
          </div>
          
          <div style="min-width:130px">
            <label class="form-label" for="txn-date-to">Hingga</label>
            <input type="date" class="form-input" id="txn-date-to" onchange="loadTransactions('')">
          </div>
          
          <button class="btn btn-sm" onclick="exportTransactionsCSV()" style="height:36px">
            📊 Export CSV
          </button>
        </div>

        <!-- Summary Stats -->
        <div id="txn-summary" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:20px">
          <!-- Stats will be populated by JS -->
        </div>

        <!-- Transaction Table -->
        <div class="tbl-wrap" style="overflow-x:auto">
          <table class="tbl">
            <thead>
              <tr>
                <th style="white-space:nowrap">ID</th>
                <th style="white-space:nowrap">Tarikh</th>
                <th>Pembeli</th>
                <th>Penjual</th>
                <th>Produk</th>
                <th style="text-align:right;white-space:nowrap">Jumlah (RM)</th>
                <th style="white-space:nowrap">Bayaran</th>
                <th style="white-space:nowrap">Status</th>
              </tr>
            </thead>
            <tbody id="transactions-list">
              <tr>
                <td colspan="8" style="text-align:center;padding:40px">
                  <span class="spinner"></span> Memuatkan...
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div id="admin-transactions-pagination"></div>
      </div><!-- end tab-transaksi -->


      <!-- 
           TAB: PULANGAN WANG (REFUNDS)
       -->
      <div id="tab-pulangan" style="display:none">
        <div class="section-hd">
          <div style="font-family:'DM Serif Display',serif;font-size:20px">
            Pulangan Wang
          </div>
          <button class="btn btn-sm" onclick="loadRefunds()">
            🔄 Muat Semula
          </button>
        </div>

        <!-- Filter buttons -->
        <div class="filters" style="margin-bottom:20px">
          <button class="filter-btn active" data-filter="all" onclick="filterRefunds('all')">
            Semua
          </button>
          <button class="filter-btn" data-filter="requested" onclick="filterRefunds('requested')">
            Diminta
          </button>
          <button class="filter-btn" data-filter="processing" onclick="filterRefunds('processing')">
            Dalam Proses
          </button>
          <button class="filter-btn" data-filter="failed" onclick="filterRefunds('failed')">
            Gagal
          </button>
          <button class="filter-btn" data-filter="completed" onclick="filterRefunds('completed')">
            Selesai
          </button>
        </div>

        <!-- Refunds table -->
        <div class="tbl-wrap">
          <table class="tbl">
            <thead>
              <tr>
                <th>Payment ID</th>
                <th>Transaksi</th>
                <th>Pembeli</th>
                <th>Jumlah</th>
                <th>Status Pulangan</th>
                <th>Diminta</th>
                <th>Sebab</th>
                <th>Tindakan</th>
              </tr>
            </thead>
            <tbody id="refunds-table-body">
              <tr>
                <td colspan="8" style="text-align:center;padding:40px;color:var(--ink3)">
                  <span class="spinner"></span> Memuatkan senarai pulangan...
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div id="admin-refunds-pagination"></div>

        <!-- Manual (non-gateway) settlements. A cash order never gets a
             payments row, so it structurally cannot appear in the table above;
             without this list a buyer-favourable ruling on cash had no owner
             and no way to be closed. -->
        <div id="manual-settlements-wrap" style="display:none;margin-top:26px">
          <div class="section-hd" style="margin-bottom:12px">
            <div style="font-family:'DM Serif Display',serif;font-size:16px">
              Pulangan Manual (Tunai / Luar Gateway)
            </div>
            <span id="manual-settlements-count" class="badge badge-gold"></span>
          </div>
          <div class="tbl-wrap">
            <table class="tbl">
              <thead>
                <tr>
                  <th>Dispute ID</th>
                  <th>Transaksi</th>
                  <th>Pembeli</th>
                  <th>Jumlah</th>
                  <th>Kaedah</th>
                  <th>Status</th>
                  <th>Keputusan</th>
                  <th>Tindakan</th>
                </tr>
              </thead>
              <tbody id="manual-settlements-body"></tbody>
            </table>
          </div>
        </div>
      </div><!-- end tab-pulangan -->


      <!-- 
           TAB: PULANGAN BARANG (RETURN SHIPMENTS)
           Same shell/sidebar/topbar as every other admin tab.
           Backend stays in admin_returns.php (AJAX-only).
       -->
      <div id="tab-barang" style="display:none">
        <div class="section-hd">
          <div style="font-family:'DM Serif Display',serif;font-size:20px">
            Pulangan Barang
          </div>
          <button class="btn btn-sm" onclick="loadReturns()">
            🔄 Muat Semula
          </button>
        </div>

        <div class="filters" style="margin-bottom:20px">
          <button class="filter-btn active" data-rstatus="all" onclick="filterReturns(this,'all')">Semua</button>
          <button class="filter-btn" data-rstatus="pending" onclick="filterReturns(this,'pending')">Menunggu (<span data-cnt="pending">0</span>)</button>
          <button class="filter-btn" data-rstatus="in_transit" onclick="filterReturns(this,'in_transit')">Dalam Penghantaran (<span data-cnt="in_transit">0</span>)</button>
          <button class="filter-btn" data-rstatus="received" onclick="filterReturns(this,'received')">Diterima (<span data-cnt="received">0</span>)</button>
        </div>

        <div class="tbl-wrap">
          <table class="tbl">
            <thead>
              <tr>
                <th>ID</th><th>Aduan / Sebab</th><th>Produk</th>
                <th>Nilai</th><th>Pembeli</th><th>Penjual</th>
                <th>Tracking</th><th>Status Pulangan</th><th>Status Pulangan Wang</th>
                <th style="text-align:right">Tindakan</th>
              </tr>
            </thead>
            <tbody id="returns-table-body">
              <tr>
                <td colspan="10" style="text-align:center;padding:40px;color:var(--ink3)">
                  <span class="spinner"></span> Memuatkan senarai pulangan barang...
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="card-box" style="margin-top:20px;font-size:12px;color:var(--ink3)">
          <strong>Panduan:</strong>
          <ul style="margin:8px 0 0 18px;line-height:1.7">
            <li><b>Diterima</b> = admin menanda barang fizikal telah sampai. Seterusnya tekan <b>Sahkan &amp; Pulkinkan</b> untuk lepaskan pulangan wang (rekod akan muncul di tab <b>Pulangan Wang</b>).</li>
            <li><b>Langgarkan</b> = keluar dari proses pulangan barang dan terus pulangkan wang (contoh: barang rosak semasa penghantaran).</li>
            <li>Rekod hanya disenaraikan apabila status <i>pending</i>, <i>in_transit</i> atau <i>received</i>. Rekod <i>verified</i>/<i>cancelled</i> ditapis keluar.</li>
          </ul>
        </div>
      </div><!-- end tab-barang -->


      <!-- 
           TAB: ANALITIK PLATFORM
       -->
      <div id="tab-analitik" style="display:none">
        <div class="section-hd">
          <div style="font-family:'DM Serif Display',serif;font-size:20px">
            Analitik Platform
          </div>
        </div>

        <div class="two-col">
          <!-- Sales by category -->
          <div>
            <div class="section-hd">
              <div class="section-title">Jualan Mengikut Kategori</div>
            </div>
            <div class="card-box" id="cat-chart">
              <div style="text-align:center;color:var(--ink3)">
                <span class="spinner"></span> Memuatkan...
              </div>
            </div>
          </div>

          <!-- Top sellers -->
          <div>
            <div class="section-hd">
              <div class="section-title">5 Penjual Teratas (Skor Kepercayaan)</div>
            </div>
            <div class="card-box" id="top-sellers-chart">
              <div style="text-align:center;color:var(--ink3)">
                <span class="spinner"></span> Memuatkan...
              </div>
            </div>
          </div>
        </div>

        <!-- Transaction trend (30 days) -->
        <div class="section-hd">
          <div class="section-title">Trend Transaksi (30 Hari)</div>
        </div>
        <div class="card-box" id="trend-chart">
          <div style="text-align:center;color:var(--ink3)">
            <span class="spinner"></span> Memuatkan...
          </div>
        </div>

      </div><!-- end tab-analitik -->

    </div><!-- end .content -->
  </div><!-- end .main -->
</div><!-- end .shell -->


<!-- 
     MODAL: RESOLVE DISPUTE
 -->
<div id="modal-dispute" class="modal-bg" style="display:none">
  <div class="modal">
    <div class="modal-title">Selesaikan Aduan</div>
    <div class="modal-sub" id="dispute-modal-sub">Semak maklumat dan tentukan resolusi.</div>
    <input type="hidden" id="dispute-modal-id">

    <div id="dispute-modal-info" style="margin-bottom:16px"></div>

    <div class="divider"></div>

    <div class="form-group">
      <label class="form-label">Keputusan Pentadbir *</label>
      <select class="form-input" id="dispute-resolution" onchange="toggleSettleSection()">
        <option value="Memihak Pembeli">Memihak Pembeli (Aduan Betul - Penjual Dikenakan Penalti -10 Skor)</option>
        <option value="Memihak Penjual">Memihak Penjual (Aduan Palsu/Ditolak - Pembeli Dikenakan Penalti -10 Skor)</option>
        <option value="Neutral">Neutral (Selesai Secara Rundingan - Tiada Penalti Skor)</option>
      </select>
    </div>
    <div class="form-group">
      <label class="form-label">Tindakan</label>
      <select class="form-input" id="dispute-status" onchange="toggleSettleSection()">
        <option value="Selesai">Selesai  Tutup aduan ini</option>
        <option value="Dalam Semakan">Dalam Semakan  Simpan untuk kajian lanjut</option>
      </select>
    </div>

    <!-- Financial settlement (only for a buyer-favourable decision) -->
    <div id="settle-section" style="display:none">
      <div class="divider"></div>
      <div style="font-weight:600;margin-bottom:10px">Penyelesaian Kewangan (Memihak Pembeli)</div>
      <div class="form-group">
        <label class="form-label">Jumlah Pulangan (RM) *</label>
        <input type="number" step="0.01" min="0" class="form-input" id="settle-amount">
        <small style="color:var(--ink3)">Nilai maksimum: <span id="settle-max">0.00</span> (jumlah pesanan)</small>
      </div>
      <div class="form-group">
        <label class="form-label">Barang Perlu Dipulangkan?</label>
        <select class="form-input" id="settle-return">
          <option value="1">Ya - pembeli perlu memulangkan barang</option>
          <option value="0">Tidak - tiada pemulangan barang</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Status Pemenuhan Pesanan</label>
        <select class="form-input" id="settle-fulfilment">
          <option value="Kekal Selesai">Kekal - jangan batalkan pesanan (tiada restock)</option>
          <option value="Dibatalkan">Batalkan pesanan (tanpa auto-stok semula)</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Nota Penyelesaian</label>
        <textarea class="form-input" id="settle-notes" rows="2" placeholder="Contoh: barang rosak, separuh bayaran balik"></textarea>
      </div>
    </div>

    <div class="modal-footer">
      <button class="btn" onclick="closeModal('modal-dispute')">Batal</button>
      <button class="btn btn-primary" id="dispute-submit-btn"
              onclick="submitDisputeResolution()">
        Sahkan Keputusan
      </button>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<!-- 
     JAVASCRIPT
 -->
<script>
//  Tab management 
// NOTE: every entry here MUST have a matching <div id="tab-..."> below.
const TABS  = ['dash','pengesahan','aduan','pengguna','transaksi','pulangan','barang','analitik'];
const TITLES = {
  dash:        'Papan Pemuka',
  pengesahan:  'Pengesahan Penjual',
  aduan:       'Urus Aduan',
  pengguna:    'Semua Pengguna',
  transaksi:   'Sejarah Transaksi',
  pulangan:    'Pulangan Wang',
  barang:      'Pulangan Barang',
  analitik:    'Analitik Platform',
};

// Track which tabs have been loaded to avoid redundant fetches
const _loaded = {};

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

function showTab(name) {
  TABS.forEach(t => {
    // Guard: a TABS entry without a rendered container must not crash the
    // rest of the switch (nav highlight, title, data loading).
    const el = document.getElementById('tab-' + t);
    if (el) el.style.display = t === name ? 'block' : 'none';
  });
  document.querySelectorAll('.nav-item').forEach(el => {
    el.classList.toggle('active',
      el.getAttribute('onclick') === `showTab('${name}')`);
  });
  document.getElementById('topbar-title').textContent = TITLES[name];

  // Lazy load each tab once
  if (name === 'pengesahan' && !_loaded.pengesahan) {
    loadSellers('pending');
    _loaded.pengesahan = true;
  }
  if (name === 'aduan' && !_loaded.aduan) {
    loadDisputes('open');
    _loaded.aduan = true;
  }
  if (name === 'pengguna' && !_loaded.pengguna) {
    loadUsers('');
    _loaded.pengguna = true;
  }
  if (name === 'transaksi' && !_loaded.transaksi) {
    loadTransactions('');
    _loaded.transaksi = true;
  }
  if (name === 'pulangan' && !_loaded.pulangan) {
    loadRefunds();
    _loaded.pulangan = true;
  }
  if (name === 'barang' && !_loaded.barang) {
    loadReturns();
    _loaded.barang = true;
  }
  if (name === 'analitik' && !_loaded.analitik) {
    loadAnalytics();
    _loaded.analitik = true;
  }
}

//  API helper
async function api(action, params = {}, body = null) {
  const url = `?action=${encodeURIComponent(action)}&${new URLSearchParams(params)}`;
  const opts = {
    method: body ? 'POST' : 'GET',
    headers: { 'Accept': 'application/json' },
  };
  if (body) {
    opts.headers['Content-Type'] = 'application/x-www-form-urlencoded';
    opts.body = new URLSearchParams(body);
  }

  try {
    const response = await fetch(url, opts);
    const text = await response.text();
    let data;
    try {
      data = JSON.parse(text);
    } catch (_) {
      throw new Error('Respons pelayan tidak sah.');
    }

    if (response.status === 401 && data.redirect) {
      window.location.href = data.redirect;
      return { success: false, message: 'Sesi tamat.' };
    }
    if (!response.ok && data.success !== false) {
      data.success = false;
    }
    return data;
  } catch (error) {
    showToast('Sambungan ke pelayan gagal. Sila cuba lagi.', 'error');
    return { success: false, message: error.message };
  }
}

//  Verify seller 
async function verifySeller(user_id, action_type) {
  const msg = action_type === 'approve'
    ? 'Terima akaun ini sebagai penjual PTU?'
    : 'Tolak pendaftaran atau tarik balik kelulusan penjual ini?';
  if (!confirm(msg)) return;

  const data = await api('verify_seller', {}, { user_id, action_type });
  showToast(data.message, data.success ? 'success' : 'error');

  if (data.success) {
    // Remove row from pending table
    const row = document.getElementById('seller-row-' + user_id);
    if (row) {
      row.style.transition = 'opacity .3s';
      row.style.opacity    = '0';
      setTimeout(() => row.remove(), 300);
    }
    // Update pending count badge in sidebar
    const sub = document.getElementById('pengesahan-sub');
    if (sub) {
      const cur = parseInt(sub.textContent) || 0;
      sub.textContent = `${Math.max(0,cur-1)} menunggu kelulusan PTU`;
    }
    // Reload sellers table if visible
    if (_loaded.pengesahan) loadSellers(_currentSellerFilter);
  }
}

let _currentSellerFilter = 'pending';
let _currentSellerPage   = 1;

async function loadSellers(filter = _currentSellerFilter, btn = null, page = _currentSellerPage) {
  _currentSellerFilter = filter;
  _currentSellerPage   = page;
  if (btn) {
    document.querySelectorAll('#tab-pengesahan .filter-btn')
      .forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    _currentSellerPage = 1;
    page = 1;
  }

  const tbody = document.getElementById('sellers-tbody');
  if (tbody) {
    tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:20px;
      color:var(--ink3)"><span class="spinner"></span> Memuatkan...</td></tr>`;
  }

  const data = await api('get_sellers', { filter, page, per_page: 15 });
  if (!data.success || !tbody) return;

  if (!data.sellers.length) {
    tbody.innerHTML = `<tr><td colspan="8"><div class="empty-state">
      <div style="font-size:28px;opacity:.3;margin-bottom:8px"></div>
      <div style="font-weight:500">Tiada penjual dalam kategori ini</div>
    </div></td></tr>`;
    renderPagination('admin-sellers-pagination', 0, 0, 0, 'goToAdminSellersPage', 'penjual');
    return;
  }

  tbody.innerHTML = data.sellers.map(s => {
    const status = s.verification_status;
    let badgeClass = 'badge-gold';
    let badgeLabel = 'Menunggu';
    if (status === 'Disahkan') {
      badgeClass = 'badge-green';
      badgeLabel = '✓ Disahkan';
    } else if (status === 'Ditolak') {
      badgeClass = 'badge-red';
      badgeLabel = 'Ditolak';
    }

    let actions = '';
    if (status === 'Menunggu') {
      actions = `<button class="btn btn-success btn-sm"
                   onclick="verifySeller(${s.user_id},'approve')">
                   ✓ Terima
                 </button>
                 <button class="btn btn-danger btn-sm"
                   onclick="verifySeller(${s.user_id},'reject')">
                   ✕ Tolak
                 </button>`;
    } else if (status === 'Disahkan') {
      actions = `<button class="btn btn-sm btn-danger"
                   onclick="verifySeller(${s.user_id},'reject')">
                   Tarik Balik
                 </button>`;
    } else if (status === 'Ditolak') {
      actions = `<button class="btn btn-success btn-sm"
                   onclick="verifySeller(${s.user_id},'approve')">
                   Terima Semula
                 </button>`;
    }

    return `<tr id="seller-row-${s.user_id}">
      <td>
        <div style="font-weight:500">${esc(s.full_name)}</div>
        <div style="font-size:11px;color:var(--ink3)">${esc(s.ident_no)}${s.user_type === 'staf' ? ' · Staf' : ''}</div>
      </td>
      <td style="font-size:12px">${esc(s.email)}</td>
      <td style="font-size:12px">${esc(s.faculty || '—')}</td>
      <td>${s.product_count}</td>
      <td>${s.order_count}</td>
      <td>
        <span style="font-weight:500;color:${trustColor(s.trust_score)}">
          ${parseFloat(s.trust_score).toFixed(0)}
        </span>
      </td>
      <td>
        <span class="badge ${badgeClass}">
          ${badgeLabel}
        </span>
      </td>
      <td>
        <div style="display:flex;gap:5px">
          ${actions}
        </div>
      </td>
    </tr>`;
  }).join('');

  if (data.pagination) {
    renderPagination(
      'admin-sellers-pagination',
      data.pagination.page,
      data.pagination.total_pages,
      data.pagination.total,
      'goToAdminSellersPage',
      'penjual'
    );
  }
}

function goToAdminSellersPage(page) {
  loadSellers(_currentSellerFilter, null, page);
}

//  Disputes 
let _currentDisputeFilter = 'open';
let _currentDisputePage   = 1;

async function loadDisputes(filter = _currentDisputeFilter, btn = null, page = _currentDisputePage) {
  _currentDisputeFilter = filter;
  _currentDisputePage   = page;
  if (btn) {
    document.querySelectorAll('#tab-aduan .filter-btn')
      .forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    _currentDisputePage = 1;
    page = 1;
  }

  const list = document.getElementById('disputes-list');
  list.innerHTML = `<div class="empty-state">
    <span class="spinner"></span> Memuatkan aduan...</div>`;

  const data = await api('get_disputes', { filter, page, per_page: 10 });
  if (!data.success) return;

  if (!data.disputes.length) {
    list.innerHTML = `<div class="empty-state">
      <div style="font-size:32px;opacity:.3;margin-bottom:10px"></div>
      <div style="font-weight:500">Tiada aduan dalam kategori ini</div>
    </div>`;
    renderPagination('admin-disputes-pagination', 0, 0, 0, 'goToAdminDisputesPage', 'aduan');
    return;
  }

  list.innerHTML = data.disputes.map(d => {
    const closed    = d.status === 'Selesai';
    const sevClass  = {Mendesak:'urgent',Sederhana:'sederhana',Rendah:'rendah'}[d.severity]||'';
    const sevBadge  = d.severity === 'Mendesak' ? 'badge-red' : 'badge-gold';
    const stsBadge  = {Terbuka:'badge-red','Dalam Semakan':'badge-gold',Selesai:'badge-green'}
                      [d.status] || 'badge-gray';

    return `
    <div class="dispute-card ${sevClass}" id="dispute-card-${d.dispute_id}"
         style="${closed?'opacity:.7':''}">
      <div class="dispute-hd">
        <div>
          <div class="dispute-title">${esc(d.title)}</div>
          <div style="font-size:11px;color:var(--ink3);margin-top:2px">
            #${d.dispute_id}  Pesanan #${d.transaction_id}
          </div>
        </div>
        <div style="display:flex;gap:6px;align-items:center;flex-shrink:0">
          <span class="badge ${sevBadge}">${d.severity}</span>
          <span class="badge ${stsBadge}">${d.status}</span>
        </div>
      </div>
      <div class="dispute-meta">
        <b>Pembeli:</b> ${esc(d.buyer_name)} (${esc(d.buyer_ident)}) 
        <b>Penjual:</b> ${esc(d.seller_name)} (${esc(d.seller_ident)}) 
        <b>Produk:</b> ${esc(d.product_name)} 
        <b>Nilai:</b> RM ${parseFloat(d.total_amount).toFixed(2)} 
        <b>Difailkan:</b> ${formatDate(d.created_at)}
        ${d.resolution
          ? `  <b>Resolusi:</b> <span style="color:var(--green)">${d.resolution}</span>
               Oleh ${esc(d.resolved_by_name||'')}` : ''}
      </div>
      <div style="font-size:12px;color:var(--ink2);line-height:1.6;
                  margin-bottom:10px;padding:10px;background:var(--cream);
                  border-radius:var(--r-sm)">
        ${esc(d.description)}
      </div>
      <div class="dispute-footer">
        ${!closed
          ? `<button class="btn btn-sm btn-primary"
               onclick="openDisputeModal(${d.dispute_id}, ${d.transaction_id}, ${parseFloat(d.total_amount).toFixed(2)})">
               Selesaikan / Kemaskini
             </button>
             <button class="btn btn-sm btn-info"
               onclick="setDisputeReview(${d.dispute_id})">
               Tandakan Dalam Semakan
             </button>`
          : `<span style="font-size:11px;color:var(--ink4)">
               Diselesaikan ${d.resolved_at ? formatDate(d.resolved_at) : ''}
               oleh ${esc(d.resolved_by_name||'PTU')}
             </span>`
        }
      </div>
    </div>`;
  }).join('');

  if (data.pagination) {
    renderPagination(
      'admin-disputes-pagination',
      data.pagination.page,
      data.pagination.total_pages,
      data.pagination.total,
      'goToAdminDisputesPage',
      'aduan'
    );
  }
}

function goToAdminDisputesPage(page) {
  loadDisputes(_currentDisputeFilter, null, page);
}

let _disputeTotal = 0;

function openDisputeModal(dispute_id, transaction_id, total) {
  document.getElementById('dispute-modal-id').value = dispute_id;
  // A missing/invalid `total` must never become a 0.00 ceiling: resolve_dispute
  // clamps the refund to min(order_total, submitted), so a silent zero would
  // record a RM0.00 refund against a real order. Unknown value => leave the
  // field blank, which the server treats as "refund the full order amount".
  const parsed = parseFloat(total);
  _disputeTotal = (isFinite(parsed) && parsed > 0) ? parsed : 0;
  const orderTxt = transaction_id ? `  Pesanan #${transaction_id}` : '';
  document.getElementById('dispute-modal-sub').textContent =
    `Aduan #${dispute_id}${orderTxt}  pilih keputusan dan tindakan`;
  // Reset/pre-fill the settlement form against the order value.
  const amt = document.getElementById('settle-amount');
  const maxEl = document.getElementById('settle-max');
  if (_disputeTotal > 0) {
    amt.max = _disputeTotal.toFixed(2);
    amt.value = _disputeTotal.toFixed(2);
    amt.placeholder = '';
    maxEl.textContent = _disputeTotal.toFixed(2);
  } else {
    amt.removeAttribute('max');
    amt.value = '';
    amt.placeholder = 'kosong = jumlah penuh pesanan';
    maxEl.textContent = 'jumlah penuh pesanan';
  }
  document.getElementById('settle-return').value = '1';
  document.getElementById('settle-fulfilment').value = 'Kekal Selesai';
  document.getElementById('settle-notes').value = '';
  toggleSettleSection();
  document.getElementById('modal-dispute').style.display = 'flex';
}

// The settlement block only applies to a final buyer-favourable decision.
function toggleSettleSection() {
  const res = document.getElementById('dispute-resolution').value;
  const sts = document.getElementById('dispute-status').value;
  const show = (res === 'Memihak Pembeli' && sts === 'Selesai');
  document.getElementById('settle-section').style.display = show ? 'block' : 'none';
}

async function setDisputeReview(dispute_id) {
  const data = await api('resolve_dispute', {}, {
    dispute_id,
    resolution: 'Neutral',
    status:     'Dalam Semakan',
  });
  showToast(data.message, data.success ? 'success' : 'error');
  if (data.success) loadDisputes(_currentDisputeFilter);
}

async function submitDisputeResolution() {
  const btn = document.getElementById('dispute-submit-btn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span>Menyimpan...';

  const res = document.getElementById('dispute-resolution').value;
  const sts = document.getElementById('dispute-status').value;
  const payload = {
    dispute_id: document.getElementById('dispute-modal-id').value,
    resolution: res,
    status:     sts,
  };
  // Attach the explicit settlement decision for a buyer-favourable closure.
  if (res === 'Memihak Pembeli' && sts === 'Selesai') {
    const amtEl = document.getElementById('settle-amount');
    // These inputs are not inside a <form>, so the browser's native max= bubble
    // never blocks this click: an over-max value would sail through and be
    // silently clamped by the server, leaving the admin certain their number was
    // recorded. Reject it here and say why. Skipped when the ceiling is unknown
    // (_disputeTotal = 0) because the field is then deliberately blank.
    if (_disputeTotal > 0 && amtEl.value !== '' && parseFloat(amtEl.value) > _disputeTotal + 0.005) {
      showToast(`Jumlah pulangan tidak boleh melebihi nilai pesanan (RM ${_disputeTotal.toFixed(2)})`, 'error');
      btn.disabled = false;
      btn.textContent = 'Sahkan Keputusan';
      return;
    }
    payload.settle_refund_amount   = amtEl.value;
    payload.settle_return_required = document.getElementById('settle-return').value;
    payload.settle_fulfilment      = document.getElementById('settle-fulfilment').value;
    payload.settle_notes           = document.getElementById('settle-notes').value;
  }

  const data = await api('resolve_dispute', {}, payload);

  showToast(data.message, data.success ? 'success' : 'error');

  if (data.success) {
    closeModal('modal-dispute');
    loadDisputes(_currentDisputeFilter);
  }

  btn.disabled = false;
  btn.textContent = 'Sahkan Keputusan';
}

//  Users 
let _currentRoleFilter = '';
let _currentUserPage   = 1;
let _userSearchTimer   = null;

async function loadUsers(role = _currentRoleFilter, btn = null, page = _currentUserPage) {
  _currentRoleFilter = role;
  _currentUserPage   = page;
  if (btn) {
    document.querySelectorAll('#tab-pengguna .filter-btn')
      .forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    _currentUserPage = 1;
    page = 1;
  }

  const search = document.getElementById('user-search')?.value || '';
  const tbody  = document.getElementById('users-tbody');
  tbody.innerHTML = `<tr><td colspan="10" style="text-align:center;padding:20px">
    <span class="spinner"></span><span class="tm-sr-only">Memuatkan pengguna...</span></td></tr>`;

  const data = await api('get_users', { role, search, page, per_page: 15 });
  if (!data.success) return;

  if (!data.users.length) {
    tbody.innerHTML = `<tr><td colspan="10"><div class="empty-state">
      <div style="font-size:28px;opacity:.3;margin-bottom:8px">👥</div>
      <div style="font-weight:500">Tiada pengguna dijumpai</div>
    </div></td></tr>`;
    renderPagination('admin-users-pagination', 0, 0, 0, 'goToAdminUsersPage', 'pengguna');
    return;
  }

  tbody.innerHTML = data.users.map(u => {
    const roleBadge = {pembeli:'badge-blue',penjual:'badge-gold',pentadbir:'badge-green'}
                      [u.role] || 'badge-gray';
    return `<tr>
      <td>
        <div style="font-weight:500">${esc(u.full_name)}</div>
      </td>
      <td style="font-size:12px">${esc(u.ident_no)}
        ${u.user_type === 'staf' ? '<div style="font-size:10px;color:var(--ink3)">Staf UTHM</div>' : ''}
      </td>
      <td style="font-size:12px;color:var(--ink3)">${esc(u.email)}</td>
      <td><span class="badge ${roleBadge}">${u.role}</span></td>
      <td style="font-size:12px">${esc(u.faculty || '—')}</td>
      <td>
        ${u.role === 'penjual'
          ? `<span style="font-weight:500;color:${trustColor(u.trust_score)}">
               ${parseFloat(u.trust_score).toFixed(0)}
             </span>`
          : '<span style="color:var(--ink4)"></span>'}
      </td>
      <td>
        ${u.role === 'penjual'
          ? `<span class="badge ${parseInt(u.is_verified)?'badge-green':'badge-gold'}">
               ${parseInt(u.is_verified) ? '✓ Disahkan' : 'Menunggu'}
             </span>`
          : '<span style="color:var(--ink4)">—</span>'}
      </td>
      <td>
        <span class="badge ${parseInt(u.is_active) ? 'badge-green' : 'badge-red'}">
          ${parseInt(u.is_active) ? 'Aktif' : 'Digantung'}
        </span>
      </td>
      <td style="font-size:11px;color:var(--ink3)">
        ${formatDate(u.created_at)}
      </td>
      <td>
        ${u.role === 'pentadbir'
          ? '<span style="color:var(--ink4)">—</span>'
          : `<button type="button" class="btn btn-sm ${parseInt(u.is_active) ? 'btn-danger' : 'btn-success'}"
                     onclick="toggleUserStatus(${parseInt(u.user_id)}, ${parseInt(u.is_active)})">
               ${parseInt(u.is_active) ? 'Gantung' : 'Aktifkan'}
             </button>`}
      </td>
    </tr>`;
  }).join('');

  if (data.pagination) {
    renderPagination(
      'admin-users-pagination',
      data.pagination.page,
      data.pagination.total_pages,
      data.pagination.total,
      'goToAdminUsersPage',
      'pengguna'
    );
  }
}

function goToAdminUsersPage(page) {
  loadUsers(_currentRoleFilter, null, page);
}

async function toggleUserStatus(userId, isActive) {
  const action = isActive ? 'menggantung' : 'mengaktifkan semula';
  if (!confirm(`Anda pasti mahu ${action} akaun ini?`)) return;

  try {
    const data = await api('toggle_user_status', {}, { user_id: userId });
    showToast(data.message || 'Status akaun dikemaskini.', data.success ? 'success' : 'error');
    if (data.success) {
      loadUsers(_currentRoleFilter, null, _currentUserPage);
    }
  } catch (error) {
    showToast('Status akaun tidak dapat dikemaskini. Sila cuba lagi.', 'error');
  }
}

function searchUsers(q) {
  clearTimeout(_userSearchTimer);
  _userSearchTimer = setTimeout(() => {
    _currentUserPage = 1;
    loadUsers(_currentRoleFilter, null, 1);
  }, 350);
}

//  Analytics 
async function loadAnalytics() {
  const data = await api('get_analytics');
  if (!data.success) return;

  // Category bar chart
  const catEl  = document.getElementById('cat-chart');
  const maxRev = Math.max(...data.by_cat.map(r => parseFloat(r.revenue)||0), 1);
  catEl.innerHTML = data.by_cat.length
    ? data.by_cat.map(r => `
        <div class="bar-row">
          <div class="bar-label">${esc(r.category)}</div>
          <div class="bar-track">
            <div class="bar-fill"
                 style="width:${(parseFloat(r.revenue)/maxRev*100).toFixed(1)}%"></div>
          </div>
          <div class="bar-val">RM ${Math.round(parseFloat(r.revenue)).toLocaleString('ms-MY')}</div>
        </div>`).join('')
    : '<div class="empty-state" style="padding:16px">Tiada data jualan</div>';

  // Top sellers bar chart
  const selEl  = document.getElementById('top-sellers-chart');
  selEl.innerHTML = data.top_sellers.length
    ? data.top_sellers.map(s => `
        <div class="bar-row">
          <div class="bar-label" title="${esc(s.full_name)}">
            ${parseInt(s.is_verified) ? ' ' : ''}${esc(s.full_name)}
          </div>
          <div class="bar-track">
            <div class="bar-fill"
                 style="width:${parseFloat(s.trust_score).toFixed(0)}%;
                        background:${trustColor(s.trust_score)}"></div>
          </div>
          <div class="bar-val"
               style="color:${trustColor(s.trust_score)}">
            ${parseFloat(s.trust_score).toFixed(0)}
          </div>
        </div>`).join('')
    : '<div class="empty-state" style="padding:16px">Tiada data penjual</div>';

  // 30-day transaction trend (CSS sparkline)
  const trendEl = document.getElementById('trend-chart');
  if (!data.txn_trend.length) {
    trendEl.innerHTML = '<div class="empty-state">Tiada data transaksi</div>';
    return;
  }

  const maxOrds = Math.max(...data.txn_trend.map(r => parseInt(r.orders)||0), 1);

  trendEl.innerHTML = `
    <div style="display:flex;align-items:flex-end;gap:3px;height:64px;margin-bottom:6px">
      ${data.txn_trend.map((r,i) => {
        const h    = Math.max(4, (parseInt(r.orders)/maxOrds*100));
        const isToday = i === data.txn_trend.length - 1;
        return `<div title="${r.day}: ${r.orders} pesanan, RM ${Math.round(parseFloat(r.revenue))}"
                     style="flex:1;border-radius:2px 2px 0 0;height:${h}%;
                            background:${isToday?'var(--gold)':'var(--gold-light)'};
                            cursor:default;transition:.3s"></div>`;
      }).join('')}
    </div>
    <div style="display:flex;justify-content:space-between;
                font-size:10px;color:var(--ink4)">
      <span>${data.txn_trend[0]?.day?.slice(5) || ''}</span>
      <span>${data.txn_trend[Math.floor(data.txn_trend.length/2)]?.day?.slice(5) || ''}</span>
      <span>Hari ini</span>
    </div>
    <div class="divider"></div>
    <div style="display:flex;justify-content:space-between">
      <div>
        <div style="font-size:10px;color:var(--ink3)">Jumlah pesanan (30 hari)</div>
        <div style="font-size:16px;font-weight:600">
          ${data.txn_trend.reduce((s,r)=>s+parseInt(r.orders),0)}
        </div>
      </div>
      <div style="text-align:right">
        <div style="font-size:10px;color:var(--ink3)">Jumlah nilai (30 hari)</div>
        <div style="font-size:16px;font-weight:600">
          RM ${data.txn_trend.reduce((s,r)=>s+parseFloat(r.revenue),0)
               .toLocaleString('ms-MY',{maximumFractionDigits:0})}
        </div>
      </div>
    </div>`;
}

//  Utilities 
function esc(str) {
  return String(str ?? '')
    .replace(/&/g,'&amp;')
    .replace(/</g,'&lt;')
    .replace(/>/g,'&gt;')
    .replace(/"/g,'&quot;')
    .replace(/'/g,'&#39;');
}

// escapeHtml is referenced by the transaction row renderer; alias it to the
// page's existing safe esc() so a non-empty result no longer throws ReferenceError.
function escapeHtml(value) { return esc(value); }

function trustColor(s) {
  s = parseFloat(s);
  return s >= 85 ? 'var(--green)' : s >= 70 ? 'var(--gold)' : 'var(--red)';
}

function formatDate(str) {
  if (!str) return '';
  return new Date(str).toLocaleDateString('ms-MY',
    {day:'2-digit', month:'short', year:'numeric'});
}

function closeModal(id) {
  document.getElementById(id).style.display = 'none';
}

document.getElementById('modal-dispute').addEventListener('click', function(e) {
  if (e.target === this) closeModal('modal-dispute');
});

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') closeModal('modal-dispute');
});

function showToast(msg, type = '') {
  const t = document.getElementById('toast');
  t.textContent = msg;
  t.className   = `toast show ${type}`;
  setTimeout(() => t.classList.remove('show'), 3200);
}

// ── Transaction History Functions ─────────────────────────────
let _currentTxnPage = 1;

async function loadTransactions(dummy = '', page = _currentTxnPage) {
  _currentTxnPage = page;
  
  const status = document.getElementById('txn-status-filter').value;
  const paymentType = document.getElementById('txn-payment-filter').value;
  const dateFrom = document.getElementById('txn-date-from').value;
  const dateTo = document.getElementById('txn-date-to').value;
  const search = document.getElementById('txn-search').value.trim();
  
  const params = new URLSearchParams({
    action: 'get_transactions',
    status: status,
    payment_type: paymentType,
    date_from: dateFrom,
    date_to: dateTo,
    search: search,
    page: page,
    per_page: 20
  });
  
  const tbody = document.getElementById('transactions-list');
  tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:20px">Memuatkan...</td></tr>`;
  
  try {
    const res = await fetch(`?${params.toString()}`);
    const data = await res.json();
    
    if (!data.success) throw new Error(data.message || 'Ralat');
    
    // Update summary
    const summaryEl = document.getElementById('txn-summary');
    summaryEl.innerHTML = `
      <div class="card-box" style="text-align:center">
        <div style="font-size:18px;font-weight:600;color:var(--ink)">${data.summary.total_count || 0}</div>
        <div style="font-size:11px;color:var(--ink3);margin-top:4px">Jumlah Transaksi</div>
      </div>
      <div class="card-box" style="text-align:center">
        <div style="font-size:18px;font-weight:600;color:var(--green)">RM ${parseFloat(data.summary.total_amount || 0).toFixed(2)}</div>
        <div style="font-size:11px;color:var(--ink3);margin-top:4px">Jumlah Nilai</div>
      </div>
      <div class="card-box" style="text-align:center">
        <div style="font-size:18px;font-weight:600;color:var(--blue)">RM ${parseFloat(data.summary.completed_amount || 0).toFixed(2)}</div>
        <div style="font-size:11px;color:var(--ink3);margin-top:4px">Selesai</div>
      </div>
      <div class="card-box" style="text-align:center">
        <div style="font-size:18px;font-weight:600;color:var(--gold)">RM ${parseFloat(data.summary.online_paid_amount || 0).toFixed(2)}</div>
        <div style="font-size:11px;color:var(--ink3);margin-top:4px">Bayaran Online</div>
      </div>
    `;
    
    if (data.transactions.length === 0) {
      tbody.innerHTML = `<tr><td colspan="8"><div class="empty-state">
        <div style="font-size:28px;opacity:.3;margin-bottom:8px">📊</div>
        <div style="font-weight:500">Tiada transaksi dijumpai</div>
      </div></td></tr>`;
      renderPagination('admin-transactions-pagination', 0, 0, 0, 'goToTxnPage', 'transaksi');
      return;
    }
    
    tbody.innerHTML = data.transactions.map(t => {
      const statusBadge = {
        'Menunggu': 'badge-gold',
        'Disahkan': 'badge-blue',
        'Selesai': 'badge-green',
        'Dibatalkan': 'badge-red'
      }[t.status] || 'badge-gray';
      
      const paymentBadge = t.payment_type === 'online' 
        ? '<span style="white-space:nowrap;font-size:10px;background:var(--blue-bg);color:var(--blue);padding:2px 8px;border-radius:10px">💳 Online</span>'
        : '<span style="white-space:nowrap;font-size:10px;background:var(--cream2);color:var(--ink3);padding:2px 8px;border-radius:10px">💵 Tunai</span>';
      
      return `
        <tr>
          <td>#${t.transaction_id}</td>
          <td style="font-size:12px;white-space:nowrap">${new Date(t.created_at).toLocaleDateString('ms-MY', {day:'2-digit',month:'short',year:'numeric'})}</td>
          <td>
            <div style="font-weight:500;font-size:13px">${escapeHtml(t.buyer_name)}</div>
            <div style="font-size:11px;color:var(--ink3)">${escapeHtml(t.buyer_ident)}</div>
          </td>
          <td>
            <div style="font-weight:500;font-size:13px">${escapeHtml(t.seller_name)}</div>
            <div style="font-size:11px;color:var(--ink3)">${escapeHtml(t.seller_ident)}</div>
          </td>
          <td>
            <div style="font-weight:500;font-size:13px">${escapeHtml(t.product_name)}</div>
            <div style="font-size:11px;color:var(--ink3)">${escapeHtml(t.category)}</div>
          </td>
          <td style="text-align:right;font-weight:600;white-space:nowrap;font-variant-numeric:tabular-nums">RM&nbsp;${parseFloat(t.total_amount).toFixed(2)}</td>
          <td style="white-space:nowrap;text-align:center">${paymentBadge}</td>
          <td style="white-space:nowrap">
            <span class="badge ${statusBadge}">
              <span class="badge-dot"></span>${t.status}
            </span>
          </td>
        </tr>
      `;
    }).join('');
    
    renderPagination(
      'admin-transactions-pagination',
      data.pagination.page,
      data.pagination.total_pages,
      data.pagination.total,
      'goToTxnPage',
      'transaksi'
    );
    
  } catch (err) {
    tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:20px;color:var(--red)">Ralat: ${err.message}</td></tr>`;
  }
}

function goToTxnPage(page) {
  loadTransactions('', page);
}

// ── Refund (Pulangan Wang) management ─────────────────────────
let _currentRefundFilter = 'all';
let _currentRefundPage   = 1;

// Map a filter button to the refund_status the endpoint expects ('' = all).
function refundFilterToStatus(filter) {
  return filter === 'all' ? '' : filter;
}

function filterRefunds(filter) {
  _currentRefundFilter = filter;
  _currentRefundPage   = 1;
  document.querySelectorAll('#tab-pulangan .filter-btn').forEach(b => {
    b.classList.toggle('active', b.dataset.filter === filter);
  });
  loadRefunds();
}

async function loadRefunds(page = _currentRefundPage) {
  _currentRefundPage = page;
  const tbody = document.getElementById('refunds-table-body');
  if (!tbody) return;
  tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:20px;
    color:var(--ink3)"><span class="spinner"></span> Memuatkan senarai pulangan...</td></tr>`;

  const data = await api('get_refunds', {
    status: refundFilterToStatus(_currentRefundFilter),
    page,
    per_page: 20,
  });
  if (!data.success) {
    tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:20px;color:var(--red)">
     Ralat memuatkan pulangan.</td></tr>`;
    return;
  }
  renderRefunds(data.refunds || [], tbody);
  renderManualSettlements(data.manual_settlements || []);

  if (data.pagination) {
    renderPagination(
      'admin-refunds-pagination',
      data.pagination.page,
      data.pagination.total_pages,
      data.pagination.total,
      'goToRefundsPage',
      'pulangan'
    );
  }
}

function goToRefundsPage(page) {
  loadRefunds(page);
}

function renderRefunds(refunds, tbody) {
  if (!refunds.length) {
    tbody.innerHTML = `<tr><td colspan="8"><div class="empty-state">
      <div style="font-size:28px;opacity:.3;margin-bottom:8px">💵</div>
      <div style="font-weight:500">Tiada rekod pulangan dalam kategori ini</div>
    </div></td></tr>`;
    return;
  }

  // refund_status -> [badgeClass, label]
  // 'pending_return' is intentionally absent: those rows live on the
  // separate "Pulangan Barang" page (admin_returns.php), not here.
  const badge = {
    'requested':      ['badge-gold',   'Diminta'],
    'processing':     ['badge-blue',   'Dalam Proses'],
    'completed':      ['badge-green',  'Selesai'],
    'failed':         ['badge-red',    'Gagal'],
  };

  tbody.innerHTML = refunds.map(r => {
    const rs = r.refund_status;
    const [bClass, bLabel] = badge[rs] || ['badge-gray', rs];
    const pid = parseInt(r.payment_id, 10);

    // Per-row actions. Completed refunds need none.
    let actions = '<span style="font-size:11px;color:var(--ink4)">—</span>';
    if (rs !== 'completed') {
      const parts = [];
      parts.push(`<button class="btn btn-sm" onclick="retryRefund(${pid})">↻ Cuba Semula</button>`);
      if (['failed', 'requested', 'processing'].includes(rs)) {
        parts.push(`<button class="btn btn-sm btn-success" onclick="markRefundCompleted(${pid})">Tanda Selesai</button>`);
      }
      if (parts.length) {
        actions = `<div style="display:flex;gap:5px;flex-wrap:wrap">${parts.join('')}</div>`;
      }
    }

    return `<tr>
      <td>#${r.payment_id}</td>
      <td>
        <div style="font-weight:500;font-size:13px">#${r.transaction_id}</div>
        <div style="font-size:11px;color:var(--ink3)">${esc(r.product_name || '—')}</div>
      </td>
      <td>
        <div style="font-weight:500;font-size:13px">${esc(r.buyer_name)}</div>
        <div style="font-size:11px;color:var(--ink3)">${esc(r.buyer_email || '')}</div>
      </td>
      <td style="text-align:right;font-weight:600;white-space:nowrap;font-variant-numeric:tabular-nums">RM&nbsp;${parseFloat(r.amount).toFixed(2)}</td>
      <td style="white-space:nowrap"><span class="badge ${bClass}">${bLabel}</span></td>
      <td style="font-size:12px;white-space:nowrap">${r.refund_requested_at ? formatDate(r.refund_requested_at) : '—'}</td>
      <td style="font-size:11px;color:var(--ink3);max-width:220px">${esc(r.refund_error || '—')}</td>
      <td>${actions}</td>
    </tr>`;
  }).join('');
}

async function retryRefund(payment_id) {
  if (!confirm('Cuba semula pemprosesan pulangan untuk pembayaran #' + payment_id + '?')) return;
  const data = await api('retry_refund', {}, { payment_id });
  showToast(data.message || (data.success ? 'Pulangan diproses' : 'Pulangan gagal diproses'),
    data.success ? 'success' : 'error');
  if (data.success) loadRefunds();
}

async function markRefundCompleted(payment_id) {
  if (!confirm('Tanda pulangan untuk pembayaran #' + payment_id + ' sebagai selesai secara manual?')) return;
  const data = await api('mark_refund_completed', {}, {
    payment_id,
    notes: 'Ditanda selesai manual oleh pentadbir',
  });
  showToast(data.message || (data.success ? 'Pulangan ditanda selesai' : 'Gagal'),
    data.success ? 'success' : 'error');
  if (data.success) loadRefunds();
}

// Pulangan manual: rulings on orders the gateway never took money for (cash).
// Kept in its own table so the gateway list, its filters and its pagination
// above are untouched.
function renderManualSettlements(rows) {
  const wrap  = document.getElementById('manual-settlements-wrap');
  const tbody = document.getElementById('manual-settlements-body');
  const count = document.getElementById('manual-settlements-count');
  if (!wrap || !tbody) return;

  if (!rows.length) {
    wrap.style.display = 'none';
    tbody.innerHTML = '';
    return;
  }
  wrap.style.display = 'block';

  const pending = rows.filter(r => r.settlement_status === 'queued').length;
  if (count) {
    count.textContent = pending ? pending + ' perlu diselesaikan' : 'Tiada tertunggak';
    count.className = 'badge ' + (pending ? 'badge-gold' : 'badge-green');
  }

  tbody.innerHTML = rows.map(r => {
    const queued = r.settlement_status === 'queued';
    const amount = parseFloat(r.settlement_refund_amount != null
      ? r.settlement_refund_amount : (r.total_amount || 0));
    const bClass = queued ? 'badge-gold' : 'badge-green';
    const bLabel = queued ? 'Perlu Selesaikan' : 'Selesai';
    const actions = queued
      ? `<button class="btn btn-sm btn-success" onclick="completeManualSettlement(${parseInt(r.dispute_id, 10)})">✓ Tanda Diserah</button>`
      : '<span style="font-size:11px;color:var(--ink4)">—</span>';
    const retBadge = String(r.settlement_return_required) === '1'
      ? ' <span class="badge badge-blue">Pulangan barang</span>' : '';

    return `<tr>
      <td>#${r.dispute_id}</td>
      <td>
        <div style="font-weight:500;font-size:13px">#${r.transaction_id}</div>
        <div style="font-size:11px;color:var(--ink3)">${esc(r.product_name || '—')} · ${esc(r.order_status || '')}</div>
      </td>
      <td>
        <div style="font-weight:500;font-size:13px">${esc(r.buyer_name)}</div>
        <div style="font-size:11px;color:var(--ink3)">${esc(r.buyer_email || '')}</div>
      </td>
      <td style="text-align:right;font-weight:600;white-space:nowrap;font-variant-numeric:tabular-nums">RM&nbsp;${isNaN(amount) ? '0.00' : amount.toFixed(2)}</td>
      <td style="font-size:12px;white-space:nowrap">${r.payment_type === 'cash' ? 'Tunai' : esc(r.payment_type || '—')}</td>
      <td style="white-space:nowrap"><span class="badge ${bClass}">${bLabel}</span>${retBadge}</td>
      <td style="font-size:11px;color:var(--ink3);max-width:220px">${esc(r.settlement_notes || '—')}</td>
      <td>${actions}</td>
    </tr>`;
  }).join('');
}

async function completeManualSettlement(dispute_id) {
  if (!confirm('Sahkan PTU telah menyerahkan pulangan untuk pertikaian #' + dispute_id + '? Tindakan ini menutup settlement.')) return;
  const data = await api('complete_manual_settlement', {}, {
    dispute_id,
    notes: 'Pulangan diserah kepada pembeli oleh PTU',
  });
  showToast(data.message || (data.success ? 'Pulangan manual ditutup' : 'Gagal'),
    data.success ? 'success' : 'error');
  if (data.success) loadRefunds();
}

async function exportTransactionsCSV() {
  const status = document.getElementById('txn-status-filter').value;
  const paymentType = document.getElementById('txn-payment-filter').value;
  const dateFrom = document.getElementById('txn-date-from').value;
  const dateTo = document.getElementById('txn-date-to').value;
  const search = document.getElementById('txn-search').value.trim();
  
  const params = new URLSearchParams({
    action: 'get_transactions',
    status: status,
    payment_type: paymentType,
    date_from: dateFrom,
    date_to: dateTo,
    search: search,
    per_page: 1000 // Get large batch for export
  });
  
  try {
    const res = await fetch(`?${params.toString()}`);
    const data = await res.json();
    
    if (!data.success || !data.transactions.length) {
      showToast('Tiada data untuk diexport', 'error');
      return;
    }
    
    // Build CSV
    const headers = ['ID', 'Tarikh', 'Pembeli', 'No. Identiti Pembeli', 'Penjual', 'No. Identiti Penjual', 'Produk', 'Kategori', 'Jumlah (RM)', 'Kaedah Bayaran', 'Jenis Bayaran', 'Status Bayaran', 'Status'];
    const rows = data.transactions.map(t => [
      t.transaction_id,
      new Date(t.created_at).toLocaleString('ms-MY'),
      t.buyer_name,
      t.buyer_ident,
      t.seller_name,
      t.seller_ident,
      t.product_name,
      t.category,
      parseFloat(t.total_amount).toFixed(2),
      t.payment_method,
      t.payment_type === 'online' ? 'Dalam Talian' : 'Tunai',
      t.payment_status || '-',
      t.status
    ]);
    
    const csvContent = [
      headers.join(','),
      ...rows.map(row => row.map(cell => `"${String(cell).replace(/"/g, '""')}"`).join(','))
    ].join('\n');
    
    // Download
    const blob = new Blob(['\uFEFF' + csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `trustmart-transactions-${new Date().toISOString().split('T')[0]}.csv`;
    link.click();
    
    showToast('CSV berjaya diexport', 'success');
  } catch (err) {
    showToast('Ralat export: ' + err.message, 'error');
  }
}

// ── Toast notification ────────────────────────────────────────
function showToast(msg, type = 'info') {
  const t = document.createElement('div');
  t.className = `toast toast-${type}`;
  t.textContent = msg;
  t.style.cssText = 'position:fixed;top:20px;right:20px;padding:14px 20px;background:var(--ink);color:#fff;border-radius:8px;z-index:9999;box-shadow:0 4px 12px rgba(0,0,0,.2)';
  if (type === 'success') t.style.background = 'var(--green)';
  if (type === 'error') t.style.background = 'var(--red)';
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 3000);
}

// ── AI Trust Layer: fraud-risk review queue ───────────────────
async function loadAiRisks() {
  const el = document.getElementById('ai-risk-panel');
  if (!el) return;
  const data = await api('get_ai_risks');
  if (!data.success) {
    el.innerHTML = '<div class="empty-state" style="padding:14px">Gagal memuatkan data AI.</div>';
    return;
  }
  if (!data.rows.length) {
    el.innerHTML = '<div class="empty-state" style="padding:14px">' +
      '<div style="font-weight:500;color:var(--green)">✓ Tiada profil berisiko tinggi dikenal pasti oleh Lapisan AI.</div>' +
      '<div style="font-size:11px;margin-top:4px">Pekerja AI mengimbas urus niaga pusingan, ulasan tidak tulen dan bahasa scam secara berkala.</div></div>';
    return;
  }
  el.innerHTML = `
    <div class="tbl-wrap"><table class="tbl">
      <thead><tr>
        <th>Pengguna</th><th>Risiko</th><th>Isyarat AI</th><th>Skor AI</th><th>Tahanan</th><th>Tindakan</th>
      </tr></thead>
      <tbody>` +
    data.rows.map(r => {
      const risk = parseFloat(r.fraud_risk);
      const riskColor = risk >= 50 ? 'var(--red)' : (risk >= 25 ? 'var(--gold)' : 'var(--green)');
      const held = parseInt(r.ai_hold) === 1 && risk >= parseFloat(data.hold_risk);
      const signals = r.signals ? esc(r.signals) : esc(r.ai_flags || '—');
      return `<tr>
        <td>
          <div style="font-weight:500">${esc(r.full_name)}</div>
          <div style="font-size:11px;color:var(--ink3)">${esc(r.ident_no || '')} · ${esc(r.profile_role)}${parseInt(r.is_active) ? '' : ' · <b style="color:var(--red)">digantung</b>'}</div>
        </td>
        <td><b style="color:${riskColor}">${risk.toFixed(0)}%</b></td>
        <td style="font-size:11px;color:var(--ink3);max-width:260px">${signals}</td>
        <td style="font-size:12px">${r.ai_trust_score === null ? '—' : parseFloat(r.ai_trust_score).toFixed(0) + '/100'}</td>
        <td>${held
            ? '<span class="badge badge-red"><span class="badge-dot"></span>Ditahan</span>'
            : (parseInt(r.ai_hold) === 0 ? '<span class="badge badge-green"><span class="badge-dot"></span>Dilepaskan</span>' : '<span style="font-size:11px;color:var(--ink4)">—</span>')}</td>
        <td>
          <div style="display:flex;gap:5px;flex-wrap:wrap">
            ${held
              ? `<button class="btn btn-success btn-sm" onclick="aiSetHold(${r.user_id},'${esc(r.profile_role)}',0)">Lepaskan Tahanan</button>
                 <button class="btn btn-danger btn-sm" onclick="aiSuspend(${r.user_id},true)">Gantung Akaun</button>`
              : `<button class="btn btn-sm" onclick="aiSetHold(${r.user_id},'${esc(r.profile_role)}',1)">Kenakan Tahanan</button>`}
          </div>
        </td>
      </tr>`;
    }).join('') +
    '</tbody></table></div>';
}

async function aiSetHold(userId, role, hold) {
  const msg = hold === 0
    ? 'Lepaskan tahanan AI? Pengguna akan boleh menerima pesanan semula.'
    : 'Kenakan tahanan AI? Pesanan baharu daripada pengguna ini akan dihalang.';
  if (!confirm(msg)) return;
  const data = await api('ai_set_hold', {}, { user_id: userId, profile_role: role, hold: hold });
  showToast(data.message, data.success ? 'success' : 'error');
  if (data.success) loadAiRisks();
}

async function aiSuspend(userId) {
  if (!confirm('Gantung akaun pengguna ini? Semua produk dan pesanan baharu akan dihentikan.')) return;
  const data = await api('toggle_user_status', {}, { user_id: userId });
  showToast(data.message, data.success ? 'success' : 'error');
  if (data.success) loadAiRisks();
}

// ── Pulangan Barang (return shipments) management ───────────
// UI lives in #tab-barang so it shares the sidebar/topbar with every
// other admin tab. Backend stays in admin_returns.php (AJAX-only);
// old bookmarks there are redirected back to admin.php?tab=barang.
let _returnRows   = [];
let _returnFilter = 'all';

const RET_BADGE = {pending:'badge-gold',   in_transit:'badge-blue',  received:'badge-gold',
                   verified:'badge-green', cancelled:'badge-gray'};
const RET_LABEL = {pending:'Menunggu',      in_transit:'Dalam Penghantaran',
                   received:'Diterima',     verified:'Disahkan',
                   cancelled:'Dibatalkan'};
const RF_BADGE  = {none:'badge-gray',       requested:'badge-gold',
                   pending_return:'badge-blue', processing:'badge-blue',
                   completed:'badge-green', failed:'badge-red'};

async function loadReturns() {
  const tbody = document.getElementById('returns-table-body');
  if (!tbody) return;
  tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:20px;color:var(--ink3)">' +
    '<span class="spinner"></span> Memuatkan senarai pulangan barang...</td></tr>';
  try {
    const r = await fetch('admin_returns.php?action=get_pending_returns',
                          {headers: {'Accept':'application/json'}});
    const j = await r.json();
    if (!j.success) throw new Error(j.message || 'unknown error');
    _returnRows = j.returns || [];
    updateReturnCounts();
    renderReturns();
  } catch (e) {
    tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;padding:20px;color:var(--red)">' +
      'Gagal memuatkan: ' + esc(e.message) + '</td></tr>';
    showToast('Gagal memuatkan senarai: ' + e.message, 'error');
  }
}

function updateReturnCounts() {
  const c = {pending:0, in_transit:0, received:0};
  _returnRows.forEach(r => { if (c[r.status] !== undefined) c[r.status]++; });
  document.querySelectorAll('#tab-barang [data-cnt]').forEach(el => {
    el.textContent = c[el.getAttribute('data-cnt')] ?? 0;
  });
}

function filterReturns(btn, st) {
  _returnFilter = st;
  document.querySelectorAll('#tab-barang .filter-btn').forEach(b =>
    b.classList.toggle('active', b === btn));
  renderReturns();
}

function renderReturns() {
  const tbody = document.getElementById('returns-table-body');
  if (!tbody) return;
  const rows = _returnFilter === 'all' ? _returnRows
                                      : _returnRows.filter(r => r.status === _returnFilter);
  if (!rows.length) {
    tbody.innerHTML = '<tr><td colspan="10"><div class="empty-state">' +
      '<div style="font-size:28px;opacity:.3;margin-bottom:8px">📦</div>' +
      '<div style="font-weight:500">Tiada rekod pulangan barang</div></div></td></tr>';
    return;
  }
  const fmtDate = s => !s ? '—' : new Date(s).toLocaleString('ms-MY',
    {day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit'});
  const rm    = v => 'RM ' + Number(v || 0).toFixed(2);
  const badge = (map, s) => '<span class="badge ' + (map[s] || 'badge-gray') + '">' +
                             '<span class="badge-dot"></span>' + esc(s || '—') + '</span>';
  tbody.innerHTML = rows.map(r => {
    const st = r.status;
    const canReceive = (st === 'pending' || st === 'in_transit');
    const canVerify  = (st === 'received') && (r.refund_status === 'pending_return');
    const canWaive   = (st === 'pending' || st === 'in_transit' || st === 'received');
    const track = r.tracking_number
      ? esc(r.tracking_number) + (r.carrier ? ' · ' + esc(r.carrier) : '')
      : '<span style="color:var(--ink4)">Belum dihantar</span>';
    const refundBadge = r.refund_status
      ? badge(RF_BADGE, r.refund_status)
      : '<span class="badge badge-gray"><span class="badge-dot"></span>Tiada bayaran</span>';
    const acts = [];
    if (canReceive) acts.push('<button class="btn btn-sm" onclick="updateReturnStatus(' + r.return_id + ',\'received\')">Tandakan Diterima</button>');
    if (canVerify)  acts.push('<button class="btn btn-sm btn-success" onclick="updateReturnStatus(' + r.return_id + ',\'verified\')">Sahkan &amp; Pulkinkan</button>');
    if (canWaive)   acts.push('<button class="btn btn-sm btn-danger"  onclick="waiveReturn(' + r.return_id + ')">Langgarkan</button>');
    return '<tr>' +
      '<td>#' + r.return_id + '</td>' +
      '<td><div style="font-weight:500">' + esc(r.dispute_reason || '—') + '</div>' +
        '<div style="font-size:11px;color:var(--ink4)">Aduan #' + r.dispute_id +
        ' · dimohon ' + fmtDate(r.return_requested_at) + '</div></td>' +
      '<td>' + esc(r.product_name || '—') +
        '<div style="font-size:11px;color:var(--ink4)">Txn #' + r.transaction_id + '</div></td>' +
      '<td>' + rm(r.total_amount) + '</td>' +
      '<td>' + esc(r.buyer_name) +
        '<div style="font-size:11px;color:var(--ink4)">' + esc(r.buyer_email) + '</div></td>' +
      '<td>' + esc(r.seller_name || '—') + '</td>' +
      '<td>' + track + '</td>' +
      '<td>' + badge(RET_BADGE, RET_LABEL[st] || st) + '</td>' +
      '<td>' + refundBadge + (r.refund_amount
        ? ' <span style="font-size:11px;color:var(--ink4)">' + rm(r.refund_amount) + '</span>' : '') + '</td>' +
      '<td style="text-align:right">' + (acts.join(' ')
        || '<span style="color:var(--ink4);font-size:11px">—</span>') + '</td>' +
    '</tr>';
  }).join('');
}

async function updateReturnStatus(returnId, newStatus) {
  const label = RET_LABEL[newStatus] || newStatus;
  if (!confirm('Sahkan status pulangan #' + returnId + ' → ' + label + '?')) return;
  const fd = new FormData();
  fd.append('return_id', returnId);
  fd.append('status', newStatus);
  fd.append('notes', 'Status dikemas kini oleh admin');
  try {
    const r = await fetch('admin_returns.php?action=update_return_status',
                          {method: 'POST', body: fd});
    const j = await r.json();
    if (j.success) { showToast(j.message || 'OK', 'success'); loadReturns(); }
    else showToast(j.message || 'Gagal', 'error');
  } catch (e) { showToast('Ralat: ' + e.message, 'error'); }
}

async function waiveReturn(returnId) {
  const reason = prompt('Sebab langgarkan pulangan (wajib):');
  if (!reason) return;
  const fd = new FormData();
  fd.append('return_id', returnId);
  fd.append('reason', reason);
  try {
    const r = await fetch('admin_returns.php?action=waive_return',
                          {method: 'POST', body: fd});
    const j = await r.json();
    if (j.success) { showToast(j.message || 'OK', 'success'); loadReturns(); }
    else showToast(j.message || 'Gagal', 'error');
  } catch (e) { showToast('Ralat: ' + e.message, 'error'); }
}

loadAiRisks();
</script>
</body>
</html>