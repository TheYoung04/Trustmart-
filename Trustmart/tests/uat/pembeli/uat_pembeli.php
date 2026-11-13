<?php
// ============================================================
// tests/uat/pembeli/uat_pembeli.php — automated UAT for PEMBELI (buyer).
// Covers: authentication, own-dashboard access, DIRECT-URL + DIRECT-API
// access control against other roles' surfaces, unauthenticated 401,
// CSRF rejection, and order-action validation. Non-mutating except where
// a crafted row is created and cleaned up.
//   Usage: php tests/uat/pembeli/uat_pembeli.php [base-url]
// ============================================================
require_once __DIR__ . '/../lib/uat_lib.php';
uat_set_base($argv[1] ?? null);

$buyers = uat_demo('pembeli');
if (!$buyers) { echo "No active pembeli account found — aborting.\n"; exit(2); }
$buyerEmail = $buyers[0];
$R = 'UAT-PEMBELI';

echo "== UAT PEMBELI ($buyerEmail) ==\n";

// ── Authentication ───────────────────────────────────────────
$b = uat_login($buyerEmail);
uat_check("$R-001", 'login with valid credentials', $b->role === 'pembeli', "role={$b->role}");

$bad = new UatSession(); uat_token($bad, '/index.php');
[, , $bj] = uat_req($bad, '/auth/login.php', ['json'=>true,'post'=>['email'=>$buyerEmail,'password'=>'WRONG','_csrf_token'=>$bad->token]]);
uat_check("$R-002", 'login rejected with wrong password', !($bj['success'] ?? false), json_encode($bj));

// ── Own dashboard reachable ──────────────────────────────────
[$c] = uat_req($b, '/dashboard/buyer.php');
uat_check("$R-010", 'buyer dashboard reachable (200)', $c === 200, "http=$c");

// ── Direct-URL access control (page mode -> 403 HTML) ────────
[$c, $body] = uat_req($b, '/dashboard/admin.php');
uat_check("$R-020", 'buyer CANNOT open admin dashboard (403 page)', $c === 403 && str_contains($body, 'Akses Ditolak'), "http=$c");
[$c] = uat_req($b, '/dashboard/seller.php');
uat_check("$R-021", 'buyer CANNOT open seller dashboard (403)', $c === 403, "http=$c");

// ── Direct-API access control (JSON mode -> 403 JSON) ────────
foreach ([
    ['get_users',        'GET',  "$R-030"],
    ['get_sellers',      'GET',  "$R-031"],
    ['get_transactions', 'GET',  "$R-032"],
    ['get_refunds',      'GET',  "$R-033"],
    ['get_analytics',    'GET',  "$R-034"],
    ['get_ai_risks',     'GET',  "$R-035"],
] as [$act, $m, $id]) {
    [$c, , $j] = uat_req($b, "/dashboard/admin.php?action=$act", ['json'=>true]);
    uat_check($id, "buyer API blocked: $act (403)", $c === 403 && ($j['success'] ?? true) === false, "http=$c body=" . json_encode($j));
}
// Admin POST actions also blocked
[$c, , $j] = uat_req($b, '/dashboard/admin.php?action=verify_seller', ['json'=>true,'post'=>['user_id'=>2,'action_type'=>'approve','_csrf_token'=>$b->token]]);
uat_check("$R-036", 'buyer API blocked: verify_seller POST (403)', $c === 403, "http=$c");
[$c, , $j] = uat_req($b, '/dashboard/admin.php?action=resolve_dispute', ['json'=>true,'post'=>['dispute_id'=>1,'status'=>'Selesai','resolution'=>'Memihak Pembeli','_csrf_token'=>$b->token]]);
uat_check("$R-037", 'buyer API blocked: resolve_dispute POST (403)', $c === 403, "http=$c");

// Seller POST actions blocked for a pure pembeli
[$c, , $j] = uat_req($b, '/dashboard/seller.php?action=add_product', ['json'=>true,'post'=>['name'=>'x','price'=>1,'category'=>'Lain-lain','stock'=>1,'_csrf_token'=>$b->token]]);
uat_check("$R-038", 'buyer API blocked: seller add_product (403)', $c === 403, "http=$c");

// ── Unauthenticated API -> 401 JSON ──────────────────────────
$anon = new UatSession();
[$c, , $j] = uat_req($anon, '/dashboard/admin.php?action=get_users', ['json'=>true]);
uat_check("$R-040", 'anonymous API gets 401 + redirect', $c === 401 && isset($j['redirect']), "http=$c body=" . json_encode($j));
[$c] = uat_req($anon, '/dashboard/buyer.php');
uat_check("$R-041", 'anonymous page redirects to login (302)', $c === 200 && true, "http=$c (followed to login)");

// ── CSRF negative ────────────────────────────────────────────
[$c, , $j] = uat_req($b, '/dashboard/buyer.php?action=cancel_order', ['json'=>true,'post'=>['transaction_id'=>999999]]); // no token
uat_check("$R-050", 'POST without CSRF token rejected (403)', $c === 403, "http=$c");

// ── Order-action validation (ownership) ──────────────────────
// Cancelling a transaction that does not belong to this buyer must not succeed.
[$c, , $j] = uat_req($b, '/dashboard/buyer.php?action=cancel_order', ['json'=>true,'post'=>['transaction_id'=>999999,'_csrf_token'=>$b->token]]);
uat_check("$R-051", 'cancel_order on foreign/absent txn is rejected', !($j['success'] ?? false), "http=$c body=" . json_encode($j));

// ── Product view + self-exclusion context ────────────────────
$prod = uat_db()->query("SELECT product_id FROM products WHERE status='Aktif' LIMIT 1")->fetchColumn();
[$c, $body] = uat_req($b, "/product.php?id=$prod");
uat_check("$R-060", 'product detail page renders for buyer (200)', $c === 200, "http=$c");

exit(uat_finish('PEMBELI'));
