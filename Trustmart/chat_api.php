<?php
// ============================================================
// chat_api.php
// Standalone AJAX chat API for TrustMart.
// Accepts GET / POST actions. All requests must be authenticated.
// ============================================================
require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/trust_score.php';
refresh_session_user($pdo);

header('Content-Type: application/json');

// ── Rate-limit constants ──────────────────────────────────────
// Max messages a single authenticated user may send in the window below.
const CHAT_RATE_LIMIT        = 20;  // messages
const CHAT_RATE_WINDOW_SEC   = 60;  // seconds

// ── Authentication Check ─────────────────────────────────────
if (!is_logged_in()) {
    http_response_code(401);
    die(json_encode(['success' => false, 'message' => 'Sesi tamat tempoh. Sila log masuk semula.']));
}

$my_id = (int) $_SESSION['user']['user_id'];
$action = trim($_GET['action'] ?? '');

// ── Contact list query (shared by get_contacts and sync) ─────
// Find distinct users we have exchanged messages with, plus their role,
// name, verification status and role-specific Bayesian trust profile
// (same source the product page uses).
function chat_contacts(PDO $pdo, int $my_id): array
{
    $sql = "
        SELECT DISTINCT u.user_id, u.full_name, u.role, u.is_verified, u.verification_status,
               tp.score AS trust_baseline, tp.evidence_units AS trust_evidence,
               tp.ai_trust_score, tp.ai_adjustment, tp.fraud_risk,
               (SELECT COUNT(*) FROM messages m2 WHERE m2.sender_id = u.user_id AND m2.receiver_id = ? AND m2.is_read = 0) AS unread_count,
               (SELECT m3.message_text FROM messages m3 
                 WHERE (m3.sender_id = u.user_id AND m3.receiver_id = ?) 
                    OR (m3.sender_id = ? AND m3.receiver_id = u.user_id) 
                 ORDER BY m3.created_at DESC LIMIT 1) AS last_message,
               (SELECT m3.created_at FROM messages m3 
                 WHERE (m3.sender_id = u.user_id AND m3.receiver_id = ?) 
                    OR (m3.sender_id = ? AND m3.receiver_id = u.user_id) 
                 ORDER BY m3.created_at DESC LIMIT 1) AS last_message_time
          FROM users u
          LEFT JOIN trust_profiles tp
                 ON tp.user_id = u.user_id AND tp.profile_role = u.role
         WHERE u.is_active = 1
           AND u.user_id IN (
             SELECT sender_id FROM messages WHERE receiver_id = ?
             UNION
             SELECT receiver_id FROM messages WHERE sender_id = ?
         )
         ORDER BY last_message_time DESC
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$my_id, $my_id, $my_id, $my_id, $my_id, $my_id, $my_id]);
    $contacts = $stmt->fetchAll();

    foreach ($contacts as &$contact) {
        $display = trust_display_from_row($contact);
        $contact['trust_display']       = $display['label'];
        $contact['trust_display_color'] = $display['color'];
        $contact['trust_has_score']     = $display['has_score'];
    }
    unset($contact);

    return $contacts;
}

switch ($action) {

    // ── One round-trip for pollers: unread badge + contact list ──
    // The chat poll used to fire three separate requests every tick, which on
    // shared hosting means several concurrent PHP workers per logged-in user.
    // Messages are deliberately NOT included here: get_messages marks the open
    // conversation as read, and a background poll must not silently consume
    // unread state.
    case 'sync':
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0');
            $stmt->execute([$my_id]);
            $count = (int) $stmt->fetchColumn();

            echo json_encode([
                'success'  => true,
                'count'    => $count,
                'contacts' => chat_contacts($pdo, $my_id),
            ]);
        } catch (PDOException $e) {
            error_log('[chat_api] sync error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Ralat pelayan.']);
        }
        break;

    // ── Get total unread messages count for this user ────────
    case 'get_unread_count':
        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM messages WHERE receiver_id = ? AND is_read = 0');
            $stmt->execute([$my_id]);
            $count = (int) $stmt->fetchColumn();
            echo json_encode(['success' => true, 'count' => $count]);
        } catch (PDOException $e) {
            error_log('[chat_api] get_unread_count error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Ralat pelayan.']);
        }
        break;

    // ── Resolve one user's real profile for a brand-new chat ──────
    // Exposes the same trust display the product page and catalogue cards use
    // (role-specific Bayesian profile, "Baharu" for new sellers), so the buyer
    // chat header never shows a misleading legacy 0/100.
    case 'get_contact_info':
        $contact_id = (int) ($_GET['user_id'] ?? 0);
        if (!$contact_id) {
            http_response_code(422);
            die(json_encode(['success' => false, 'message' => 'user_id diperlukan.']));
        }
        try {
            $stmt = $pdo->prepare(
                'SELECT u.user_id, u.full_name, u.role, u.is_verified, u.verification_status,
                        tp.score AS trust_baseline, tp.evidence_units AS trust_evidence,
                        tp.ai_trust_score, tp.ai_adjustment, tp.fraud_risk
                   FROM users u
                   LEFT JOIN trust_profiles tp
                          ON tp.user_id = u.user_id AND tp.profile_role = u.role
                  WHERE u.user_id = ? AND u.is_active = 1'
            );
            $stmt->execute([$contact_id]);
            $contact = $stmt->fetch();
            if (!$contact) {
                http_response_code(404);
                die(json_encode(['success' => false, 'message' => 'Pengguna tidak ditemui.']));
            }
            $display = trust_display_from_row($contact);
            $contact['trust_display']       = $display['label'];
            $contact['trust_display_color'] = $display['color'];
            $contact['trust_has_score']     = $display['has_score'];
            echo json_encode(['success' => true, 'contact' => $contact]);
        } catch (PDOException $e) {
            error_log('[chat_api] get_contact_info error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Ralat pelayan.']);
        }
        break;

    // ── Get list of active chat partners ────────────────────
    case 'get_contacts':
        try {
            echo json_encode(['success' => true, 'contacts' => chat_contacts($pdo, $my_id)]);
        } catch (PDOException $e) {
            error_log('[chat_api] get_contacts error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Ralat pelayan.']);
        }
        break;


    // ── Get message history with a specific contact ──────────
    case 'get_messages':
        $contact_id = (int) ($_GET['contact_id'] ?? 0);
        if (!$contact_id) {
            http_response_code(422);
            die(json_encode(['success' => false, 'message' => 'contact_id diperlukan.']));
        }

        try {
            // Mark incoming messages from this contact as read
            $upd = $pdo->prepare('UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0');
            $upd->execute([$contact_id, $my_id]);

            // Return the latest conversation window rather than an unbounded
            // history, which keeps long-running chats responsive.
            $sql = "
                SELECT m.*, p.name AS product_name, p.price AS product_price, p.image_path AS product_image
                  FROM (
                        SELECT *
                          FROM messages
                         WHERE (sender_id = ? AND receiver_id = ?)
                            OR (sender_id = ? AND receiver_id = ?)
                         ORDER BY created_at DESC, message_id DESC
                         LIMIT 300
                       ) m
             LEFT JOIN products p ON m.product_id = p.product_id
                 ORDER BY m.created_at ASC, m.message_id ASC
            ";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$my_id, $contact_id, $contact_id, $my_id]);
            $messages = $stmt->fetchAll();

            echo json_encode(['success' => true, 'messages' => $messages]);
        } catch (PDOException $e) {
            error_log('[chat_api] get_messages error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Ralat pelayan.']);
        }
        break;

    // ── Send a new message ───────────────────────────────────
    case 'send_message':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            die(json_encode(['success' => false, 'message' => 'Kaedah tidak dibenarkan.']));
        }
        csrf_validate(); // Protect against CSRF

        // ── Rate limiting ─────────────────────────────────────
        // Uses a session-based sliding window — no extra table needed.
        // Shared with the session started by auth_check.php above.
        $now = time();
        if (!isset($_SESSION['chat_send_times'])) {
            $_SESSION['chat_send_times'] = [];
        }
        // Evict timestamps outside the current window
        $_SESSION['chat_send_times'] = array_values(array_filter(
            $_SESSION['chat_send_times'],
            fn($t) => ($now - $t) < CHAT_RATE_WINDOW_SEC
        ));
        if (count($_SESSION['chat_send_times']) >= CHAT_RATE_LIMIT) {
            http_response_code(429);
            die(json_encode([
                'success' => false,
                'message' => sprintf(
                    'Had penghantaran mesej dicapai. Sila tunggu %d saat.',
                    CHAT_RATE_WINDOW_SEC
                ),
            ]));
        }
        // Record this send attempt before processing
        $_SESSION['chat_send_times'][] = $now;

        $receiver_id  = (int) ($_POST['receiver_id'] ?? 0);
        $message_text = trim($_POST['message_text']  ?? '');
        $product_id   = isset($_POST['product_id']) && (int)$_POST['product_id'] > 0 ? (int)$_POST['product_id'] : null;

        if (!$receiver_id) {
            http_response_code(422);
            die(json_encode(['success' => false, 'message' => 'Penerima tidak sah.']));
        }
        if ($message_text === '') {
            http_response_code(422);
            die(json_encode(['success' => false, 'message' => 'Mesej tidak boleh kosong.']));
        }
        if (mb_strlen($message_text) > 2000) {
            http_response_code(422);
            die(json_encode(['success' => false, 'message' => 'Mesej terlalu panjang (maks 2000 aksara).']));
        }
        if ($receiver_id === $my_id) {
            http_response_code(422);
            die(json_encode(['success' => false, 'message' => 'Anda tidak boleh menghantar mesej kepada diri sendiri.']));
        }

        try {
            // Verify the receiver still exists and is allowed to participate.
            $chk = $pdo->prepare(
                'SELECT user_id, role, is_active FROM users WHERE user_id = ?'
            );
            $chk->execute([$receiver_id]);
            $receiver = $chk->fetch();
            if (!$receiver) {
                http_response_code(404);
                die(json_encode(['success' => false, 'message' => 'Penerima tidak ditemui.']));
            }
            if (!(int) $receiver['is_active']) {
                http_response_code(403);
                die(json_encode(['success' => false, 'message' => 'Akaun penerima tidak aktif.']));
            }

            if ($product_id !== null) {
                $product_stmt = $pdo->prepare(
                    'SELECT p.seller_id, p.status,
                            seller.is_active, seller.is_verified
                       FROM products p
                       JOIN users seller ON seller.user_id = p.seller_id
                      WHERE p.product_id = ?'
                );
                $product_stmt->execute([$product_id]);
                $product = $product_stmt->fetch();

                // Anyone who can shop (buyers, and sellers in buyer mode) may
                // ask a listing's seller about it, and that seller may reply.
                $my_role = (string) $_SESSION['user']['role'];
                $shopper_roles = ['pembeli', 'penjual'];
                $valid_participants = $product && (
                    (in_array($my_role, $shopper_roles, true)
                        && $receiver['role'] === 'penjual'
                        && (int) $product['seller_id'] === $receiver_id)
                    || ($my_role === 'penjual'
                        && in_array($receiver['role'], $shopper_roles, true)
                        && (int) $product['seller_id'] === $my_id)
                );

                if (!$product
                    || $product['status'] !== 'Aktif'
                    || !(int) $product['is_active']
                    || !(int) $product['is_verified']
                    || !$valid_participants) {
                    http_response_code(422);
                    die(json_encode([
                        'success' => false,
                        'message' => 'Produk yang dirujuk tidak sepadan dengan sembang ini.',
                    ]));
                }
            }

            // Insert message
            $stmt = $pdo->prepare("
                INSERT INTO messages (sender_id, receiver_id, product_id, message_text)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$my_id, $receiver_id, $product_id, $message_text]);
            $msg_id = $pdo->lastInsertId();

            echo json_encode([
                'success' => true,
                'message' => 'Mesej berjaya dihantar.',
                'message_id' => (int) $msg_id
            ]);
        } catch (PDOException $e) {
            error_log('[chat_api] send_message error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['success' => false, 'message' => 'Ralat pelayan.']);
        }
        break;

    default:
        http_response_code(400);
        die(json_encode(['success' => false, 'message' => 'Tindakan tidak sah.']));
}
