<?php
// ============================================================
// tests/uat/penjual/uat_penjual.php — automated UAT for PENJUAL (seller).
// Covers: auth, own dashboard, admin access control (403), the intended
// seller-as-buyer access, a REAL product CRUD cycle (add->edit->delete,
// self-cleaned), input validation, ownership guards, and order-action
// validation.   Usage: php tests/uat/penjual/uat_penjual.php [base-url]
// ============================================================
require_once __DIR__ . '/../lib/uat_lib.php';
uat_set_base($argv[1] ?? null);

$R = 'UAT-PENJUAL';
$SELLER_EMAIL = 'haziq@student.uthm.edu.my';   // user 2, verified, owns product 1
$FOREIGN_PROD = 12;                              // owned by mirza (16)
$OWN_PROD     = (int)(uat_db()->query("SELECT product_id FROM products WHERE seller_id=2 LIMIT 1")->fetchColumn() ?: 1);

echo "== UAT PENJUAL ($SELLER_EMAIL) ==\n";
$s = uat_login($SELLER_EMAIL);
uat_check("$R-001", 'seller login', $s->role === 'penjual', "role={$s->role}");

// Own dashboard
[$c] = uat_req($s, '/dashboard/seller.php');
uat_check("$R-010", 'seller dashboard reachable (200)', $c === 200, "http=$c");

// Intended cross-access: a penjual MAY open the buyer dashboard (guard allows it)
[$c] = uat_req($s, '/dashboard/buyer.php');
uat_check("$R-011", 'seller MAY open buyer dashboard (by design, 200)', $c === 200, "http=$c");

// ── Admin access control (403) ───────────────────────────────
[$c, $body] = uat_req($s, '/dashboard/admin.php');
uat_check("$R-020", 'seller CANNOT open admin dashboard (403)', $c === 403 && str_contains($body, 'Akses Ditolak'), "http=$c");
foreach ([['get_users',"$R-030"],['get_refunds',"$R-031"],['get_ai_risks',"$R-032"],['get_analytics',"$R-033"]] as [$act,$id]) {
    [$c] = uat_req($s, "/dashboard/admin.php?action=$act", ['json'=>true]);
    uat_check($id, "seller API blocked: $act (403)", $c === 403, "http=$c");
}
[$c] = uat_req($s, '/dashboard/admin.php?action=verify_seller', ['json'=>true,'post'=>['user_id'=>4,'action_type'=>'approve','_csrf_token'=>$s->token]]);
uat_check("$R-034", 'seller API blocked: verify_seller POST (403)', $c === 403, "http=$c");

// ── Seller read endpoints ────────────────────────────────────
foreach ([['get_products',"$R-040"],['get_orders',"$R-041"],['get_stats',"$R-042"]] as [$act,$id]) {
    [$c,, $j] = uat_req($s, "/dashboard/seller.php?action=$act", ['json'=>true]);
    uat_check($id, "seller read endpoint ok: $act", $c === 200 && ($j['success'] ?? false) === true, "http=$c body=" . json_encode($j));
}

// ── Product CRUD cycle (self-cleaned) ────────────────────────
$made = null;
uat_on_cleanup(function () use (&$made) {
    if ($made) { uat_db()->prepare("DELETE FROM products WHERE product_id=?")->execute([$made]); $made = null; }
});
[$c,, $j] = uat_req($s, '/dashboard/seller.php?action=add_product', ['json'=>true,'post'=>[
    'name'=>'UAT Test Produk','category'=>'Lain-lain','description'=>'created by uat','price'=>'9.90','stock'=>5,'_csrf_token'=>$s->token]]);
$made = (int)($j['product_id'] ?? 0) ?: $made;
uat_check("$R-050", 'add_product creates a listing', ($j['success'] ?? false) === true && $made > 0, "http=$c body=" . json_encode($j));

if ($made) {
    [$c,, $j] = uat_req($s, '/dashboard/seller.php?action=edit_product', ['json'=>true,'post'=>[
        'product_id'=>$made,'name'=>'UAT Edited Nama','category'=>'Buku','price'=>'19.90','stock'=>3,'_csrf_token'=>$s->token]]);
    uat_check("$R-051", 'edit_product updates own listing', ($j['success'] ?? false) === true, "http=$c body=" . json_encode($j));
    $row = uat_db()->prepare("SELECT name,price FROM products WHERE product_id=?"); $row->execute([$made]); $p = $row->fetch(PDO::FETCH_ASSOC);
    uat_check("$R-052", 'edit persisted to DB', ($p['name'] ?? '') === 'UAT Edited Nama', json_encode($p));

    [$c,, $j] = uat_req($s, '/dashboard/seller.php?action=delete_product', ['json'=>true,'post'=>['product_id'=>$made,'_csrf_token'=>$s->token]]);
    uat_check("$R-053", 'delete_product removes own listing', ($j['success'] ?? false) === true, "http=$c body=" . json_encode($j));
    $st = uat_db()->prepare("SELECT status FROM products WHERE product_id=?"); $st->execute([$made]);
    $stt = (string)$st->fetchColumn();
    uat_check("$R-054", 'delete is a soft-delete (status=Dipadam)', $stt === 'Dipadam', "status=$stt");
    // keep $made set so teardown HARD-removes the crafted row (no catalogue leak)
}

// ── Input validation (negative) ──────────────────────────────
[$c,, $j] = uat_req($s, '/dashboard/seller.php?action=add_product', ['json'=>true,'post'=>[
    'name'=>'','category'=>'NotACategory','price'=>'0','stock'=>-5,'_csrf_token'=>$s->token]]);
uat_check("$R-060", 'add_product rejects invalid input (422 + errors)', $c === 422 && !($j['success'] ?? true) && !empty($j['errors']), "http=$c body=" . json_encode($j));

// ── Ownership guards (negative, non-mutating) ────────────────
[$c,, $j] = uat_req($s, '/dashboard/seller.php?action=edit_product', ['json'=>true,'post'=>[
    'product_id'=>$FOREIGN_PROD,'name'=>'hijack','category'=>'Buku','price'=>'1','stock'=>1,'_csrf_token'=>$s->token]]);
uat_check("$R-061", 'edit_product on FOREIGN product rejected', !($j['success'] ?? false), "http=$c body=" . json_encode($j));
[$c,, $j] = uat_req($s, '/dashboard/seller.php?action=delete_product', ['json'=>true,'post'=>['product_id'=>$FOREIGN_PROD,'_csrf_token'=>$s->token]]);
uat_check("$R-062", 'delete_product on FOREIGN product rejected', !($j['success'] ?? false), "http=$c body=" . json_encode($j));
// foreign product still intact?
$still = (int)uat_db()->query("SELECT COUNT(*) FROM products WHERE product_id=$FOREIGN_PROD")->fetchColumn();
uat_check("$R-063", 'foreign product NOT modified/deleted by attack', $still === 1, "count=$still");

// ── Self-exclusion: seller cannot order their own product ────
[$c,, $j] = uat_req($s, "/product.php?action=make_order", ['json'=>true,'post'=>[
    'product_id'=>$OWN_PROD,'quantity'=>1,'payment_method'=>'Tunai','payment_type'=>'cash','_csrf_token'=>$s->token]]);
uat_check("$R-070", 'seller cannot order own product (self-exclusion)', !($j['success'] ?? false), "http=$c body=" . json_encode($j));

// ── CSRF negative ────────────────────────────────────────────
[$c] = uat_req($s, '/dashboard/seller.php?action=add_product', ['json'=>true,'post'=>['name'=>'x','category'=>'Buku','price'=>1,'stock'=>1]]);
uat_check("$R-080", 'seller POST without CSRF rejected (403)', $c === 403, "http=$c");

exit(uat_finish('PENJUAL'));
