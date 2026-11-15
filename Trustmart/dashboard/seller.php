<?php
// ============================================================
// dashboard/seller.php
// Dashboard for logged-in sellers (penjual).
// Handles AJAX sub-actions posted to the same URL:
//   ?action=get_stats
//   ?action=add_product
//   ?action=edit_product
//   ?action=delete_product
//   ?action=update_order_status
//   ?action=get_orders
//   ?action=get_products
// ============================================================

require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/trust_score.php';
require_once __DIR__ . '/../includes/ai_trust.php';
require_once __DIR__ . '/../includes/notifications.php';
refresh_session_user($pdo);

require_role('penjual');

$user      = current_user();
$seller_id = $user['user_id'];

// ════════════════════════════════════════════════════════════
// AJAX ACTION ROUTER
// ════════════════════════════════════════════════════════════
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_validate();

    switch ($_GET['action']) {
        
        // ── FIX H4: Confirm cash payment receipt ──────────────
        case 'confirm_cash_payment':
            $txn_id = (int)($_POST['transaction_id'] ?? 0);
            $amount_received = (float)($_POST['amount_received'] ?? 0);
            $payment_method_detail = trim($_POST['payment_method_detail'] ?? 'Serah tangan');
            $seller_notes = trim($_POST['seller_notes'] ?? '');
            
            try {
                $pdo->beginTransaction();
                
                // Lock transaction
                $stmt = $pdo->prepare("
                    SELECT t.transaction_id, t.total_amount, t.payment_type, t.payment_status
                    FROM transactions t
                    WHERE t.transaction_id = ? AND t.seller_id = ?
                    FOR UPDATE
                ");
                $stmt->execute([$txn_id, $seller_id]);
                $txn = $stmt->fetch();
                
                if (!$txn) {
                    $pdo->rollBack();
                    http_response_code(404);
                    die(json_encode(['success' => false, 'message' => 'Pesanan tidak ditemui']));
                }
                
                if ($txn['payment_type'] !== 'cash') {
                    $pdo->rollBack();
                    http_response_code(422);
                    die(json_encode(['success' => false, 'message' => 'Hanya pesanan tunai boleh disahkan di sini']));
                }
                
                if ($txn['payment_status'] !== 'unpaid_cash') {
                    $pdo->rollBack();
                    http_response_code(422);
                    die(json_encode(['success' => false, 'message' => 'Pembayaran tunai sudah disahkan atau tidak perlu pengesahan']));
                }
                
                // Validate amount
                if ($amount_received <= 0 || abs($amount_received - $txn['total_amount']) > 0.01) {
                    $pdo->rollBack();
                    http_response_code(422);
                    die(json_encode([
                        'success' => false, 
                        'message' => 'Jumlah yang diterima tidak sepadan dengan jumlah pesanan (RM' . number_format($txn['total_amount'], 2) . ')'
                    ]));
                }
                
                // Update transaction payment status
                $pdo->prepare("
                    UPDATE transactions
                    SET payment_status = 'paid_cash'
                    WHERE transaction_id = ?
                ")->execute([$txn_id]);
                
                // Update / create cash_payments record.
                // L6: legacy cash orders created before the after_cash_transaction_insert
                // trigger have NO cash_payments row, so a plain UPDATE matched 0 rows and
                // the confirmation was never recorded. Upsert guarantees the audit row
                // exists whether or not the trigger had fired for this order.
                $pdo->prepare("
                    INSERT INTO cash_payments
                        (transaction_id, confirmed_by_seller, seller_confirmed_at,
                         seller_notes, amount_received, payment_method_detail)
                    VALUES (?, 1, CURRENT_TIMESTAMP, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE
                        confirmed_by_seller = 1,
                        seller_confirmed_at = CURRENT_TIMESTAMP,
                        seller_notes        = VALUES(seller_notes),
                        amount_received     = VALUES(amount_received),
                        payment_method_detail = VALUES(payment_method_detail)
                ")->execute([
                    $txn_id,
                    $seller_notes ?: 'Cash payment confirmed by seller',
                    $amount_received,
                    $payment_method_detail
                ]);
                
                $pdo->commit();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'Pembayaran tunai telah disahkan. Anda kini boleh mengesahkan pesanan.'
                ]);
                
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[confirm_cash_payment] ' . $e->getMessage());
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Ralat pelayan']);
            }
            exit;

        // ── Add a new product ─────────────────────────────────
        case 'add_product':
            if ($_SESSION['user']['verification_status'] !== 'Disahkan') {
                http_response_code(403);
                die(json_encode(['success' => false, 'message' => 'Akaun anda belum disahkan oleh PTU untuk menambah produk.']));
            }
            $name     = trim($_POST['name']        ?? '');
            $category = trim($_POST['category']    ?? '');
            $desc     = trim($_POST['description'] ?? '');
            $price    = (float) ($_POST['price']   ?? 0);
            $stock    = (int)   ($_POST['stock']   ?? 0);

            // Validate
            $errors = [];
            if ($name === '')              $errors[] = 'Nama produk diperlukan.';
            if (mb_strlen($name) > 200)    $errors[] = 'Nama produk tidak boleh melebihi 200 aksara.';
            if (mb_strlen($desc) > 5000)   $errors[] = 'Penerangan tidak boleh melebihi 5000 aksara.';
            if (!is_finite($price) || $price <= 0 || $price > 99999999.99)
                                               $errors[] = 'Harga mestilah antara RM0.01 dan RM99,999,999.99.';
            if ($stock < 0 || $stock > 1000000)
                                               $errors[] = 'Stok mestilah antara 0 dan 1,000,000.';

            $allowed_cats = [
                'Elektronik','Buku','Pakaian','Makanan','Perkhidmatan','Lain-lain'
            ];
            if (!in_array($category, $allowed_cats, true)) {
                $errors[] = 'Kategori tidak sah.';
            }

            if (!empty($errors)) {
                http_response_code(422);
                die(json_encode(['success'=>false,'errors'=>$errors]));
            }

            // Handle image upload
            $image_path = null;
            if (!empty($_FILES['image']['name'])) {
                $result = handle_image_upload($_FILES['image']);
                if (!$result['success']) {
                    http_response_code(422);
                    die(json_encode($result));
                }
                $image_path = $result['path'];
            }

            try {
                $stmt = $pdo->prepare(
                    'INSERT INTO products
                        (seller_id, name, category, description,
                         price, stock, image_path, status)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );
                $stmt->execute([
                    $seller_id, $name, $category,
                    $desc ?: null, $price, $stock,
                    $image_path, 'Aktif',
                ]);
                $new_id = $pdo->lastInsertId();

                echo json_encode([
                    'success'    => true,
                    'message'    => 'Produk berjaya ditambah!',
                    'product_id' => (int) $new_id,
                ]);
            } catch (PDOException $e) {
                error_log('[add_product] ' . $e->getMessage());
                http_response_code(500);
                echo json_encode(['success'=>false,'message'=>'Ralat pelayan.']);
            }
            exit;

        // ── Edit an existing product ──────────────────────────
        case 'edit_product':
            if ($_SESSION['user']['verification_status'] !== 'Disahkan') {
                http_response_code(403);
                die(json_encode(['success' => false, 'message' => 'Akaun anda belum disahkan oleh PTU untuk mengemas kini produk.']));
            }
            $product_id  = (int)   ($_POST['product_id']   ?? 0);
            $name        = trim($_POST['name']             ?? '');
            $category    = trim($_POST['category']         ?? '');
            $desc        = trim($_POST['description']      ?? '');
            $price       = (float) ($_POST['price']        ?? 0);
            $stock       = (int)   ($_POST['stock']        ?? 0);
            $status      = trim($_POST['status']           ?? 'Aktif');

            // Verify ownership — seller cannot edit another seller's product
            $stmt = $pdo->prepare(
                'SELECT product_id, image_path
                   FROM products
                  WHERE product_id = ? AND seller_id = ?'
            );
            $stmt->execute([$product_id, $seller_id]);
            $existing = $stmt->fetch();

            if (!$existing) {
                http_response_code(404);
                die(json_encode([
                    'success' => false,
                    'message' => 'Produk tidak ditemui.',
                ]));
            }

            // Validate
            $errors = [];
            if ($name === '')              $errors[] = 'Nama produk diperlukan.';
            if (mb_strlen($name) > 200)    $errors[] = 'Nama produk tidak boleh melebihi 200 aksara.';
            if (mb_strlen($desc) > 5000)   $errors[] = 'Penerangan tidak boleh melebihi 5000 aksara.';
            if (!is_finite($price) || $price <= 0 || $price > 99999999.99)
                                               $errors[] = 'Harga mestilah antara RM0.01 dan RM99,999,999.99.';
            if ($stock < 0 || $stock > 1000000)
                                               $errors[] = 'Stok mestilah antara 0 dan 1,000,000.';

            $allowed_cats = [
                'Elektronik','Buku','Pakaian','Makanan','Perkhidmatan','Lain-lain'
            ];
            if (!in_array($category, $allowed_cats, true)) {
                $errors[] = 'Kategori tidak sah.';
            }

            $allowed_statuses = ['Aktif','Tidak Aktif'];
            if (!in_array($status, $allowed_statuses, true)) {
                $errors[] = 'Status tidak sah.';
            }

            if (!empty($errors)) {
                http_response_code(422);
                die(json_encode(['success'=>false,'errors'=>$errors]));
            }

            // Stage a replacement image. Keep the old file until the database
            // update succeeds, and remove the staged file if the update fails.
            $old_image_path = $existing['image_path'];
            $image_path = $old_image_path;
            $staged_image_path = null;
            if (!empty($_FILES['image']['name'])) {
                $result = handle_image_upload($_FILES['image']);
                if (!$result['success']) {
                    http_response_code(422);
                    die(json_encode($result));
                }
                $staged_image_path = $result['path'];
                $image_path = $staged_image_path;
            }

            try {
                $pdo->prepare(
                    'UPDATE products
                        SET name=?, category=?, description=?,
                            price=?, stock=?, image_path=?, status=?
                      WHERE product_id=? AND seller_id=?'
                )->execute([
                    $name, $category, $desc ?: null,
                    $price, $stock, $image_path, $status,
                    $product_id, $seller_id,
                ]);

                if ($staged_image_path && $old_image_path) {
                    $old_file = __DIR__ . '/../uploads/' . $old_image_path;
                    if (is_file($old_file) && !unlink($old_file)) {
                        error_log('[edit_product] Could not remove old image: ' . $old_file);
                    }
                }

                echo json_encode([
                    'success' => true,
                    'message' => 'Produk berjaya dikemas kini.',
                ]);
            } catch (PDOException $e) {
                if ($staged_image_path) {
                    $staged_file = __DIR__ . '/../uploads/' . $staged_image_path;
                    if (is_file($staged_file)) {
                        unlink($staged_file);
                    }
                }
                error_log('[edit_product] ' . $e->getMessage());
                http_response_code(500);
                echo json_encode(['success'=>false,'message'=>'Ralat pelayan.']);
            }
            exit;

        // ── Soft-delete a product ─────────────────────────────
        // Sets status to 'Dipadam' rather than deleting the row,
        // so existing transaction records stay intact.
        case 'delete_product':
            $product_id = (int) ($_POST['product_id'] ?? 0);

            try {
                $pdo->beginTransaction();

                // Lock the product before checking orders. A checkout locks the
                // same row, so deletion and purchase cannot pass each other.
                $stmt = $pdo->prepare(
                    'SELECT product_id
                       FROM products
                      WHERE product_id = ? AND seller_id = ?
                      FOR UPDATE'
                );
                $stmt->execute([$product_id, $seller_id]);

                if (!$stmt->fetch()) {
                    $pdo->rollBack();
                    http_response_code(404);
                    die(json_encode([
                        'success' => false,
                        'message' => 'Produk tidak ditemui.',
                    ]));
                }

                $stmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM transactions
                      WHERE product_id = ?
                        AND status IN (\'Menunggu\',\'Disahkan\')'
                );
                $stmt->execute([$product_id]);
                if ((int) $stmt->fetchColumn() > 0) {
                    $pdo->rollBack();
                    http_response_code(422);
                    die(json_encode([
                        'success' => false,
                        'message' => 'Tidak boleh dipadam — ada pesanan aktif untuk produk ini.',
                    ]));
                }

                $pdo->prepare(
                    'UPDATE products SET status = ?
                      WHERE product_id = ? AND seller_id = ?'
                )->execute(['Dipadam', $product_id, $seller_id]);

                $pdo->commit();
                echo json_encode([
                    'success' => true,
                    'message' => 'Produk berjaya dipadamkan.',
                ]);
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[delete_product] ' . $e->getMessage());
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Ralat pelayan.']);
            }
            exit;

        // ── Update order status ───────────────────────────────
        case 'update_order_status':
            $txn_id     = (int) ($_POST['transaction_id'] ?? 0);
            $new_status = trim($_POST['status'] ?? '');

            // Sellers may accept or reject an order. Completion is confirmed
            // by the buyer, so sellers cannot manufacture successful outcomes.
            $valid_transitions = [
                'Menunggu' => ['Disahkan','Dibatalkan'],
                'Disahkan' => ['Dibatalkan'],
            ];

            try {
                $pdo->beginTransaction();

                // Lock before validating the current status. Buyer and seller
                // transitions now serialize on the same transaction row.
                $stmt = $pdo->prepare(
                    'SELECT transaction_id, product_id, quantity, status,
                            payment_type, payment_status, total_amount
                       FROM transactions
                      WHERE transaction_id = ? AND seller_id = ?
                      FOR UPDATE'
                );
                $stmt->execute([$txn_id, $seller_id]);
                $txn = $stmt->fetch();

                if (!$txn) {
                    $pdo->rollBack();
                    http_response_code(404);
                    die(json_encode([
                        'success' => false,
                        'message' => 'Pesanan tidak ditemui.',
                    ]));
                }

                // Block confirmation of unpaid online orders
                // CRITICAL: Only allow 'paid' status - not 'pending' or 'processing'
                if ($new_status === 'Disahkan' && 
                    $txn['payment_type'] === 'online' && 
                    $txn['payment_status'] !== 'paid') {
                    $pdo->rollBack();
                    http_response_code(422);
                    die(json_encode([
                        'success' => false,
                        'message' => 'Pesanan bayaran dalam talian mesti dibayar sepenuhnya dahulu sebelum disahkan. Status pembayaran: ' . ($txn['payment_status'] ?? 'tidak diketahui'),
                    ]));
                }
                
                // FIX H4: Block confirmation of unpaid cash orders
                if ($new_status === 'Disahkan' && 
                    $txn['payment_type'] === 'cash' && 
                    $txn['payment_status'] === 'unpaid_cash') {
                    $pdo->rollBack();
                    http_response_code(422);
                    die(json_encode([
                        'success' => false,
                        'message' => 'Sila sahkan penerimaan pembayaran tunai terlebih dahulu sebelum mengesahkan pesanan.',
                        'action_required' => 'confirm_cash_payment'
                    ]));
                }

                $allowed_next = $valid_transitions[$txn['status']] ?? [];
                if (!in_array($new_status, $allowed_next, true)) {
                    $pdo->rollBack();
                    http_response_code(422);
                    die(json_encode([
                        'success' => false,
                        'message' => "Tidak boleh menukar daripada {$txn['status']} kepada {$new_status}.",
                    ]));
                }

                if ($new_status === 'Disahkan') {
                    $pdo->prepare(
                        'UPDATE transactions
                            SET status = ?,
                                confirmed_at = COALESCE(confirmed_at, CURRENT_TIMESTAMP)
                          WHERE transaction_id = ?'
                    )->execute([$new_status, $txn_id]);
                } else {
                    $pdo->prepare(
                        'UPDATE transactions
                            SET status = ?, cancelled_at = CURRENT_TIMESTAMP,
                                cancelled_by = \'seller\'
                          WHERE transaction_id = ?'
                    )->execute([$new_status, $txn_id]);
                }

                if ($new_status === 'Dibatalkan') {
                    // L3: Restock ONLY when cancelling from 'Menunggu', where the
                    // goods were never dispatched. Once the seller has moved the
                    // order to 'Disahkan' the sale is confirmed and the item has
                    // been handed over / committed to the buyer, so returning it
                    // to inventory would overstate available stock. This matches
                    // admin dispute settlement, which deliberately never restocks.
                    if ($txn['status'] === 'Menunggu') {
                        $pdo->prepare(
                            'UPDATE products
                                SET stock      = stock + ?,
                                    sold_count = GREATEST(sold_count - ?, 0)
                              WHERE product_id = ?'
                        )->execute([
                            $txn['quantity'],
                            $txn['quantity'],
                            $txn['product_id'],
                        ]);
                    } else {
                        // Cancelling an already-confirmed order: goods already left,
                        // so no stock movement. Log it so the retained sold_count /
                        // reduced stock is explainable during reconciliation.
                        error_log("[update_order_status] L3 no-restock txn={$txn_id} cancelled from {$txn['status']} (goods already confirmed/dispatched)");
                    }

                    // Close any still-open (pending) payment attempt so it
                    // cannot later be completed and mark this cancelled order
                    // as paid.
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
                    
                    // Refund paid online orders
                    $cash_reconcile = false;
                    if ($txn['payment_type'] === 'online' && $txn['payment_status'] === 'paid') {
                        // Import refund processing function
                        require_once __DIR__ . '/../config/payment.php';
                        
                        // Fetch payment record
                        $payment_stmt = $pdo->prepare(
                            'SELECT payment_id FROM payments 
                             WHERE transaction_id = ? AND status = \'completed\'
                             LIMIT 1'
                        );
                        $payment_stmt->execute([$txn_id]);
                        $payment = $payment_stmt->fetch();
                        
                        if ($payment) {
                            // Process actual refund through payment gateway
                            $refund_result = process_payment_refund(
                                $pdo, 
                                $payment['payment_id'], 
                                'Pesanan dibatalkan oleh penjual',
                                'auto'
                            );
                            
                            if (!$refund_result['success']) {
                                // Log refund failure but don't block cancellation
                                error_log("[seller_cancel_order] Refund failed for payment {$payment['payment_id']}: {$refund_result['error']}");
                            }
                        }
                    }
                    // L1: A cancelled order whose cash had already been received by
                    // the seller has NO gateway refund path. Flag the cash_payments
                    // audit record so the obligation is visible to both the seller
                    // (via the response warning) and to PTU admins reviewing
                    // Dibatalkan + paid_cash anomalies.
                    elseif ($txn['payment_type'] === 'cash' && $txn['payment_status'] === 'paid_cash') {
                        $cash_reconcile = true;
                        $pdo->prepare(
                            "UPDATE cash_payments
                                SET seller_notes = CONCAT(
                                        COALESCE(seller_notes, ''),
                                        '\n[CASH_RECONCILE ', NOW(), '] Penjual membatalkan pesanan setelah tunai diterima. Wajib pulangkan RM', ?, ' kepada pembeli secara manual.')
                              WHERE transaction_id = ?"
                        )->execute([
                            number_format($txn['total_amount'], 2, '.', ''),
                            $txn_id,
                        ]);
                        error_log("[update_order_status] CASH_RECONCILE txn={$txn_id} seller cancelled AFTER confirming cash receipt — buyer must receive RM{$txn['total_amount']} manually");
                    }
                }

                $pdo->commit();

                $resp = [
                    'success'    => true,
                    'message'    => "Status pesanan dikemas kini kepada {$new_status}.",
                    'new_status' => $new_status,
                ];
                if (!empty($cash_reconcile)) {
                    $resp['cash_reconcile_required'] = true;
                    $resp['warning'] = 'Amaran: Anda telah menerima pembayaran tunai untuk pesanan ini. Sila pulangkan wang kepada pembeli secara manual. Rekod ini akan disemak oleh PTU.';
                    // Also bake the warning into `message` so the existing success
                    // toast (which only reads data.message) surfaces it to the
                    // seller without a frontend change.
                    $resp['message'] .= ' ⚠ ' . $resp['warning'];
                }
                echo json_encode($resp);
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[update_order_status] ' . $e->getMessage());
                http_response_code(500);
                echo json_encode(['success'=>false,'message'=>'Ralat pelayan.']);
            }
            exit;

        // ── Fetch live stats for dashboard cards ──────────────
        case 'get_stats':
            $stats = fetch_seller_stats($pdo, $seller_id);
            echo json_encode(['success'=>true,'stats'=>$stats]);
            exit;

        // ── Fetch seller's products (with optional filter & pagination)
        case 'get_products':
            $status_filter = trim($_GET['status'] ?? '');
            $page          = max(1, (int)($_GET['page'] ?? 1));
            $per_page      = max(1, min(50, (int)($_GET['per_page'] ?? 10)));
            $offset        = ($page - 1) * $per_page;

            $params        = [$seller_id];
            $where         = 'WHERE seller_id = ? AND status != \'Dipadam\'';

            $allowed_statuses = ['Aktif','Tidak Aktif'];
            if (in_array($status_filter, $allowed_statuses, true)) {
                $where   .= ' AND status = ?';
                $params[] = $status_filter;
            }

            // Total count
            $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM products {$where}");
            $count_stmt->execute($params);
            $total = (int)$count_stmt->fetchColumn();
            $total_pages = $total > 0 ? (int)ceil($total / $per_page) : 1;

            $stmt = $pdo->prepare(
                "SELECT product_id, name, category, description,
                        price, stock, sold_count, image_path, status, created_at
                   FROM products
                  {$where}
                  ORDER BY created_at DESC
                  LIMIT {$per_page} OFFSET {$offset}"
            );
            $stmt->execute($params);
            echo json_encode([
                'success'    => true,
                'products'   => $stmt->fetchAll(),
                'pagination' => [
                    'page'        => $page,
                    'per_page'    => $per_page,
                    'total'       => $total,
                    'total_pages' => $total_pages,
                ]
            ]);
            exit;

        // ── Fetch seller's orders (with optional filter & pagination) ──
        case 'get_orders':
            $status_filter = trim($_GET['status'] ?? '');
            $page          = max(1, (int)($_GET['page'] ?? 1));
            $per_page      = max(1, min(50, (int)($_GET['per_page'] ?? 10)));
            $offset        = ($page - 1) * $per_page;

            $params        = [$seller_id];
            $where         = 'WHERE t.seller_id = ?';

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
                        t.payment_type, t.payment_status,
                        t.payment_method, t.quantity, t.created_at,
                        p.name  AS product_name, p.category,
                        u.full_name AS buyer_name,
                        COALESCE(NULLIF(u.matric_no, ''), u.staff_no) AS buyer_ident,
                        tp.score AS buyer_trust, tp.evidence_units AS buyer_trust_evidence,
                        r.rating
                   FROM transactions t
                   JOIN products p ON t.product_id = p.product_id
                   JOIN users    u ON t.buyer_id   = u.user_id
              LEFT JOIN trust_profiles tp ON tp.user_id = t.buyer_id AND tp.profile_role = 'pembeli'
              LEFT JOIN reviews  r ON r.transaction_id = t.transaction_id
                  {$where}
                  ORDER BY t.created_at DESC
                  LIMIT {$per_page} OFFSET {$offset}"
            );
            $stmt->execute($params);
            echo json_encode([
                'success'    => true,
                'orders'     => $stmt->fetchAll(),
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
            die(json_encode([
                'success' => false,
                'message' => 'Tindakan tidak diketahui.',
            ]));
    }
}

// ════════════════════════════════════════════════════════════
// HELPER FUNCTIONS
// ════════════════════════════════════════════════════════════

// ── Fetch all stats in one place ──────────────────────────────
function fetch_seller_stats(PDO $pdo, int $seller_id): array
{
    // Orders by status
    $stmt = $pdo->prepare(
        'SELECT status, COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS total
           FROM transactions
          WHERE seller_id = ?
          GROUP BY status'
    );
    $stmt->execute([$seller_id]);
    $rows = $stmt->fetchAll();

    $counts  = [];
    $revenue = 0;
    foreach ($rows as $r) {
        $counts[$r['status']] = (int) $r['cnt'];
        if ($r['status'] === 'Selesai') {
            $revenue = (float) $r['total'];
        }
    }

    // Average rating
    $stmt = $pdo->prepare(
        'SELECT COALESCE(AVG(rating),0) AS avg_rating, COUNT(*) AS total_reviews
           FROM reviews WHERE seller_id = ?'
    );
    $stmt->execute([$seller_id]);
    $rating_row = $stmt->fetch();

    // Product counts
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS total,
                SUM(CASE WHEN status=\'Aktif\'       THEN 1 ELSE 0 END) AS active,
                SUM(CASE WHEN stock=0               THEN 1 ELSE 0 END) AS out_of_stock
           FROM products
          WHERE seller_id = ? AND status != \'Dipadam\''
    );
    $stmt->execute([$seller_id]);
    $prod_row = $stmt->fetch();

    // Weekly sales (last 7 days, one value per day)
    $stmt = $pdo->prepare(
        'SELECT DATE(created_at) AS day,
                COUNT(*)        AS orders,
                SUM(total_amount) AS sales
           FROM transactions
          WHERE seller_id = ?
            AND status    = \'Selesai\'
            AND created_at >= CURDATE() - INTERVAL 6 DAY
          GROUP BY DATE(created_at)
          ORDER BY day ASC'
    );
    $stmt->execute([$seller_id]);
    $weekly_raw = $stmt->fetchAll();

    // Fill in missing days with 0
    $weekly = [];
    for ($i = 6; $i >= 0; $i--) {
        $day = date('Y-m-d', strtotime("-{$i} days"));
        $found = array_filter($weekly_raw, fn($r) => $r['day'] === $day);
        $found = array_values($found);
        $weekly[] = [
            'day'    => date('D', strtotime($day)),
            'orders' => $found ? (int) $found[0]['orders'] : 0,
            'sales'  => $found ? (float) $found[0]['sales'] : 0,
        ];
    }

    // Disputes against seller
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM disputes d
         JOIN transactions t ON d.transaction_id = t.transaction_id
         WHERE t.seller_id = ?
       AND d.status = 'Selesai'
       AND d.resolution = 'Memihak Pembeli'"
    );
    $stmt->execute([$seller_id]);
    $disputes_against = (int) $stmt->fetchColumn();

    // Authoritative trust profile and persisted component breakdown.
    $trust = trust_profile($pdo, $seller_id, 'penjual');
    $stmt = $pdo->prepare('SELECT is_verified FROM users WHERE user_id=?');
    $stmt->execute([$seller_id]);
    $is_verified = (bool)$stmt->fetchColumn();

    return [
        'disputes_against'=> $disputes_against,
        'counts'         => $counts,
        'revenue'        => $revenue,
        'avg_rating'     => round((float)$rating_row['avg_rating'], 1),
        'total_reviews'  => (int)$rating_row['total_reviews'],
        'products_total' => (int)$prod_row['total'],
        'products_active'=> (int)$prod_row['active'],
        'out_of_stock'   => (int)$prod_row['out_of_stock'],
        'weekly'         => $weekly,
        'trust'          => $trust,
        'trust_score'    => (float)$trust['score'],
        'is_verified'    => $is_verified,
        // carried with the other stats so ?action=get_stats and the initial
        // page render can never disagree about what is pending.
        'notify'         => pending_actions($pdo, $seller_id, 'penjual'),
    ];
}

// ── Image upload handler ──────────────────────────────────────
function handle_image_upload(array $file): array
{
    $max_size   = 2 * 1024 * 1024; // 2 MB
    $max_pixels = 24_000_000;      // decompression-bomb guard
    $allowed    = ['image/jpeg','image/png','image/webp'];
    $upload_dir = __DIR__ . '/../uploads/products/';

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['success'=>false,'message'=>'Ralat semasa memuat naik fail.'];
    }
    if (($file['size'] ?? 0) < 1 || $file['size'] > $max_size || !is_uploaded_file($file['tmp_name'])) {
        return ['success'=>false,'message'=>'Imej mesti fail yang sah dan tidak melebihi 2MB.'];
    }

    // Validate MIME type and dimensions from file content, not its extension.
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = $finfo ? finfo_file($finfo, $file['tmp_name']) : false;
    if ($finfo) {
        finfo_close($finfo);
    }

    $dimensions = @getimagesize($file['tmp_name']);
    if (!in_array($mime, $allowed, true) || $dimensions === false) {
        return [
            'success' => false,
            'message' => 'Format fail tidak disokong. Gunakan JPG, PNG, atau WEBP.',
        ];
    }
    if ((int)$dimensions[0] * (int)$dimensions[1] > $max_pixels) {
        return ['success'=>false,'message'=>'Resolusi imej terlalu besar. Gunakan imej di bawah 24 megapiksel.'];
    }

    if ((!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true)) || !is_writable($upload_dir)) {
        error_log('[image_upload] Upload directory is unavailable: ' . $upload_dir);
        return ['success'=>false,'message'=>'Storan imej tidak tersedia buat masa ini.'];
    }

    $ext = match($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    };
    $filename = 'products/' . bin2hex(random_bytes(12)) . '.' . $ext;
    $dest     = __DIR__ . '/../uploads/' . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['success'=>false,'message'=>'Gagal menyimpan fail.'];
    }

    return ['success'=>true,'path'=>$filename];
}

// ── Page data for initial render ──────────────────────────────
$stats = fetch_seller_stats($pdo, $seller_id);
$seller_trust = $stats['trust'];
$notify = $stats['notify'];

// Recent pending orders (for dashboard quick-view)
$stmt = $pdo->prepare(
    'SELECT t.transaction_id, t.status, t.total_amount,
            t.payment_type,   t.payment_status,
            t.quantity,       t.created_at,
            p.name  AS product_name, p.category,
            u.full_name AS buyer_name,
            COALESCE(NULLIF(u.matric_no, \'\'), u.staff_no) AS buyer_ident,
            tp.score AS buyer_trust, tp.evidence_units AS buyer_trust_evidence
       FROM transactions t
       JOIN products p ON t.product_id = p.product_id
       JOIN users    u ON t.buyer_id   = u.user_id
  LEFT JOIN trust_profiles tp ON tp.user_id = t.buyer_id AND tp.profile_role = \'pembeli\'
      WHERE t.seller_id = ?
        AND t.status IN (\'Menunggu\',\'Disahkan\')
      ORDER BY t.created_at DESC
      LIMIT 5'
);
$stmt->execute([$seller_id]);
$pending_orders = $stmt->fetchAll();

// All active products for the product table
$stmt = $pdo->prepare(
    'SELECT product_id, name, category, price, stock,
            sold_count, image_path, status, created_at
       FROM products
      WHERE seller_id = ? AND status != \'Dipadam\'
      ORDER BY created_at DESC'
);
$stmt->execute([$seller_id]);
$products = $stmt->fetchAll();

// Badge helper
function badge_class(string $status): string {
    return match($status) {
        'Menunggu'   => 'badge-gold',
        'Disahkan'   => 'badge-blue',
        'Selesai'    => 'badge-green',
        'Dibatalkan' => 'badge-red',
        'Aktif'      => 'badge-green',
        'Tidak Aktif'=> 'badge-gray',
        default      => 'badge-gray',
    };
}

// Payment status badge for the seller order tables, so a seller can tell
// paid vs unpaid before clicking "Sahkan" (online must be 'paid'; cash
// 'unpaid_cash' still awaits in-person confirmation).
function payment_status_info(?string $status): array {
    return match((string) $status) {
        'paid'        => ['Dibayar',         'badge-green'],
        'paid_cash'   => ['Dibayar (Tunai)', 'badge-green'],
        'pending'     => ['Menunggu Bayaran', 'badge-gold'],
        'unpaid'      => ['Belum Bayar',     'badge-red'],
        'unpaid_cash' => ['Tunai: Belum Dibayar',    'badge-gold'],
        'failed'      => ['Bayaran Gagal',   'badge-red'],
        'refunded'    => ['Dipulangkan',     'badge-blue'],
        default       => ['—',               'badge-gray'],
    };
}
?>
<!DOCTYPE html>
<html lang="ms">
<head>
  <meta charset="UTF-8">
  <?= csrf_meta_tag() ?>
  <?= csrf_script() ?>
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>TrustMart — Papan Pemuka Penjual</title>
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

    /* ── Layout ─────────────────────────────────────────────── */
    .shell  {display:flex;height:100vh;overflow:hidden}
    .sidebar{width:220px;background:var(--ink);flex-shrink:0;
             display:flex;flex-direction:column;padding:24px 0}
    .main   {flex:1;overflow-y:auto}
    .topbar {height:60px;background:#fff;border-bottom:1px solid var(--cream3);
             display:flex;align-items:center;padding:0 28px;gap:16px;
             position:sticky;top:0;z-index:10}
    .content{padding:28px}

    /* ── Sidebar ─────────────────────────────────────────────── */
    .logo-text{font-family:'DM Serif Display',serif;font-size:22px;color:#fff;
               padding:0 20px 24px;border-bottom:1px solid rgba(255,255,255,0.08)}
    .logo-text span{color:var(--gold)}
    .nav-section{padding:20px 12px 8px;font-size:10px;letter-spacing:1.5px;
                 text-transform:uppercase;color:rgba(255,255,255,0.3)}
    .nav-item{display:flex;align-items:center;gap:10px;padding:9px 20px;
              color:rgba(255,255,255,0.55);cursor:pointer;font-size:13px;
              border-left:2px solid transparent;transition:all .15s}
    .nav-item:hover{color:#fff;background:rgba(255,255,255,0.06)}
    .nav-item.active{color:#fff;background:rgba(201,168,76,0.15);
                     border-left-color:var(--gold)}
    .sidebar-footer{margin-top:auto;padding:16px 20px;
                    border-top:1px solid rgba(255,255,255,0.08)}
    .avatar{width:32px;height:32px;border-radius:50%;background:var(--gold);
            display:flex;align-items:center;justify-content:center;
            font-size:12px;font-weight:600;color:var(--ink)}
    .trust-pill{display:inline-flex;align-items:center;gap:5px;padding:3px 10px;
                border-radius:20px;font-size:11px;font-weight:500;margin-top:6px}

    /* ── Cards ───────────────────────────────────────────────── */
    .stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;
                margin-bottom:28px}
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
    .card-box{background:#fff;border:1px solid var(--cream3);
              border-radius:var(--r);padding:20px}

    /* ── Tables ──────────────────────────────────────────────── */
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

    /* ── Badges & Buttons ────────────────────────────────────── */
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
    .btn-sm{padding:5px 10px;font-size:11px}
    .btn:disabled{opacity:.5;cursor:not-allowed}

    /* ── Layout helpers ──────────────────────────────────────── */
    .two-col{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:28px}
    .section-hd{display:flex;align-items:center;justify-content:space-between;
                margin-bottom:16px}
    .section-title{font-size:15px;font-weight:500}
    .divider{height:1px;background:var(--cream2);margin:16px 0}
    .filters{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap}
    .filter-btn{padding:5px 12px;border-radius:20px;border:1px solid var(--cream3);
                background:#fff;color:var(--ink3);font-size:12px;cursor:pointer;
                transition:.15s;font-family:inherit}
    .filter-btn.active{border-color:var(--gold);color:var(--ink);
                       background:var(--gold-pale)}

    /* ── Sparkline chart ─────────────────────────────────────── */
    .sparkline{display:flex;align-items:flex-end;gap:4px;height:48px;margin-bottom:4px}
    .spark-bar{flex:1;border-radius:3px 3px 0 0;background:var(--gold-light);
               transition:height .4s ease;cursor:pointer;position:relative}
    .spark-bar.today{background:var(--gold)}
    .spark-bar:hover::after{content:attr(data-tip);position:absolute;bottom:calc(100% + 4px);
                            left:50%;transform:translateX(-50%);background:var(--ink);
                            color:#fff;font-size:10px;padding:3px 7px;border-radius:4px;
                            white-space:nowrap;pointer-events:none;z-index:5}
    .spark-labels{display:flex;gap:4px;font-size:10px;color:var(--ink4)}
    .spark-labels span{flex:1;text-align:center}

    /* ── Trust score ring ────────────────────────────────────── */
    .trust-ring{position:relative;width:72px;height:72px;flex-shrink:0}
    .trust-ring svg{transform:rotate(-90deg)}
    .trust-ring-score{position:absolute;inset:0;display:flex;align-items:center;
                      justify-content:center;font-size:16px;font-weight:600}
    .trust-row{display:flex;align-items:center;gap:8px;font-size:12px;margin-bottom:5px}
    .trust-lbl{width:130px;color:var(--ink3);flex-shrink:0}
    .trust-track{flex:1;height:5px;background:var(--cream2);
                 border-radius:3px;overflow:hidden}
    .trust-prog{height:100%;border-radius:3px;background:var(--gold);transition:width .6s}
    .trust-pct{width:32px;text-align:right;font-size:11px;font-weight:500;flex-shrink:0}

    /* ── Modal ───────────────────────────────────────────────── */
    .modal-bg{position:fixed;inset:0;background:rgba(26,23,20,0.5);z-index:100;
              display:flex;align-items:center;justify-content:center}
    .modal{background:#fff;border-radius:16px;padding:28px;width:520px;
           max-width:90vw;max-height:85vh;overflow-y:auto}
    .modal-title{font-family:'DM Serif Display',serif;font-size:20px;margin-bottom:4px}
    .modal-sub{font-size:12px;color:var(--ink3);margin-bottom:20px}
    .modal-footer{display:flex;gap:8px;justify-content:flex-end;margin-top:20px;
                  padding-top:16px;border-top:1px solid var(--cream2)}
    .form-group{margin-bottom:16px}
    .form-label{display:block;font-size:12px;font-weight:500;color:var(--ink2);
                margin-bottom:6px;letter-spacing:.2px}
    .form-input{width:100%;padding:10px 14px;border:1px solid var(--cream3);
                border-radius:var(--r-sm);font-size:13px;color:var(--ink);
                font-family:inherit;outline:none;transition:.15s;background:#fff}
    .form-input:focus{border-color:var(--gold);box-shadow:0 0 0 3px rgba(201,168,76,0.1)}
    .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
    .form-error{font-size:11px;color:var(--red);margin-top:4px}
    .img-preview{width:100%;height:120px;border-radius:var(--r-sm);
                 object-fit:cover;display:none;margin-top:8px;
                 border:1px solid var(--cream3)}

    /* ── Toast ───────────────────────────────────────────────── */
    .toast{position:fixed;bottom:24px;right:24px;background:var(--ink);color:#fff;
           padding:12px 18px;border-radius:var(--r);font-size:13px;z-index:200;
           transform:translateY(100px);opacity:0;transition:all .3s;max-width:320px}
    .toast.show{transform:translateY(0);opacity:1}
    .toast.success{background:var(--green)}
    .toast.error{background:var(--red)}

    .empty-state{text-align:center;padding:32px;color:var(--ink3)}
    .spinner{display:inline-block;width:14px;height:14px;border:2px solid rgba(0,0,0,.2);
             border-top-color:var(--ink);border-radius:50%;
             animation:spin .6s linear infinite;vertical-align:middle;margin-right:6px}
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
      .two-col{grid-template-columns:1fr}
      .form-grid{grid-template-columns:1fr}
    }
  </style>
  <meta name="theme-color" content="#171b26">
  <link rel="stylesheet" href="../public/assets/css/trustmart-ui.css">
  <script src="../public/assets/js/trustmart-ui.js" defer></script>
</head>
<body>
<div class="shell">

  <!-- ── SIDEBAR ───────────────────────────────────────────── -->
  <aside class="sidebar">
    <div class="logo-text">Trust<span>Mart</span></div>

    <div class="nav-section">Penjual</div>
    <div class="nav-item active" onclick="showTab('dash')">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
           viewBox="0 0 24 24">
        <rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/>
        <rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>
      </svg>Papan Pemuka
    </div>
    <div class="nav-item" onclick="showTab('produk')">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
           viewBox="0 0 24 24">
        <path d="m2 7 4.41-4.41A2 2 0 0 1 7.83 2h8.34a2 2 0 0 1 1.42.59L22 7"/>
        <path d="M4 12v8a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-8"/>
        <path d="M15 22v-4a2 2 0 0 0-2-2h-2a2 2 0 0 0-2 2v4"/>
        <path d="M2 7h20"/>
      </svg>Produk Saya
    </div>
    <div class="nav-item" onclick="showTab('pesanan')">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
           viewBox="0 0 24 24">
        <path d="M9 11l3 3L22 4"/>
        <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>
      </svg>Pesanan Diterima
      <?= notify_count_chip_html($notify['action'], 'notif-nav-count') ?>
    </div>
    <div class="nav-item" id="nav-chat" onclick="showTab('chat')">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
           viewBox="0 0 24 24">
        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
      </svg>Sembang
      <span id="chat-badge-global" class="badge-dot" style="display:none;background:var(--red);width:8px;height:8px;border-radius:50%;margin-left:auto;"></span>
    </div>
    <a class="nav-item" href="profile.php" style="text-decoration:none">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
           viewBox="0 0 24 24">
        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
        <circle cx="12" cy="7" r="4"/>
      </svg>Profil Saya
    </a>

    <div class="nav-section">Beli Barang</div>
    <a class="nav-item" href="buyer.php" style="text-decoration:none">
      <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2"
           viewBox="0 0 24 24">
        <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/>
        <line x1="3" y1="6" x2="21" y2="6"/>
        <path d="M16 10a4 4 0 0 1-8 0"/>
      </svg>Tukar ke Mod Pembeli
    </a>

    <div class="sidebar-footer">
      <div style="display:flex;align-items:center;gap:10px">
        <div class="avatar"><?= htmlspecialchars(mb_substr($user['full_name'],0,1)) ?></div>
        <div>
          <div style="font-size:12px;color:rgba(255,255,255,0.8)">
            <?= htmlspecialchars($user['full_name']) ?>
          </div>
          <div style="font-size:10px;color:rgba(255,255,255,0.35)">
            Penjual<?= ($user['user_type'] ?? '') === 'staf' ? ' · Staf' : '' ?> · <?= htmlspecialchars($user['ident_no']) ?>
          </div>
        </div>
      </div>
      <?php if ($user['is_verified']): ?>
      <div class="trust-pill"
           style="background:rgba(201,168,76,0.15);color:var(--gold)">
        ✓ Disahkan PTU
      </div>
      <?php else: ?>
      <div class="trust-pill"
           style="background:rgba(255,255,255,0.06);color:rgba(255,255,255,0.4)">
        ⏳ Menunggu Pengesahan PTU
      </div>
      <?php endif ?>
      <div style="margin-top:12px">
        <a href="../auth/logout.php"
           style="display:flex;align-items:center;gap:8px;padding:7px 0;
                  font-size:12px;color:rgba(255,255,255,0.4);text-decoration:none">
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

  <!-- ── MAIN ──────────────────────────────────────────────── -->
  <div class="main">
    <!-- .console-topbar: the shared stylesheet lets this bar grow to two rows on
         narrow screens instead of clipping the notification badge and the score. -->
    <div class="topbar console-topbar">
      <div style="font-family:'DM Serif Display',serif;font-size:16px" id="topbar-title">
        Papan Pemuka
      </div>
      <div style="margin-left:auto;display:flex;align-items:center;gap:12px;font-size:12px;color:var(--ink3)">
        <?= notify_topbar_html($notify, 'notif', 'pesanan') ?>
        <div style="cursor:pointer;display:flex;align-items:center;gap:4px;background:var(--cream2);padding:4px 8px;border-radius:20px;font-weight:500" onclick="openSellerTrustModal()">
          Skor Pintar: <span id="topbar-trust" style="color:<?= trust_score_color($seller_trust) ?>"><?= htmlspecialchars(trust_score_display($seller_trust, 1)) ?></span>
          <span class="badge <?= trust_confidence_class($seller_trust) ?>" style="font-size:9px;padding:1px 6px">Keyakinan <?= htmlspecialchars($seller_trust['confidence_label']) ?></span>
          <span style="font-size:10px;opacity:0.7">ⓘ</span>
        </div>
      </div>

    </div>

    <div class="content">

      <!-- ═══════════════════════════════════════════════════
           TAB: PAPAN PEMUKA
      ════════════════════════════════════════════════════ -->
      <div id="tab-dash">

        <!-- Alert Banner if Unverified or Rejected -->
        <?php if ($_SESSION['user']['verification_status'] === 'Menunggu'): ?>
          <div style="background:var(--gold-pale); border:1px solid var(--gold-light); padding:16px; border-radius:var(--r); margin-bottom:20px; display:flex; align-items:center; gap:12px; color:#7A5A10">
            <span style="font-size:24px">⏳</span>
            <div>
              <div style="font-weight:600; font-size:14px">Akaun Penjual Menunggu Pengesahan</div>
              <div style="font-size:12px; margin-top:2px">Akaun anda masih belum disahkan oleh PTU UTHM. Anda tidak boleh menambah produk atau menjual sehingga akaun anda disahkan.</div>
            </div>
          </div>
        <?php elseif ($_SESSION['user']['verification_status'] === 'Ditolak'): ?>
          <div style="background:var(--red-bg); border:1px solid #F0B3AD; padding:16px; border-radius:var(--r); margin-bottom:20px; display:flex; align-items:center; gap:12px; color:var(--red)">
            <span style="font-size:24px">✕</span>
            <div>
              <div style="font-weight:600; font-size:14px">Pengesahan Penjual Ditolak</div>
              <div style="font-size:12px; margin-top:2px">Akaun penjual anda telah ditolak oleh PTU UTHM. Sila hubungi pentadbir PTU untuk maklumat lanjut atau rayuan.</div>
            </div>
          </div>
        <?php endif; ?>

        <!-- Stats cards -->
        <div class="stats-grid">
          <div class="stat-card gold">
            <div class="stat-label">Skor Kepercayaan</div>
            <div class="stat-val" id="sc-trust"
                 style="color:<?= trust_score_color($seller_trust) ?>"><?= htmlspecialchars(trust_score_display($seller_trust)) ?></div>
            <div class="stat-sub" id="sc-trust-sub">
              <?= htmlspecialchars(trust_summary_text($seller_trust)) ?>
            </div>
          </div>
          <div class="stat-card green">
            <div class="stat-label">Pendapatan (Selesai)</div>
            <div class="stat-val" id="sc-revenue">
              RM <?= number_format($stats['revenue'],0) ?>
            </div>
            <div class="stat-sub" id="sc-revenue-sub">
              <?= $stats['counts']['Selesai'] ?? 0 ?> pesanan selesai
            </div>
          </div>
          <div class="stat-card blue">
            <div class="stat-label">Pesanan Menunggu</div>
            <div class="stat-val" id="sc-pending">
              <?= $stats['counts']['Menunggu'] ?? 0 ?>
            </div>
            <div class="stat-sub" id="sc-pending-sub">
              <?= ($stats['counts']['Disahkan']??0) ?> dalam proses
            </div>
          </div>
          <div class="stat-card">
            <div class="stat-label">Purata Penilaian</div>
            <div class="stat-val" id="sc-rating">
              <?= $stats['avg_rating'] > 0
                  ? $stats['avg_rating'].' ★'
                  : '—' ?>
            </div>
            <div class="stat-sub" id="sc-rating-sub">
              daripada <?= $stats['total_reviews'] ?> ulasan
            </div>
          </div>
        </div>

        <!-- Charts + pending orders -->
        <div class="two-col">

          <!-- Weekly sales sparkline -->
          <div>
            <div class="section-hd">
              <div class="section-title">Jualan 7 Hari Terakhir</div>
            </div>
            <div class="card-box">
              <div class="sparkline" id="sparkline">
                <?php foreach ($stats['weekly'] as $i => $w): ?>
                <?php $max = max(array_column($stats['weekly'],'sales')) ?: 1; ?>
                <div class="spark-bar <?= $i===6?'today':'' ?>"
                     style="height:<?= max(8, ($w['sales']/$max)*100) ?>%"
                     data-tip="<?= $w['day'] ?>: RM <?= number_format($w['sales'],0) ?>">
                </div>
                <?php endforeach ?>
              </div>
              <div class="spark-labels">
                <?php foreach ($stats['weekly'] as $w): ?>
                <span><?= $w['day'] ?></span>
                <?php endforeach ?>
              </div>
              <div class="divider"></div>
              <div style="display:flex;justify-content:space-between">
                <div>
                  <div style="font-size:10px;color:var(--ink3)">Jualan minggu ini</div>
                  <div style="font-size:16px;font-weight:600">
                    RM <?= number_format(
                          array_sum(array_column($stats['weekly'],'sales')), 0) ?>
                  </div>
                </div>
                <div style="text-align:right">
                  <div style="font-size:10px;color:var(--ink3)">Kadar selesai</div>
                  <?php
                    $total_t  = array_sum($stats['counts'] ?? []);
                    $selesai  = $stats['counts']['Selesai'] ?? 0;
                    $rate_pct = $total_t > 0
                        ? round(($selesai / $total_t) * 100)
                        : 0;
                  ?>
                  <div style="font-size:16px;font-weight:600;color:var(--green)">
                    <?= $rate_pct ?>%
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Trust score breakdown -->
          <div>
            <div class="section-hd">
              <div class="section-title">Pecahan Skor Kepercayaan</div>
            </div>
            <div class="card-box">
              <?php
                $ts = (float)$seller_trust['score'];
                $circ = 2 * M_PI * 28;
                $offset = $circ - ($circ * ($ts / 100));
                $tc = trust_score_color($seller_trust);
              ?>
              <div style="display:flex;align-items:center;gap:20px;margin-bottom:16px">
                <div class="trust-ring">
                  <svg width="72" height="72" viewBox="0 0 72 72" aria-hidden="true">
                    <circle cx="36" cy="36" r="28" fill="none"
                            stroke="var(--cream3)" stroke-width="7"/>
                    <circle cx="36" cy="36" r="28" fill="none"
                            stroke="<?= $tc ?>" stroke-width="7"
                            stroke-dasharray="<?= round($circ,2) ?>"
                            stroke-dashoffset="<?= round($offset,2) ?>"
                            stroke-linecap="round"/>
                  </svg>
                  <div class="trust-ring-score"><?= trust_has_evidence($seller_trust) ? number_format($ts,0) : '—' ?></div>
                </div>
                <div style="flex:1;font-size:11px;color:var(--ink3);line-height:1.6">
                  <b style="color:var(--ink)"><?= htmlspecialchars($seller_trust['trust_level']) ?></b><br>
                  Keyakinan <?= htmlspecialchars($seller_trust['confidence_label']) ?>
                  (<?= number_format($seller_trust['confidence'],0) ?>%) ·
                  <?= (int)$seller_trust['distinct_counterparties'] ?> pembeli berbeza.<br>
                  Kualiti rekod <?= number_format($seller_trust['raw_score'],1) ?>/100.
                </div>
              </div>
              <?= trust_components_html($seller_trust) ?>
              <div style="font-size:11px;color:var(--ink4);border-top:1px solid var(--cream3);padding-top:10px">
                Formula <?= htmlspecialchars($seller_trust['formula_version']) ?> · Hanya 3 urus niaga pertama dengan pembeli yang sama dikira.
              </div>
            </div>
          </div>
        </div>

        <!-- Pending orders quick-action -->
        <div class="section-hd">
          <div class="section-title">Pesanan Menunggu Tindakan</div>
          <button class="btn btn-sm" onclick="showTab('pesanan')">Lihat Semua</button>
        </div>

        <?php if (empty($pending_orders)): ?>
        <div class="empty-state">
          <div style="font-size:32px;opacity:.3;margin-bottom:10px">✅</div>
          <div style="font-weight:500;margin-bottom:4px">Tiada pesanan menunggu</div>
          <div style="font-size:12px">Semua pesanan telah diuruskan</div>
        </div>
        <?php else: ?>
        <div class="tbl-wrap">
          <table class="tbl">
            <thead>
              <tr>
                <th>Produk</th><th>Pembeli</th><th>Jumlah</th><th>Bayaran</th>
                <th>Masa</th><th>Status</th><th>Tindakan</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($pending_orders as $o): ?>
              <tr data-order-row="<?= $o['transaction_id'] ?>">
                <td>
                  <div style="font-weight:500;max-width:180px;
                              white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                    <?= htmlspecialchars($o['product_name']) ?>
                  </div>
                  <div style="font-size:11px;color:var(--ink3)"><?= $o['category'] ?></div>
                </td>
                <td>
                  <div><?= htmlspecialchars($o['buyer_name']) ?></div>
                  <div style="font-size:11px;color:var(--ink3)">
                    <?php
                        // L5: buyer trust now comes from the Bayesian trust_profiles
                        // row (profile_role='pembeli') instead of the legacy
                        // users.buyer_trust_score mirror, so a buyer with no real
                        // evidence shows "Baharu" rather than a misleading 100/100.
                        $btEv    = (float)($o['buyer_trust_evidence'] ?? 0);
                        $btScore = (float)($o['buyer_trust'] ?? 0);
                        $btLabel = $btEv > 0 ? number_format($btScore, 0) : 'Baharu';
                        $btColor = $btEv <= 0 ? 'var(--ink4)'
                                  : ($btScore >= 85 ? 'var(--green)'
                                  : ($btScore >= 70 ? 'var(--gold)' : 'var(--red)'));
                    ?>
                    <?= htmlspecialchars($o['buyer_ident']) ?> · <span style="font-weight:600;color:<?= $btColor ?>">Skor Pembeli: <?= $btLabel ?></span>
                  </div>
                </td>
                <td>RM <?= number_format($o['total_amount'],2) ?></td>
                <?php $pi = payment_status_info($o['payment_status']); ?>
                <td style="white-space:nowrap"><span class="badge <?= $pi[1] ?>" data-role="pay-badge" style="white-space:nowrap"><span class="badge-dot"></span><?= $pi[0] ?></span></td>
                <td style="font-size:11px;color:var(--ink3)">
                  <?= date('d M, H:i', strtotime($o['created_at'])) ?>
                </td>
                <td>
                  <span class="badge <?= badge_class($o['status']) ?>" data-role="status-badge">
                    <span class="badge-dot"></span><?= $o['status'] ?>
                  </span>
                </td>
                <td>
                  <div style="display:flex;gap:6px" data-role="actions">
                    <?php if ($o['status'] === 'Menunggu'): ?>
                    <?php /* 'unpaid_cash' is only ever set by the cash checkout path
                             (product.php), so it is the exact condition
                             update_order_status blocks on. Without this control the
                             seller had no way to record receiving the money, and the
                             order could never move forward. */ ?>
                    <?php if (($o['payment_status'] ?? '') === 'unpaid_cash'): ?>
                    <button class="btn btn-primary btn-sm"
                      onclick="openCashModal(<?= (int)$o['transaction_id'] ?>, '<?= number_format($o['total_amount'], 2, '.', '') ?>')">
                      Sahkan Terima Tunai
                    </button>
                    <button class="btn btn-success btn-sm" disabled
                      title="Sahkan penerimaan tunai dahulu sebelum mengesahkan pesanan.">
                      Sahkan
                    </button>
                    <?php else: ?>
                    <button class="btn btn-success btn-sm"
                      onclick="updateOrder(<?= $o['transaction_id'] ?>,'Disahkan')">
                      Sahkan
                    </button>
                    <?php endif ?>
                    <button class="btn btn-danger btn-sm"
                      onclick="updateOrder(<?= $o['transaction_id'] ?>,'Dibatalkan')">
                      Tolak
                    </button>
                    <?php elseif ($o['status'] === 'Disahkan'): ?>
                    <span style="font-size:11px;color:var(--ink3)">Menunggu pengesahan penerimaan pembeli</span>
                    <button class="btn btn-danger btn-sm"
                      onclick="updateOrder(<?= $o['transaction_id'] ?>,'Dibatalkan')">
                      Batalkan
                    </button>
                    <?php endif ?>
                  </div>
                </td>
              </tr>
              <?php endforeach ?>
            </tbody>
          </table>
        </div>
        <?php endif ?>

      </div><!-- end tab-dash -->


      <!-- ═══════════════════════════════════════════════════
           TAB: PRODUK SAYA
      ════════════════════════════════════════════════════ -->
      <div id="tab-produk" style="display:none">
        <div class="section-hd">
          <div>
            <div style="font-family:'DM Serif Display',serif;font-size:20px">
              Produk Saya
            </div>
            <div style="font-size:12px;color:var(--ink3);margin-top:2px">
              <?= $stats['products_active'] ?> aktif ·
              <?= $stats['out_of_stock'] ?> kehabisan stok
            </div>
          </div>
          <div style="display:flex;gap:8px">
            <div class="filters" style="margin:0">
              <button class="filter-btn active"
                      onclick="filterProducts(this,'')">Semua</button>
              <button class="filter-btn"
                      onclick="filterProducts(this,'Aktif')">Aktif</button>
              <button class="filter-btn"
                      onclick="filterProducts(this,'Tidak Aktif')">Tidak Aktif</button>
            </div>
            <?php if ($user['verification_status'] === 'Disahkan'): ?>
              <button type="button" class="btn btn-primary" onclick="openAddModal()">+ Tambah Produk</button>
            <?php else: ?>
              <button type="button" class="btn btn-primary" disabled
                      title="Pengesahan PTU diperlukan sebelum produk boleh ditambah">
                Menunggu Pengesahan PTU
              </button>
            <?php endif ?>
          </div>
        </div>

        <div class="tbl-wrap">
          <table class="tbl">
            <thead>
              <tr>
                <th>Produk</th><th>Kategori</th><th>Harga</th>
                <th>Stok</th><th>Terjual</th><th>Status</th><th>Tindakan</th>
              </tr>
            </thead>
            <tbody id="products-tbody">
              <?php foreach ($products as $p): ?>
              <tr id="prod-row-<?= $p['product_id'] ?>">
                <td>
                  <div style="display:flex;align-items:center;gap:10px">
                    <?php if ($p['image_path']): ?>
                    <img src="../uploads/<?= htmlspecialchars($p['image_path']) ?>"
                         alt="" style="width:36px;height:36px;object-fit:cover;
                                       border-radius:6px;flex-shrink:0">
                    <?php else: ?>
                    <div style="width:36px;height:36px;background:var(--cream2);
                                border-radius:6px;flex-shrink:0;display:flex;
                                align-items:center;justify-content:center;
                                font-size:16px">📦</div>
                    <?php endif ?>
                    <div>
                      <div style="font-weight:500;max-width:160px;white-space:nowrap;
                                  overflow:hidden;text-overflow:ellipsis">
                        <?= htmlspecialchars($p['name']) ?>
                      </div>
                      <div style="font-size:10px;color:var(--ink4)">
                        ID #<?= $p['product_id'] ?>
                      </div>
                    </div>
                  </div>
                </td>
                <td><?= $p['category'] ?></td>
                <td>RM <?= number_format($p['price'],2) ?></td>
                <td>
                  <?php if ($p['stock'] === '0' || $p['stock'] == 0): ?>
                  <span style="color:var(--red);font-weight:500">0</span>
                  <?php else: ?>
                  <?= $p['stock'] ?>
                  <?php endif ?>
                </td>
                <td><?= $p['sold_count'] ?></td>
                <td>
                  <span class="badge <?= badge_class($p['status']) ?>">
                    <?= $p['status'] ?>
                  </span>
                </td>
                <td>
                  <div style="display:flex;gap:6px">
                    <button class="btn btn-sm"
                      onclick="openEditModal(<?= htmlspecialchars(json_encode($p), ENT_QUOTES, 'UTF-8') ?>)">Edit</button>
                    <button class="btn btn-sm btn-danger"
                      onclick="deleteProduct(<?= $p['product_id'] ?>)">Padam</button>
                  </div>
                </td>
              </tr>
              <?php endforeach ?>
              <?php if (empty($products)): ?>
              <tr>
                <td colspan="7">
                  <div class="empty-state">
                    <div style="font-size:32px;opacity:.3;margin-bottom:10px">📦</div>
                    <div style="font-weight:500">Tiada produk lagi</div>
                    <div style="font-size:12px;margin-top:4px">
                      Klik "+ Tambah" untuk mula menjual
                    </div>
                  </div>
                </td>
              </tr>
              <?php endif ?>
            </tbody>
          </table>
        </div>
        <div id="seller-products-pagination"></div>
      </div><!-- end tab-produk -->


      <!-- ═══════════════════════════════════════════════════
           TAB: PESANAN DITERIMA
      ════════════════════════════════════════════════════ -->
      <div id="tab-pesanan" style="display:none">
        <div class="section-hd">
          <div style="font-family:'DM Serif Display',serif;font-size:20px">
            Pesanan Diterima
          </div>
        </div>

        <div class="filters" id="order-filters">
          <?php foreach(['Semua','Menunggu','Disahkan','Selesai','Dibatalkan'] as $s): ?>
          <button class="filter-btn <?= $s==='Semua'?'active':'' ?>"
                  onclick="filterOrders(this,'<?= $s ?>')"><?= $s ?></button>
          <?php endforeach ?>
        </div>

        <div class="tbl-wrap">
          <table class="tbl">
            <thead>
              <tr>
                <th>No. Pesanan</th><th>Produk</th><th>Pembeli</th>
                <th>Jumlah</th><th>Bayaran</th><th>Status</th><th>Masa</th><th>Tindakan</th>
              </tr>
            </thead>
            <tbody id="orders-tbody">
              <tr>
                <td colspan="8" style="text-align:center;padding:20px;color:var(--ink3)">
                  <span class="spinner"></span> Memuatkan pesanan...
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <div id="seller-orders-pagination"></div>
      </div><!-- end tab-pesanan -->

      <!-- ── TAB: SEMBANG / CHAT ───────────────────────────── -->
      <div id="tab-chat" style="display:none">
        <div class="section-hd" style="margin-bottom:20px">
          <div>
            <div style="font-family:'DM Serif Display',serif;font-size:20px">Sembang</div>
            <div style="font-size:12px;color:var(--ink3);margin-top:2px">Berkomunikasi dengan pembeli mengenai produk anda.</div>
          </div>
        </div>

        <!-- Chat Container Layout -->
        <div style="background:#fff; border:1px solid var(--cream3); border-radius:var(--r); display:grid; grid-template-columns:220px 1fr; height:500px; overflow:hidden;">
          <!-- Left Panel: Contacts List -->
          <div style="border-right:1px solid var(--cream3); display:flex; flex-direction:column; background:var(--cream);">
            <div style="padding:12px; font-weight:600; border-bottom:1px solid var(--cream3); font-size:12px; color:var(--ink2);">Senarai Mesej Pembeli</div>
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
                <div style="font-weight:500;">Pilih pembeli untuk memulakan sembang</div>
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


<!-- ═══════════════════════════════════════════════════════════
     MODAL: ADD PRODUCT
════════════════════════════════════════════════════════════ -->
<div id="modal-add" class="modal-bg" style="display:none">
  <div class="modal">
    <div class="modal-title">Tambah Produk Baharu</div>
    <div class="modal-sub">Isi maklumat produk. Semua medan bertanda * wajib diisi.</div>

    <div class="form-group">
      <label class="form-label">Nama Produk *</label>
      <input class="form-input" id="add-name" type="text"
             placeholder="cth. Laptop Dell Inspiron 15">
    </div>
    <div class="form-grid">
      <div class="form-group">
        <label class="form-label">Kategori *</label>
        <select class="form-input" id="add-category">
          <?php foreach(['Elektronik','Buku','Pakaian','Makanan','Perkhidmatan','Lain-lain'] as $c): ?>
          <option value="<?= $c ?>"><?= $c ?></option>
          <?php endforeach ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Status</label>
        <select class="form-input" id="add-status">
          <option value="Aktif">Aktif</option>
          <option value="Tidak Aktif">Tidak Aktif</option>
        </select>
      </div>
    </div>
    <div class="form-grid">
      <div class="form-group">
        <label class="form-label">Harga (RM) *</label>
        <input class="form-input" id="add-price" type="number"
               min="0.01" step="0.01" placeholder="0.00">
      </div>
      <div class="form-group">
        <label class="form-label">Stok *</label>
        <input class="form-input" id="add-stock" type="number"
               min="0" placeholder="1" value="1">
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Penerangan</label>
      <textarea class="form-input" id="add-desc" rows="3"
                style="resize:vertical"
                placeholder="Huraikan produk anda..."></textarea>
    </div>
    <div class="form-group">
      <label class="form-label">Gambar Produk (JPG/PNG/WEBP, maks 2MB)</label>
      <input class="form-input" id="add-image" type="file"
             accept="image/jpeg,image/png,image/webp"
             onchange="previewImage(this,'add-preview')">
      <img id="add-preview" class="img-preview" alt="Pratonton gambar">
    </div>
    <div id="add-errors" style="color:var(--red);font-size:12px;margin-bottom:8px"></div>
    <div class="modal-footer">
      <button class="btn" onclick="closeModal('modal-add')">Batal</button>
      <button class="btn btn-primary" id="add-submit-btn" onclick="submitAdd()">
        Simpan Produk
      </button>
    </div>
  </div>
</div>


<!-- ═══════════════════════════════════════════════════════════
     MODAL: EDIT PRODUCT
════════════════════════════════════════════════════════════ -->
<div id="modal-edit" class="modal-bg" style="display:none">
  <div class="modal">
    <div class="modal-title">Kemas Kini Produk</div>
    <div class="modal-sub">Ubah maklumat produk. Gambar baharu akan menggantikan gambar lama.</div>

    <input type="hidden" id="edit-id">
    <div class="form-group">
      <label class="form-label">Nama Produk *</label>
      <input class="form-input" id="edit-name" type="text">
    </div>
    <div class="form-grid">
      <div class="form-group">
        <label class="form-label">Kategori *</label>
        <select class="form-input" id="edit-category">
          <?php foreach(['Elektronik','Buku','Pakaian','Makanan','Perkhidmatan','Lain-lain'] as $c): ?>
          <option value="<?= $c ?>"><?= $c ?></option>
          <?php endforeach ?>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Status</label>
        <select class="form-input" id="edit-status">
          <option value="Aktif">Aktif</option>
          <option value="Tidak Aktif">Tidak Aktif</option>
        </select>
      </div>
    </div>
    <div class="form-grid">
      <div class="form-group">
        <label class="form-label">Harga (RM) *</label>
        <input class="form-input" id="edit-price" type="number" min="0.01" step="0.01">
      </div>
      <div class="form-group">
        <label class="form-label">Stok *</label>
        <input class="form-input" id="edit-stock" type="number" min="0">
      </div>
    </div>
    <div class="form-group">
      <label class="form-label">Penerangan</label>
      <textarea class="form-input" id="edit-desc" rows="3"
                style="resize:vertical"></textarea>
    </div>
    <div class="form-group">
      <label class="form-label">Tukar Gambar (kosongkan untuk mengekalkan gambar lama)</label>
      <input class="form-input" id="edit-image" type="file"
             accept="image/jpeg,image/png,image/webp"
             onchange="previewImage(this,'edit-preview')">
      <img id="edit-preview" class="img-preview" alt="Pratonton gambar">
    </div>
    <div id="edit-errors" style="color:var(--red);font-size:12px;margin-bottom:8px"></div>
    <div class="modal-footer">
      <button class="btn" onclick="closeModal('modal-edit')">Batal</button>
      <button class="btn btn-primary" id="edit-submit-btn" onclick="submitEdit()">
        Simpan Perubahan
      </button>
    </div>
  </div>
</div>

<!-- ═══════════════════════════════════════════════════════════
     MODAL: SAHKAN PENERIMAAN BAYARAN TUNAI
     Records physical cash receipt (payment_status unpaid_cash ->
     paid_cash) so "Sahkan" becomes available on a cash order.
════════════════════════════════════════════════════════════ -->
<div id="modal-cash" class="modal-bg" style="display:none">
  <div class="modal">
    <div class="modal-title">Sahkan Penerimaan Bayaran Tunai</div>
    <div class="modal-sub" id="cash-sub">Pesanan</div>

    <div class="form-group">
      <label class="form-label">Jumlah Diterima (RM)</label>
      <input class="form-input" id="cash-amount" type="number"
             step="0.01" min="0.01" style="max-width:180px">
      <div style="font-size:11px;color:var(--ink3);margin-top:4px">
        Mesti sepadan dengan jumlah pesanan.
      </div>
    </div>

    <div class="form-group">
      <label class="form-label">Kaedah Penerimaan</label>
      <select class="form-input" id="cash-method">
        <option>Serah tangan</option>
        <option>Deposit ke akaun bank</option>
        <option>Diterima melalui e-dompet</option>
      </select>
    </div>

    <div class="form-group">
      <label class="form-label">Nota (pilihan)</label>
      <input class="form-input" id="cash-notes" type="text" maxlength="255"
             placeholder="cth. Serah di perpustakaan FKEE">
    </div>

    <div style="display:flex;justify-content:flex-end;gap:8px">
      <button class="btn" onclick="closeCashModal()">Batal</button>
      <button class="btn btn-primary" id="cash-submit-btn"
              onclick="submitCashConfirm()">Sahkan Penerimaan</button>
    </div>
  </div>
</div>

<div class="toast" id="toast"></div>

<!-- ═══════════════════════════════════════════════════════════
     JAVASCRIPT
════════════════════════════════════════════════════════════ -->
<script>
// ── Tab switching ─────────────────────────────────────────────
const TABS = ['dash','produk','pesanan','chat'];
const TITLES = {
  dash:    'Papan Pemuka',
  produk:  'Produk Saya',
  pesanan: 'Pesanan Diterima',
  chat:    'Sembang',
};

function escapeHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

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
    // Guard against a TABS entry without a rendered container (same crash
    // class as admin.php's old 'pulangan' entry).
    const el = document.getElementById('tab-' + t);
    if (el) el.style.display = t === name ? 'block' : 'none';
  });
  document.querySelectorAll('.nav-item').forEach(el => {
    el.classList.toggle('active',
      el.getAttribute('onclick') === `showTab('${name}')` || el.id === `nav-${name}`);
  });
  document.getElementById('topbar-title').textContent = TITLES[name];

  if (name === 'pesanan') {
    loadOrders(_currentOrdersFilter, _currentOrdersPage);
  }

  if (name === 'produk' && !window._sellerProductsLoaded) {
    loadSellerProducts('', 1);
    window._sellerProductsLoaded = true;
  }

  if (name === 'chat') {
    loadChatContacts();
    startChatPolling();
  } else {
    stopChatPolling();
  }
}

// ── Toast notification ────────────────────────────────────────
function showToast(msg, type = '') {
  const t = document.getElementById('toast');
  if (!t) return;
  t.textContent = msg;
  t.className   = `toast show ${type}`;
  setTimeout(() => t.classList.remove('show'), 3200);
}

// ── API helper ────────────────────────────────────────────────
async function api(action, params = {}, body = null, isFormData = false) {
  const url = `?action=${action}&${new URLSearchParams(params)}`;
  const opts = { method: body ? 'POST' : 'GET' };
  if (body) {
    opts.body    = isFormData ? body : new URLSearchParams(body);
    opts.headers = isFormData ? {} : { 'Content-Type':'application/x-www-form-urlencoded' };
  }
  // Always signal we want JSON so auth_check.php returns JSON on session expiry
  opts.headers = Object.assign({ 'Accept': 'application/json' }, opts.headers || {});
  const res  = await fetch(url, opts);
  const text = await res.text();
  try {
    return JSON.parse(text);
  } catch (e) {
    console.error('[api] Non-JSON response for action=' + action + ':', text.slice(0, 300));
    return { success: false, message: 'Ralat pelayan: respons tidak sah.' };
  }
}

// ── Cash receipt confirmation ─────────────────────────────────
// update_order_status refuses to approve a cash order while
// payment_status = 'unpaid_cash' (422 + action_required). This modal is the
// only client path into the confirm_cash_payment endpoint, so without it a
// cash order is stranded: the seller has the money but the system cannot know.
let cashTxnId = null;

function openCashModal(txn_id, amount) {
  cashTxnId = txn_id;
  const m = document.getElementById('modal-cash');
  if (!m) return;
  document.getElementById('cash-sub').textContent = `Pesanan #${txn_id} — RM ${amount}`;
  // Pre-fill the exact total: the server rejects any amount that does not
  // match total_amount within RM0.01, so the default must be the right number.
  document.getElementById('cash-amount').value = amount;
  document.getElementById('cash-method').value = 'Serah tangan';
  document.getElementById('cash-notes').value  = '';
  m.style.display = 'flex';
}

function closeCashModal() {
  const m = document.getElementById('modal-cash');
  if (m) m.style.display = 'none';
  cashTxnId = null;
}

async function submitCashConfirm() {
  if (!cashTxnId) return;
  const txn_id = cashTxnId;
  const amount = document.getElementById('cash-amount').value;
  const btn    = document.getElementById('cash-submit-btn');

  if (!amount || parseFloat(amount) <= 0) {
    showToast('Sila masukkan jumlah yang diterima.', 'error');
    return;
  }

  btn.disabled = true;
  btn.textContent = 'Menyimpan...';
  let data;
  try {
    data = await api('confirm_cash_payment', {}, {
      transaction_id:      txn_id,
      amount_received:     amount,
      payment_method_detail: document.getElementById('cash-method').value,
      seller_notes:        document.getElementById('cash-notes').value,
    });
  } finally {
    btn.disabled = false;
    btn.textContent = 'Sahkan Penerimaan';
  }

  if (!data.success) {
    showToast(data.message || 'Gagal mengesahkan pembayaran tunai.', 'error');
    return;
  }

  closeCashModal();
  showToast(data.message, 'success');

  // Recording the cash receipt is exactly what clears one "perlu tindakan" item
  // from the badge, so the counters must be re-read like every other action.
  refreshStats();

  // Swap the row in place: payment badge goes green and the now-unblocked
  // "Sahkan" button becomes live. The same order can appear in both the
  // dashboard quick-view and the Pesanan tab, hence the shared data-order-row key.
  document.querySelectorAll(`[data-order-row="${txn_id}"]`).forEach(row => {
    const pay = row.querySelector('[data-role="pay-badge"]');
    if (pay) {
      pay.className = 'badge badge-green';
      pay.style.whiteSpace = 'nowrap';
      pay.innerHTML = '<span class="badge-dot"></span>Dibayar (Tunai)';
    }
    const btns = row.querySelector('[data-role="actions"]');
    if (btns) {
      btns.innerHTML = `
        <button class="btn btn-success btn-sm"
          onclick="updateOrder(${txn_id},'Disahkan')">Sahkan</button>
        <button class="btn btn-danger btn-sm"
          onclick="updateOrder(${txn_id},'Dibatalkan')">Tolak</button>`;
    }
  });
}

// ── Order actions ─────────────────────────────────────────────
async function updateOrder(txn_id, status) {
  const labels = {
    Disahkan:  'Sahkan pesanan ini?',
    Selesai:   'Tandakan pesanan sebagai selesai?',
    Dibatalkan:'Tolak / batalkan pesanan ini?',
  };
  if (!confirm(labels[status] || 'Teruskan?')) return;

  let data;
  try {
    const url = `?action=update_order_status`;
    const body = new URLSearchParams({ transaction_id: txn_id, status });
    const res = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'Accept': 'application/json'
      },
      body: body
    });
    const text = await res.text();
    try {
      data = JSON.parse(text);
    } catch(e) {
      alert('RALAT: Respons pelayan bukan JSON.\n\nHTTP Status: ' + res.status + '\nRespons:\n' + text.slice(0, 500));
      return;
    }
  } catch (err) {
    alert('RALAT SAMBUNGAN: ' + err.message);
    return;
  }

  if (data.success) {
    showToast(data.message || 'Status dikemas kini!', 'success');

    // The same order can appear in BOTH the dashboard quick-view table and the
    // "Pesanan Diterima" tab. They now share a data-order-row key (not duplicate
    // ids), so refresh every matching row in place without a full reload.
    const badgeClass = {
      Menunggu:'badge-gold', Disahkan:'badge-blue',
      Selesai:'badge-green', Dibatalkan:'badge-red',
    }[status] || 'badge-gray';

    document.querySelectorAll(`[data-order-row="${txn_id}"]`).forEach(row => {
      const badge = row.querySelector('[data-role="status-badge"]');
      if (badge) {
        badge.className = `badge ${badgeClass}`;
        badge.innerHTML = `<span class="badge-dot"></span>${status}`;
      }

      const btns = row.querySelector('[data-role="actions"]');
      if (btns) {
        if (status === 'Disahkan') {
          btns.innerHTML = `
            <span style="font-size:11px;color:var(--ink3)">Menunggu pengesahan pembeli</span>
            <button class="btn btn-danger btn-sm"
              onclick="updateOrder(${txn_id},'Dibatalkan')">Batalkan</button>`;
        } else {
          btns.innerHTML = `<span style="font-size:11px;color:var(--ink4)">${status}</span>`;
        }
      }
    });

    // Refresh top stats quietly
    refreshStats();
  } else {
    showToast(data.message || 'Ralat tidak diketahui.', 'error');
    // Also alert so user sees it clearly
    alert('Gagal: ' + (data.message || 'Ralat tidak diketahui.'));
  }
}

// ── Pending-action badge ──────────────────────────────────────
// Number-only updater generated by the same helper that printed the badges, so
// the label can never be re-worded differently after an AJAX re-read.
<?= notify_js('notif') ?>

async function refreshStats() {
  const data = await api('get_stats');
  if (!data.success) return;
  const s = data.stats;
  document.getElementById('sc-trust').textContent    = Math.round(s.trust_score);
  document.getElementById('sc-revenue').textContent  = 'RM ' + Math.round(s.revenue).toLocaleString('ms-MY');
  document.getElementById('sc-pending').textContent  = s.counts['Menunggu'] || 0;
  document.getElementById('topbar-trust').textContent= s.trust_score.toFixed(1) + '/100';
  renderPendingNotify(s.notify);
}


// ── Orders table (pesanan tab) ────────────────────────────────
let _currentOrdersFilter = '';
let _currentOrdersPage   = 1;

// Payment badge mirroring the server-side payment_status_info() mapping, so
// the seller sees paid vs unpaid in the orders list without opening each row.
function payInfo(o) {
  const map = {
    paid:        ['Dibayar',         'badge-green'],
    paid_cash:   ['Dibayar (Tunai)', 'badge-green'],
    pending:     ['Menunggu Bayaran', 'badge-gold'],
    unpaid:      ['Belum Bayar',     'badge-red'],
    unpaid_cash: ['Tunai: Belum Dibayar',    'badge-gold'],
    failed:      ['Bayaran Gagal',   'badge-red'],
    refunded:    ['Dipulangkan',     'badge-blue'],
  };
  const s = o.payment_status;
  if (!s) return '<span class="badge badge-gray" data-role="pay-badge"><span class="badge-dot"></span>—</span>';
  const m = map[s] || [s, 'badge-gray'];
  return `<span class="badge ${m[1]}" data-role="pay-badge" style="white-space:nowrap"><span class="badge-dot"></span>${m[0]}</span>`;
}

async function loadOrders(statusFilter = _currentOrdersFilter, page = _currentOrdersPage) {
  _currentOrdersFilter = statusFilter;
  _currentOrdersPage   = page;

  const tbody = document.getElementById('orders-tbody');
  tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:20px;color:var(--ink3)"><span class="spinner"></span> Memuatkan pesanan...</td></tr>`;

  const data = await api('get_orders', { status: statusFilter, page, per_page: 10 });
  if (!data.success) {
    tbody.innerHTML = `<tr><td colspan="8" style="text-align:center;padding:20px;color:var(--red)">Gagal memuatkan pesanan.</td></tr>`;
    return;
  }

  if (!data.orders.length) {
    tbody.innerHTML = `<tr><td colspan="8">
      <div class="empty-state">
        <div style="font-size:32px;opacity:.3;margin-bottom:10px">📋</div>
        <div style="font-weight:500">Tiada pesanan</div>
      </div></td></tr>`;
    renderPagination('seller-orders-pagination', 0, 0, 0, 'goToSellerOrderPage', 'pesanan');
    return;
  }

  tbody.innerHTML = data.orders.map(o => {
    const badgeClass = {
      Menunggu:'badge-gold', Disahkan:'badge-blue',
      Selesai:'badge-green', Dibatalkan:'badge-red',
    }[o.status] || 'badge-gray';

    // L5: buyer trust comes from trust_profiles (pembeli). No evidence -> Baharu.
    const btVal = parseFloat(o.buyer_trust || 0);
    const btEv  = parseFloat(o.buyer_trust_evidence || 0);
    const btText = btEv > 0 ? Math.round(btVal) : 'Baharu';
    const trustColor = btEv <= 0 ? 'var(--ink4)'
                      : (btVal >= 85 ? 'var(--green)' : (btVal >= 70 ? 'var(--gold)' : 'var(--red)'));

    let actions = '';
    if (o.status === 'Menunggu') {
      // Mirrors the server-rendered dashboard rows: a cash order cannot be
      // approved until receipt is recorded, so offer the cash modal instead of
      // a "Sahkan" the server would reject with 422.
      if (o.payment_status === 'unpaid_cash') {
        actions = `
        <button class="btn btn-primary btn-sm"
          onclick="openCashModal(${o.transaction_id}, '${parseFloat(o.total_amount).toFixed(2)}')">Sahkan Terima Tunai</button>
        <button class="btn btn-success btn-sm" disabled
          title="Sahkan penerimaan tunai dahulu sebelum mengesahkan pesanan.">Sahkan</button>`;
      } else {
        actions = `
        <button class="btn btn-success btn-sm"
          onclick="updateOrder(${o.transaction_id},'Disahkan')">Sahkan</button>`;
      }
      actions += `
        <button class="btn btn-danger btn-sm"
          onclick="updateOrder(${o.transaction_id},'Dibatalkan')">Tolak</button>`;
    } else if (o.status === 'Disahkan') {
      actions = `
        <span style="font-size:11px;color:var(--ink3)">Menunggu pengesahan pembeli</span>
        <button class="btn btn-danger btn-sm"
          onclick="updateOrder(${o.transaction_id},'Dibatalkan')">Batalkan</button>`;
    } else if (o.status === 'Selesai' && o.rating) {
      actions = `<span style="color:var(--gold)">${'★'.repeat(o.rating)}${'☆'.repeat(5-o.rating)}</span>`;
    } else {
      actions = `<span style="font-size:11px;color:var(--ink4)">${o.status}</span>`;
    }

    return `<tr data-order-row="${o.transaction_id}">
      <td style="font-size:11px;color:var(--ink3)">#${o.transaction_id}</td>
      <td>
        <div style="font-weight:500;max-width:150px;white-space:nowrap;
                    overflow:hidden;text-overflow:ellipsis">${escapeHtml(o.product_name)}</div>
        <div style="font-size:11px;color:var(--ink3)">${escapeHtml(o.category)}</div>
      </td>
      <td>
        <div>${escapeHtml(o.buyer_name)}</div>
        <div style="font-size:11px;color:var(--ink3)">
          ${escapeHtml(o.buyer_ident)} · <span style="font-weight:600;color:${trustColor}">Skor Pembeli: ${btText}</span>
        </div>
      </td>
      <td>RM ${parseFloat(o.total_amount).toFixed(2)}</td>
      <td style="white-space:nowrap">${payInfo(o)}</td>
      <td><span class="badge ${badgeClass}" data-role="status-badge">
        <span class="badge-dot"></span>${o.status}
      </span></td>
      <td style="font-size:11px;color:var(--ink3)">
        ${new Date(o.created_at).toLocaleDateString('ms-MY',
          {day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'})}
      </td>
      <td><div style="display:flex;gap:6px" data-role="actions">${actions}</div></td>
    </tr>`;
  }).join('');

  if (data.pagination) {
    renderPagination(
      'seller-orders-pagination',
      data.pagination.page,
      data.pagination.total_pages,
      data.pagination.total,
      'goToSellerOrderPage',
      'pesanan'
    );
  }
}

function goToSellerOrderPage(page) {
  loadOrders(_currentOrdersFilter, page);
}

function filterOrders(el, status) {
  document.querySelectorAll('#order-filters .filter-btn')
    .forEach(b => b.classList.remove('active'));
  el.classList.add('active');
  loadOrders(status === 'Semua' ? '' : status, 1);
}

// ── Products table (produk tab) ───────────────────────────────
let _currentProductFilter = '';
let _currentProductPage   = 1;
window._sellerProducts    = [];

async function loadSellerProducts(statusFilter = _currentProductFilter, page = _currentProductPage) {
  _currentProductFilter = statusFilter;
  _currentProductPage   = page;

  const tbody = document.getElementById('products-tbody');
  tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:20px;color:var(--ink3)"><span class="spinner"></span> Memuatkan produk...</td></tr>`;

  const data = await api('get_products', { status: statusFilter, page, per_page: 10 });
  if (!data.success) {
    tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:20px;color:var(--red)">Gagal memuatkan produk.</td></tr>`;
    return;
  }

  window._sellerProducts = data.products;

  if (!data.products.length) {
    tbody.innerHTML = `<tr><td colspan="7">
      <div class="empty-state">
        <div style="font-size:32px;opacity:.3;margin-bottom:10px">📦</div>
        <div style="font-weight:500">Tiada produk lagi</div>
        <div style="font-size:12px;margin-top:4px">Klik "+ Tambah" untuk mula menjual</div>
      </div></td></tr>`;
    renderPagination('seller-products-pagination', 0, 0, 0, 'goToSellerProductPage', 'produk');
    return;
  }

  tbody.innerHTML = data.products.map(p => {
    const imgHtml = p.image_path
      ? `<img src="../uploads/${escapeHtml(p.image_path)}" alt="" style="width:36px;height:36px;object-fit:cover;border-radius:6px;flex-shrink:0">`
      : `<div style="width:36px;height:36px;background:var(--cream2);border-radius:6px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:16px">📦</div>`;

    const badgeCls = {
      'Aktif': 'badge-green',
      'Tidak Aktif': 'badge-gray'
    }[p.status] || 'badge-gray';

    const stockHtml = (p.stock === '0' || p.stock == 0)
      ? `<span style="color:var(--red);font-weight:500">0</span>`
      : p.stock;

    return `
      <tr id="prod-row-${p.product_id}">
        <td>
          <div style="display:flex;align-items:center;gap:10px">
            ${imgHtml}
            <div>
              <div style="font-weight:500;max-width:160px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                ${escapeHtml(p.name)}
              </div>
              <div style="font-size:10px;color:var(--ink4)">
                ID #${p.product_id}
              </div>
            </div>
          </div>
        </td>
        <td>${escapeHtml(p.category)}</td>
        <td>RM ${parseFloat(p.price).toFixed(2)}</td>
        <td>${stockHtml}</td>
        <td>${p.sold_count}</td>
        <td><span class="badge ${badgeCls}">${p.status}</span></td>
        <td>
          <div style="display:flex;gap:6px">
            <button class="btn btn-sm" onclick="openEditModalById(${p.product_id})">Edit</button>
            <button class="btn btn-sm btn-danger" onclick="deleteProduct(${p.product_id})">Padam</button>
          </div>
        </td>
      </tr>
    `;
  }).join('');

  if (data.pagination) {
    renderPagination(
      'seller-products-pagination',
      data.pagination.page,
      data.pagination.total_pages,
      data.pagination.total,
      'goToSellerProductPage',
      'produk'
    );
  }
}

function openEditModalById(productId) {
  const p = (window._sellerProducts || []).find(x => x.product_id == productId);
  if (p) openEditModal(p);
}

function goToSellerProductPage(page) {
  loadSellerProducts(_currentProductFilter, page);
}

function filterProducts(el, status) {
  document.querySelectorAll('.filters .filter-btn')
    .forEach(b => b.classList.remove('active'));
  el.classList.add('active');
  loadSellerProducts(status, 1);
}

// ── Add product modal ─────────────────────────────────────────
function openAddModal() {
  document.getElementById('add-name').value    = '';
  document.getElementById('add-price').value   = '';
  document.getElementById('add-stock').value   = '1';
  document.getElementById('add-desc').value    = '';
  document.getElementById('add-image').value   = '';
  document.getElementById('add-errors').textContent = '';
  document.getElementById('add-preview').style.display = 'none';
  document.getElementById('modal-add').style.display = 'flex';
}

async function submitAdd() {
  const btn  = document.getElementById('add-submit-btn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span>Menyimpan...';
  document.getElementById('add-errors').textContent = '';

  const fd = new FormData();
  fd.append('name',        document.getElementById('add-name').value);
  fd.append('category',    document.getElementById('add-category').value);
  fd.append('description', document.getElementById('add-desc').value);
  fd.append('price',       document.getElementById('add-price').value);
  fd.append('stock',       document.getElementById('add-stock').value);
  const img = document.getElementById('add-image').files[0];
  if (img) fd.append('image', img);

  const data = await api('add_product', {}, fd, true);

  if (data.success) {
    showToast(data.message, 'success');
    closeModal('modal-add');
    setTimeout(() => location.reload(), 800);
  } else {
    const msg = data.errors ? data.errors.join(' · ') : data.message;
    document.getElementById('add-errors').textContent = msg;
    btn.disabled = false;
    btn.textContent = 'Simpan Produk';
  }
}

// ── Edit product modal ────────────────────────────────────────
function openEditModal(product) {
  document.getElementById('edit-id').value       = product.product_id;
  document.getElementById('edit-name').value     = product.name;
  document.getElementById('edit-category').value = product.category;
  document.getElementById('edit-status').value   = product.status;
  document.getElementById('edit-price').value    = product.price;
  document.getElementById('edit-stock').value    = product.stock;
  document.getElementById('edit-desc').value     = product.description || '';
  document.getElementById('edit-image').value    = '';
  document.getElementById('edit-errors').textContent = '';

  // Show current image if any
  const preview = document.getElementById('edit-preview');
  if (product.image_path) {
    preview.src   = '/uploads/' + product.image_path;
    preview.style.display = 'block';
  } else {
    preview.style.display = 'none';
  }

  document.getElementById('modal-edit').style.display = 'flex';
}

async function submitEdit() {
  const btn = document.getElementById('edit-submit-btn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner"></span>Menyimpan...';
  document.getElementById('edit-errors').textContent = '';

  const fd = new FormData();
  fd.append('product_id',  document.getElementById('edit-id').value);
  fd.append('name',        document.getElementById('edit-name').value);
  fd.append('category',    document.getElementById('edit-category').value);
  fd.append('description', document.getElementById('edit-desc').value);
  fd.append('price',       document.getElementById('edit-price').value);
  fd.append('stock',       document.getElementById('edit-stock').value);
  fd.append('status',      document.getElementById('edit-status').value);
  const img = document.getElementById('edit-image').files[0];
  if (img) fd.append('image', img);

  const data = await api('edit_product', {}, fd, true);

  if (data.success) {
    showToast(data.message, 'success');
    closeModal('modal-edit');
    setTimeout(() => location.reload(), 800);
  } else {
    const msg = data.errors ? data.errors.join(' · ') : data.message;
    document.getElementById('edit-errors').textContent = msg;
    btn.disabled = false;
    btn.textContent = 'Simpan Perubahan';
  }
}

// ── Delete product ────────────────────────────────────────────
async function deleteProduct(product_id) {
  if (!confirm('Padamkan produk ini? Tindakan ini tidak boleh dibatalkan.')) return;
  const data = await api('delete_product', {}, { product_id });
  showToast(data.message, data.success ? 'success' : 'error');
  if (data.success) {
    const row = document.getElementById('prod-row-' + product_id);
    if (row) row.remove();
  }
}

// ── Image preview ─────────────────────────────────────────────
function previewImage(input, previewId) {
  const preview = document.getElementById(previewId);
  if (input.files && input.files[0]) {
    const reader = new FileReader();
    reader.onload = e => {
      preview.src = e.target.result;
      preview.style.display = 'block';
    };
    reader.readAsDataURL(input.files[0]);
  }
}

// ── Modal helpers ─────────────────────────────────────────────
function closeModal(id) {
  document.getElementById(id).style.display = 'none';
}

function openSellerTrustModal() {
  document.getElementById('seller-trust-modal').style.display = 'flex';
}
</script>
<!-- SELLER TRUST MODAL -->
<div id="seller-trust-modal" class="modal-bg" style="display:none">
  <div class="modal" style="width:600px">
    <div class="modal-title">Skor Kepercayaan Pintar Penjual</div>
    <div class="modal-sub">Algoritma Bayesian yang boleh diterangkan · <?= htmlspecialchars($seller_trust['formula_version']) ?></div>

    <div style="background:var(--cream);padding:16px;border-radius:var(--r);margin-bottom:16px;text-align:center">
      <div style="font-size:11px;color:var(--ink3);text-transform:uppercase;margin-bottom:4px">Skor berasaskan rekod urus niaga</div>
      <div style="font-size:36px;font-weight:600;color:<?= trust_score_color($seller_trust) ?>">
        <?= htmlspecialchars(trust_score_display($seller_trust, 1)) ?>
      </div>
      <div style="font-size:12px;color:var(--ink3);margin-top:5px">
        <?= htmlspecialchars(trust_summary_text($seller_trust)) ?> · Kualiti rekod <?= number_format($seller_trust['raw_score'],1) ?>/100
      </div>
    </div>

    <div style="font-size:13px;line-height:1.6;color:var(--ink2)">
      <?= trust_explained_html($seller_trust, 'pembeli') ?>
      <?= trust_components_html($seller_trust) ?>
      <?= ai_trust_detail_html(ai_trust_fetch($pdo, (int)$seller_id, 'penjual')) ?>
      <div style="background:var(--gold-pale);padding:12px;border-radius:var(--r);font-size:11px;color:var(--ink3)">
        <b>Perlindungan manipulasi:</b> hanya 3 urus niaga pertama dengan pembeli
        yang sama dikira — lebih daripada itu tidak menaikkan skor anda.
        Pembatalan pembeli tidak menjejaskan penjual; hanya
        pembatalan oleh penjual dan aduan yang diputuskan memihak pembeli dikira.
        Tindak balas bermaksud membuat keputusan pesanan dalam 12 jam, bukan sekadar membuka mesej.
      </div>
    </div>

    <div class="modal-footer">
      <button class="btn btn-primary" onclick="closeModal('seller-trust-modal')">Tutup</button>
    </div>
  </div>
</div>

<script>
// Close modals on backdrop click
['modal-add','modal-edit','seller-trust-modal'].forEach(id => {
  const modalEl = document.getElementById(id);
  if (modalEl) {
    modalEl.addEventListener('click', function(e) {
      if (e.target === this) closeModal(id);
    });
  }
});

// Close modals on Escape key
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') {
    ['modal-add','modal-edit','seller-trust-modal'].forEach(closeModal);
  }
});

// ── Chat System Frontend ─────────────────────────────────────
window.activeContactId = null;
window.chatPollInterval = null;

function escapeHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
}

async function loadChatContacts(selectedContactId = window.activeContactId, preloaded = null) {
  try {
    // The poller already holds the contact payload from the combined `sync`
    // call, so it passes it through instead of issuing a second request.
    const data = preloaded ? { success: true, contacts: preloaded }
                           : await (await fetch('../chat_api.php?action=get_contacts')).json();
    if (!data.success) return;

    const listEl = document.getElementById('chat-contacts-list');
    if (!data.contacts.length) {
      listEl.innerHTML = '<div class="empty-state" style="padding: 20px; text-align: center; color: var(--ink3); font-size:12px;">Tiada sembang lagi.</div>';
      return;
    }

    // The poll rewrites this list every few seconds; without restoring focus,
    // a keyboard user gets thrown back to the top of the page mid-navigation.
    const focusedId = document.activeElement &&
      document.activeElement.closest('#chat-contacts-list')
      ? document.activeElement.closest('[data-contact-id]')?.dataset.contactId : null;

    listEl.innerHTML = data.contacts.map(c => {
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

  // Update header UI
  document.getElementById('chat-header-name').innerHTML = escapeHtml(fullName) + 
    (isVerified == 1 ? ' <span style="background:var(--gold-pale); color:#7A5A10; padding:1px 7px; border-radius:10px; font-size:9px; font-weight:normal; margin-left:4px;">✓ Disahkan PTU</span>' : '');

  const headerTrust = document.getElementById('chat-header-trust');
  if (trustDisplay) {
    // trustDisplay + hasScore come from the same server-side trust_profiles
    // source as the product page, so a new buyer reads "Baharu" here too.
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
          Mula bersembang dengan pembeli ini.
        </div>
      `;
      return;
    }

    const myId = <?= $seller_id ?>;
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

function startChatPolling() {
  if (window.chatPollInterval) clearInterval(window.chatPollInterval);
  window.chatPollInterval = setInterval(pollChatSync, 4000);

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

  // Cheap badge refresh for users sitting on another tab.
  setInterval(() => {
    if (document.getElementById('tab-chat').style.display !== 'block') {
      refreshChatBadge();
    }
  }, 10000);
});
</script>
</body>
</html>