<?php
/*
 * seed_demo_scenes.php — puts the live demo into the state the SV deck promises.
 *
 *   php scratch/seed_demo_scenes.php            seed the three scenes
 *   php scratch/seed_demo_scenes.php --status   show current scene state
 *   php scratch/seed_demo_scenes.php --restore  revert exactly what was seeded
 *
 * demo_preflight.php reports three NO-GO items that are data gaps, not bugs:
 * no seller currently AI-held (so the blocked-checkout scene fires nothing),
 * no open dispute (so the admin resolve modal is empty), and no seller waiting
 * for PTU verification (so the approval gate cannot be shown). This script
 * creates all three and records the original values in demo_scene_state.json so
 * the seed is reversible — a demo fixture must never silently become the
 * permanent database state.
 *
 * The seeded dispute and seller are real rows created through the same column
 * contract the application uses, so they behave identically to organic data.
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/ai_trust.php';

$STATE = __DIR__ . '/demo_scene_state.json';
$mode  = $argv[1] ?? 'seed';

/* ── --status: report only ─────────────────────────────────────── */
if ($mode === '--status') {
    $held = $pdo->prepare("SELECT tp.user_id, u.full_name, tp.fraud_risk, tp.ai_hold
        FROM trust_profiles tp JOIN users u ON u.user_id=tp.user_id
        WHERE tp.profile_role='penjual' AND tp.fraud_risk >= ? AND tp.ai_hold=1");
    $held->execute([AI_TRUST_HOLD_RISK]);
    echo 'AI held sellers   : ' . (count($held->fetchAll()) ?: '0') . "\n";
    echo 'Open disputes     : ' . $pdo->query("SELECT COUNT(*) FROM disputes WHERE status<>'Selesai'")->fetchColumn() . "\n";
    echo 'Pending sellers   : ' . $pdo->query("SELECT COUNT(*) FROM users WHERE role='penjual' AND verification_status='Menunggu' AND is_active=1")->fetchColumn() . "\n";
    echo 'State file        : ' . (is_file($STATE) ? 'PRESENT (' . basename($STATE) . ')' : 'none') . "\n";
    exit(0);
}

/* ── --restore ─────────────────────────────────────────────────── */
if ($mode === '--restore') {
    if (!is_file($STATE)) { exit("Nothing seeded by this script — no state file.\n"); }
    $s = json_decode(file_get_contents($STATE), true);
    if (!empty($s['dispute']['dispute_id'])) {
        $d = $s['dispute'];
        $pdo->prepare("DELETE FROM disputes WHERE dispute_id=?")->execute([$d['dispute_id']]);
        echo "Dispute #{$d['dispute_id']} dihapus.\n";
        // Same refresh the seed did, so removing the row restores the old scores.
        $pdo->prepare("CALL recalculate_trust_score(?)")->execute([$d['seller_id']]);
        $pdo->prepare("CALL recalculate_buyer_trust_score(?)")->execute([$d['buyer_id']]);
    }
    if (!empty($s['pending_user_id'])) {
        // No products can belong to it, but clear the FK side first anyway.
        $pdo->prepare("DELETE FROM products WHERE seller_id=?")->execute([$s['pending_user_id']]);
        $pdo->prepare("DELETE FROM trust_profiles WHERE user_id=?")->execute([$s['pending_user_id']]);
        $pdo->prepare("DELETE FROM users WHERE user_id=?")->execute([$s['pending_user_id']]);
        echo "Akaun penjual demo #{$s['pending_user_id']} dihapus.\n";
    }
    if (!empty($s['hold']['user_id'])) {
        $h = $s['hold'];
        $pdo->prepare("UPDATE trust_profiles SET fraud_risk=?, ai_hold=?, ai_hold_released_by=?,
                            ai_hold_released_at=? WHERE user_id=? AND profile_role='penjual'")
            ->execute([$h['fraud_risk'], $h['ai_hold'], $h['ai_hold_released_by'],
                        $h['ai_hold_released_at'], $h['user_id']]);
        // Re-derive the deterministic score the way the triggers would.
        $pdo->prepare("CALL recalculate_trust_score(?)")->execute([$h['user_id']]);
        echo "Penjual #{$h['user_id']} dikembalikan kepada nilai asal (risk={$h['fraud_risk']}, hold={$h['ai_hold']}).\n";
    }
    unlink($STATE);
    echo "Keadaan sebelum seed dipulihkan. State file dibuang.\n";
    exit(0);
}

/* ── seed ──────────────────────────────────────────────────────── */
if (is_file($STATE)) {
    exit("State file sudah wujud — seed lalu belum dipulihkan.\n"
        . "Jalankan php scratch/seed_demo_scenes.php --restore dahulu, atau --status untuk semak.\n");
}
$state = [];
// Persist after EVERY step, not only at the end: a crash mid-seed with no state
// file means the original values are unrecoverable (this actually happened).
function save_state(array $state, string $file): void { file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT)); }

// 1 · Put one seller into the AI-held state. Prefer a profile the detectors
//     ALREADY scored over the hold threshold — then nothing about the panel is
//     invented and the demo shows a real override of a real flag. Only fall
//     back to raising fraud_risk if no genuine candidate exists.
$listingJoin = "JOIN users u ON u.user_id = tp.user_id AND u.role='penjual' AND u.is_active=1
                JOIN products p ON p.seller_id = tp.user_id AND p.status='Aktif' AND p.stock>0";
$seller = $pdo->query("SELECT tp.user_id, u.full_name, tp.fraud_risk, tp.ai_hold,
        tp.ai_hold_released_by, tp.ai_hold_released_at, COUNT(p.product_id) listings
    FROM trust_profiles tp $listingJoin
    WHERE tp.profile_role='penjual' AND tp.fraud_risk >= " . AI_TRUST_HOLD_RISK . "
    GROUP BY tp.user_id ORDER BY tp.fraud_risk DESC, listings DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$synthetic = false;
if (!$seller) {
    // No genuine flag available — nudge the most-listed verified seller instead.
    $seller = $pdo->query("SELECT tp.user_id, u.full_name, tp.fraud_risk, tp.ai_hold,
            tp.ai_hold_released_by, tp.ai_hold_released_at, COUNT(p.product_id) listings
        FROM trust_profiles tp $listingJoin
        WHERE tp.profile_role='penjual'
        GROUP BY tp.user_id ORDER BY listings DESC, tp.user_id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $synthetic = true;
}
if (!$seller) {
    echo "[skip 1] Tiada penjual dengan listing Aktif — katalog perlu di-seed dulu.\n";
} else {
    $orig = [
        'fraud_risk'           => $seller['fraud_risk'],
        'ai_hold'              => $seller['ai_hold'],
        'ai_hold_released_by'  => $seller['ai_hold_released_by'],
        'ai_hold_released_at'  => $seller['ai_hold_released_at'],
    ];
    $risk = (float) $seller['fraud_risk'];
    if ($synthetic || $risk < AI_TRUST_HOLD_RISK) {
        $risk = AI_TRUST_HOLD_RISK + 5; // comfortably over the gate
        echo "[1] AMARAN: tiada profil AI yang benar-benar melepasi ambang — fraud_risk ditokok "
            . "kepada $risk secara sintetis untuk $seller[full_name]. Nyatakan ini sebagai fixture jika SV bertanya.\n";
    }
    $pdo->prepare("UPDATE trust_profiles SET ai_hold=1, ai_hold_released_by=NULL,
                        ai_hold_released_at=NULL, fraud_risk=? WHERE user_id=? AND profile_role='penjual'")
        ->execute([$risk, $seller['user_id']]);
    $state['hold'] = array_merge($orig, ['user_id' => (int) $seller['user_id'], 'synthetic' => $synthetic]);
    save_state($state, $STATE);
    echo "[1] AI hold DIARMakan pada {$seller['full_name']} (uid {$seller['user_id']}, fraud_risk="
        . number_format($risk, 1) . ", $seller[listings] listing berstok).\n";
    echo "    Demo: beli produk penjual ini -> checkout 403 -> admin lepaskan -> beli semula berjaya.\n";
}

// 2 · Open a dispute on a real finished order that does not already have one.
$txn = $pdo->query("SELECT t.transaction_id, t.buyer_id, t.seller_id, p.name AS product_name, u.full_name buyer_name
    FROM transactions t
    JOIN products p ON p.product_id = t.product_id
    JOIN users u    ON u.user_id     = t.buyer_id
    LEFT JOIN disputes d ON d.transaction_id = t.transaction_id
    WHERE d.dispute_id IS NULL AND t.status IN ('Selesai','Disahkan')
    ORDER BY t.created_at DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$txn) {
    echo "[skip 2] Tiada order tanpa dispute — semua sudah ada aduan.\n";
} else {
    $pdo->prepare("INSERT INTO disputes (transaction_id, filed_by, title, description, severity, status)
        VALUES (?,?,?,?, 'Sederhana', 'Terbuka')")
        ->execute([$txn['transaction_id'], $txn['buyer_id'],
                   'Barang tidak sampai selepas 7 hari',
                   'Pembeli melaporkan barang belum diterima walaupun bayaran sudah selesai. Kes ini difailkan untuk demo alur selesaikan oleh PTU.']);
    $did = (int) $pdo->lastInsertId();
    $state['dispute'] = ['dispute_id' => $did, 'transaction_id' => (int) $txn['transaction_id'],
                         'seller_id' => (int) $txn['seller_id'], 'buyer_id' => (int) $txn['buyer_id']];
    echo "[2] Dispute #$did TERBUKA pada txn #{$txn['transaction_id']} ({$txn['product_name']}, pembeli {$txn['buyer_name']}).\n";
    echo "    Demo: admin -> Dispute -> Selesaikan (pilih settlement) -> skor kepercayaan kedua pihak berubah.\n";
    // An open dispute is an evidence event for the deterministic core; refresh
    // both profiles so the numbers on screen agree with the new row.
    $pdo->prepare("CALL recalculate_trust_score(?)")->execute([$txn['seller_id']]);
    $pdo->prepare("CALL recalculate_buyer_trust_score(?)")->execute([$txn['buyer_id']]);
    save_state($state, $STATE);
}

// 3 · A seller stuck in Menunggu, so the PTU approval gate is demonstrable.
$newEmail = 'nursyafiq.demo@student.uthm.edu.my';
if ((int) $pdo->query("SELECT COUNT(*) FROM users WHERE email=" . $pdo->quote($newEmail))->fetchColumn() > 0) {
    echo "[skip 3] Akaun $newEmail sudah wujud.\n";
} else {
    $hash = password_hash('password123', PASSWORD_BCRYPT, ['cost' => 12]);
    $pdo->prepare("INSERT INTO users (full_name, matric_no, email, password_hash, role, faculty,
                is_active, verification_status, is_verified, trust_score)
            VALUES ('Nur Syafiq (Demo PTU)', 'PSM-DEMO-01', ?, ?, 'penjual',
                'Pengurusan', 1, 'Menunggu', 0, 0.00)")
        ->execute([$newEmail, $hash]);
    $uid = (int) $pdo->lastInsertId();
    $state['pending_user_id'] = $uid;
    save_state($state, $STATE);
    echo "[3] Penjual BELUM DISAHKAN dicipta: $newEmail / password123 (uid $uid, Menunggu).\n";
    echo "    Demo: admin -> Sahkan Penjual -> filter Menunggu -> tekan Sah -> listing jadi tampak.\n";
}

file_put_contents($STATE, json_encode($state, JSON_PRETTY_PRINT));
echo "\nSeed direkodkan di " . basename($STATE) . ". Pulihkan dengan: php scratch/seed_demo_scenes.php --restore\n";
echo "Sahkan: php scratch/demo_preflight.php\n";
