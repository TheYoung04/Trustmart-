<?php
/**
 * admin_returns.php
 * Admin interface for managing return shipments
 * Handles return verification and refund release
 */

require_once __DIR__ . '/../includes/auth_check.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/payment.php';

require_role(['pentadbir']);

$user = current_user();

// ── AJAX action router ────────────────────────────────────────
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] === 'POST') csrf_validate();

    switch ($_GET['action']) {

        // ── Get all pending returns ───────────────────────────
        case 'get_pending_returns':
            try {
                // L9: a transaction can hold more than one 'completed' payment
                // (e.g. a superseded attempt that still ended completed). Joining
                // payments directly would duplicate every return row. Collapse to
                // the single latest completed payment per transaction via a
                // derived table so one return always yields exactly one row.
                $stmt = $pdo->query("
                    SELECT
                        rs.return_id,
                        rs.dispute_id,
                        rs.transaction_id,
                        rs.tracking_number,
                        rs.carrier,
                        rs.status,
                        rs.return_requested_at,
                        rs.shipped_at,
                        rs.received_at,
                        rs.notes,
                        d.title AS dispute_reason,
                        t.total_amount,
                        p.name AS product_name,
                        buyer.full_name AS buyer_name,
                        buyer.email AS buyer_email,
                        seller.full_name AS seller_name,
                        pay.payment_id,
                        pay.refund_status,
                        pay.amount AS refund_amount
                    FROM return_shipments rs
                    JOIN disputes d ON d.dispute_id = rs.dispute_id
                    JOIN transactions t ON t.transaction_id = rs.transaction_id
                    JOIN products p ON p.product_id = t.product_id
                    JOIN users buyer ON buyer.user_id = t.buyer_id
                    JOIN users seller ON seller.user_id = t.seller_id
                    LEFT JOIN (
                        SELECT p2.payment_id, p2.transaction_id, p2.refund_status, p2.amount
                          FROM payments p2
                          JOIN (
                              SELECT transaction_id, MAX(payment_id) AS latest_id
                                FROM payments
                               WHERE status = 'completed'
                               GROUP BY transaction_id
                          ) lp ON lp.latest_id = p2.payment_id
                    ) pay ON pay.transaction_id = t.transaction_id
                    WHERE rs.status IN ('pending', 'in_transit', 'received')
                    ORDER BY rs.return_requested_at DESC
                ");

                echo json_encode([
                    'success' => true,
                    'returns' => $stmt->fetchAll(PDO::FETCH_ASSOC)
                ]);
            } catch (PDOException $e) {
                error_log('[get_pending_returns] ' . $e->getMessage());
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Ralat pangkalan data']);
            }
            exit;

        // ── Update return status ──────────────────────────────
        case 'update_return_status':
            $return_id = (int)($_POST['return_id'] ?? 0);
            $new_status = trim($_POST['status'] ?? '');
            $admin_notes = trim($_POST['notes'] ?? '');

            $valid_statuses = ['in_transit', 'received', 'verified', 'cancelled'];
            if (!in_array($new_status, $valid_statuses, true)) {
                http_response_code(422);
                die(json_encode(['success' => false, 'message' => 'Status tidak sah']));
            }

            try {
                $pdo->beginTransaction();

                // Lock return record
                $stmt = $pdo->prepare("
                    SELECT rs.*, pay.payment_id, pay.refund_status
                    FROM return_shipments rs
                    JOIN payments pay ON pay.transaction_id = rs.transaction_id
                        AND pay.status = 'completed'
                    WHERE rs.return_id = ?
                    FOR UPDATE
                ");
                $stmt->execute([$return_id]);
                $return_record = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$return_record) {
                    $pdo->rollBack();
                    http_response_code(404);
                    die(json_encode(['success' => false, 'message' => 'Rekod pemulangan barang tidak ditemui']));
                }

                // Update return status with timestamp
                $timestamp_field = match($new_status) {
                    'in_transit' => 'shipped_at',
                    'received' => 'received_at',
                    'verified' => 'verified_at',
                    default => null
                };

                $update_sql = "
                    UPDATE return_shipments
                    SET status = ?,
                        notes = CONCAT(COALESCE(notes, ''), '\n[', NOW(), '] Admin: ', ?)
                ";

                if ($timestamp_field) {
                    $update_sql .= ", $timestamp_field = CURRENT_TIMESTAMP";
                }

                $update_sql .= " WHERE return_id = ?";

                $pdo->prepare($update_sql)->execute([
                    $new_status,
                    $admin_notes ?: 'Status dikemas kini kepada ' . $new_status,
                    $return_id
                ]);

                // If verified, process the refund
                if ($new_status === 'verified' && $return_record['refund_status'] === 'pending_return') {
                    // Change refund status from pending_return to requested
                    $pdo->prepare("
                        UPDATE payments
                        SET refund_status = 'requested'
                        WHERE payment_id = ?
                          AND refund_status = 'pending_return'
                    ")->execute([$return_record['payment_id']]);

                    $pdo->commit();

                    // Process refund now that return is verified
                    $refund_result = process_payment_refund(
                        $pdo,
                        $return_record['payment_id'],
                        'Pemulangan barang disahkan oleh pentadbir - pemulangan wang diteruskan',
                        'admin'
                    );

                    if ($refund_result['success']) {
                        echo json_encode([
                            'success' => true,
                            'message' => 'Pemulangan barang disahkan dan pemulangan wang diproses: ' . $refund_result['message']
                        ]);
                    } else {
                        echo json_encode([
                            'success' => true,
                            'message' => 'Pemulangan barang disahkan tetapi pemulangan wang gagal: ' . $refund_result['error'],
                            'warning' => true
                        ]);
                    }
                } else {
                    $pdo->commit();
                    echo json_encode([
                        'success' => true,
                        'message' => 'Status pemulangan barang dikemas kini kepada ' . $new_status
                    ]);
                }

            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[update_return_status] ' . $e->getMessage());
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Ralat pangkalan data']);
            }
            exit;

        // ── Waive return requirement ──────────────────────────
        case 'waive_return':
            $return_id = (int)($_POST['return_id'] ?? 0);
            $waive_reason = trim($_POST['reason'] ?? '');

            if (!$waive_reason) {
                http_response_code(422);
                die(json_encode(['success' => false, 'message' => 'Sila berikan sebab']));
            }

            try {
                $pdo->beginTransaction();

                $stmt = $pdo->prepare("
                    SELECT rs.*, pay.payment_id, pay.refund_status
                    FROM return_shipments rs
                    JOIN payments pay ON pay.transaction_id = rs.transaction_id
                        AND pay.status = 'completed'
                    WHERE rs.return_id = ?
                    FOR UPDATE
                ");
                $stmt->execute([$return_id]);
                $return_record = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$return_record) {
                    $pdo->rollBack();
                    http_response_code(404);
                    die(json_encode(['success' => false, 'message' => 'Rekod pemulangan barang tidak ditemui']));
                }

                // Cancel return requirement
                $pdo->prepare("
                    UPDATE return_shipments
                    SET status = 'cancelled',
                        notes = CONCAT(COALESCE(notes, ''), '\n[', NOW(), '] Pentadbir kecualikan pemulangan barang: ', ?)
                    WHERE return_id = ?
                ")->execute([$waive_reason, $return_id]);

                // Change payment refund status to requested (bypass return hold)
                $pdo->prepare("
                    UPDATE payments
                    SET refund_status = 'requested'
                    WHERE payment_id = ?
                      AND refund_status = 'pending_return'
                ")->execute([$return_record['payment_id']]);

                $pdo->commit();

                // Process refund
                $refund_result = process_payment_refund(
                    $pdo,
                    $return_record['payment_id'],
                    'Pemulangan barang dikecualikan oleh pentadbir: ' . $waive_reason,
                    'admin'
                );

                echo json_encode([
                    'success' => true,
                    'message' => 'Pemulangan barang dikecualikan dan pemulangan wang diproses',
                    'refund_result' => $refund_result
                ]);

            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('[waive_return] ' . $e->getMessage());
                http_response_code(500);
                echo json_encode(['success' => false, 'message' => 'Ralat pangkalan data']);
            }
            exit;

        default:
            http_response_code(400);
            die(json_encode(['success' => false, 'message' => 'Tindakan tidak diketahui']));
    }
}

// ── This file is now AJAX-only ────────────────────
// The return-management UI has been moved into admin.php as the
// "Pulangan Barang" tab (<div id="tab-barang">), so it shares the
// same sidebar / topbar / styling as every other admin console
// view. Visiting admin_returns.php directly (old bookmark, manual
// navigation) is redirected to that tab. The three AJAX endpoints
// above (get_pending_returns, update_return_status, waive_return)
// remain here and are what the tab fetches.
header('Location: admin.php?tab=barang');
exit;
