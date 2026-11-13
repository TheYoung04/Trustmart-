<?php
// ============================================================
// tests/uat/pentadbir/uat_pentadbir.php — automated UAT for PENTADBIR (admin).
// Covers: auth, admin surface reachability, every admin read endpoint,
// the refund-tab endpoints (incl. the pending_return summary bucket fix),
// action validation (negative, non-mutating), CSRF rejection, and admin's
// SCOPED access (admin is blocked from the buyer/seller dashboards by design).
//   Usage: php tests/uat/pentadbir/uat_pentadbir.php [base-url]
// ============================================================
require_once __DIR__ . '/../lib/uat_lib.php';
uat_set_base($argv[1] ?? null);

$R = 'UAT-PENTADBIR';
$admins = uat_demo('pentadbir');
if (!$admins) { echo "No active pentadbir account — aborting.\n"; exit(2); }
$adminEmail = $admins[0];
echo "== UAT PENTADBIR ($adminEmail) ==\n";

$a = uat_login($adminEmail);
uat_check("$R-001", 'admin login', $a->role === 'pentadbir', "role={$a->role}");
[$c] = uat_req($a, '/dashboard/admin.php');
uat_check("$R-010", 'admin dashboard reachable (200)', $c === 200, "http=$c");

// ── Every admin read endpoint returns success JSON ───────────
foreach ([
    ['get_sellers',      "$R-020"], ['get_disputes', "$R-021"],
    ['get_users',        "$R-022"], ['get_transactions', "$R-023"],
    ['get_refunds',      "$R-024"], ['get_stats', "$R-025"],
    ['get_analytics',    "$R-026"], ['get_ai_risks', "$R-027"],
] as [$act, $id]) {
    [$c,, $j] = uat_req($a, "/dashboard/admin.php?action=$act", ['json'=>true]);
    uat_check($id, "admin endpoint ok: $act", $c === 200 && ($j['success'] ?? false) === true, "http=$c body=" . substr(json_encode($j),0,120));
}

// ── Refund tab contract (verifies the pending_return fixes) ──
[$c,, $j] = uat_req($a, '/dashboard/admin.php?action=get_refunds&status=pending_return', ['json'=>true]);
$sum = $j['summary'] ?? [];
uat_check("$R-030", 'get_refunds?status=pending_return accepted (not ignored)', ($j['success'] ?? false) === true && is_array($j['refunds'] ?? null), json_encode($j));
uat_check("$R-031", 'summary exposes pending_return_count bucket', array_key_exists('pending_return_count', $sum), json_encode($sum));
uat_check("$R-032", 'summary has core refund counters', array_key_exists('total_refunds',$sum) && array_key_exists('failed_count',$sum) && array_key_exists('completed_count',$sum), json_encode($sum));

// ── Action validation (negative, non-mutating) ───────────────
[$c,, $j] = uat_req($a, '/dashboard/admin.php?action=verify_seller', ['json'=>true,'post'=>['user_id'=>0,'action_type'=>'approve','_csrf_token'=>$a->token]]);
uat_check("$R-040", 'verify_seller rejects invalid user_id', !($j['success'] ?? false), "http=$c body=" . json_encode($j));
[$c,, $j] = uat_req($a, '/dashboard/admin.php?action=resolve_dispute', ['json'=>true,'post'=>['dispute_id'=>0,'status'=>'Bogus','_csrf_token'=>$a->token]]);
uat_check("$R-041", 'resolve_dispute rejects invalid status (422)', $c === 422 && !($j['success'] ?? false), "http=$c body=" . json_encode($j));
[$c,, $j] = uat_req($a, '/dashboard/admin.php?action=retry_refund', ['json'=>true,'post'=>['payment_id'=>0,'_csrf_token'=>$a->token]]);
uat_check("$R-042", 'retry_refund rejects payment_id=0 (422)', $c === 422 && !($j['success'] ?? false), "http=$c body=" . json_encode($j));
[$c,, $j] = uat_req($a, '/dashboard/admin.php?action=mark_refund_completed', ['json'=>true,'post'=>['payment_id'=>0,'_csrf_token'=>$a->token]]);
uat_check("$R-043", 'mark_refund_completed rejects payment_id=0 (422)', $c === 422 && !($j['success'] ?? false), "http=$c body=" . json_encode($j));

// ── CSRF negative ────────────────────────────────────────────
[$c] = uat_req($a, '/dashboard/admin.php?action=toggle_user_status', ['json'=>true,'post'=>['user_id'=>2]]); // no token
uat_check("$R-050", 'admin POST without CSRF rejected (403)', $c === 403, "http=$c");

// ── Unauthenticated admin API -> 401 ─────────────────────────
$anon = new UatSession();
[$c,, $j] = uat_req($anon, '/dashboard/admin.php?action=get_stats', ['json'=>true]);
uat_check("$R-051", 'anonymous admin API -> 401', $c === 401 && ($j['success'] ?? true) === false, "http=$c");

// ── Admin SCOPED access (by design admin cannot open buyer/seller dashboards)
[$c] = uat_req($a, '/dashboard/buyer.php');
uat_check("$R-060", 'admin CANNOT open buyer dashboard (403, scoped)', $c === 403, "http=$c");
[$c] = uat_req($a, '/dashboard/seller.php');
uat_check("$R-061", 'admin CANNOT open seller dashboard (403, scoped)', $c === 403, "http=$c");
// but shared surfaces are allowed
[$c] = uat_req($a, '/dashboard/profile.php');
uat_check("$R-062", 'admin CAN open shared profile page (200)', $c === 200, "http=$c");

exit(uat_finish('PENTADBIR'));
