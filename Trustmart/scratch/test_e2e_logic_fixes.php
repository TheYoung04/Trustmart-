<?php
/**
 * scratch/test_e2e_logic_fixes.php — LIVE E2E for logical fixes L4/L5/L6/L8/L9.
 *
 *   L4  expire_unpaid.php reclaims a stale (>= CASH_EXPIRY_DAYS) unpaid cash
 *       order + restores stock, and leaves a fresh cash order untouched.
 *   L5  seller get_orders reads buyer trust from trust_profiles (pembeli),
 *       exposing a buyer_trust_evidence column (no legacy mirror).
 *   L6  confirm_cash_payment UPSERTs the cash_payments row even for a legacy
 *       order that has none.
 *   L8  admin "Memihak Penjual" on a 'Disahkan' disputed order completes it
 *       (Disahkan -> Selesai, completed_by='system') instead of leaving it stuck.
 *   L9  get_pending_returns yields exactly ONE row per return even when the
 *       transaction has multiple completed payment rows.
 *
 * Drives the real endpoints + the real CLI expiry job over Apache/MySQL.
 * Self-cleans every crafted row and restores the product baseline.
 * Run: php scratch/test_e2e_logic_fixes.php  [http://localhost/Trustmart]
 */
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
$base = rtrim($argv[1] ?? 'http://localhost/Trustmart', '/');
$root = dirname(__DIR__);
require_once $root . '/config/db.php';

$pass = 0; $fail = 0;
function check($n, $c, &$pass, &$fail, $d = '') { echo ($c?"  [PASS] ":"  [FAIL] ").$n.($d?"  ($d)":"")."\n"; $c?$pass++:$fail++; }

function jar($name){ return sys_get_temp_dir().'/tm_logic_'.$name.'.txt'; }
function http($url,$post=null,$hdr=[],$ck=null){
    $ch=curl_init($url);
    $opts=[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_TIMEOUT=>25];
    if($ck){ $opts[CURLOPT_COOKIEJAR]=$ck; $opts[CURLOPT_COOKIEFILE]=$ck; }
    curl_setopt_array($ch,$opts);
    if($hdr) curl_setopt($ch,CURLOPT_HTTPHEADER,$hdr);
    if($post!==null){curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($post));}
    $b=curl_exec($ch); $c=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch); return [$c,(string)$b];
}
function token_from($b){ if(preg_match('/name="csrf-token" content="([^"]+)"/',$b,$m))return $m[1]; if(preg_match('/name="_csrf_token" value="([^"]+)"/',$b,$m))return $m[1]; return ''; }
function login($email,$ck){ global $base;
    [, $page]=http("$base/public/",null,[],$ck); $tok=token_from($page);
    http("$base/auth/login.php", ['email'=>$email,'password'=>'password123','_csrf_token'=>$tok], ['X-CSRF-Token: '.$tok,'Accept: application/json'], $ck);
    return $tok;
}

echo "Target: $base\n";
$S = jar('seller'); $A = jar('admin'); @unlink($S); @unlink($A);

// Product + its seller must have room for the crafted orders.
$prod = $pdo->query(
    "SELECT p.product_id, p.seller_id, p.stock, p.sold_count, u.email
       FROM products p JOIN users u ON u.user_id=p.seller_id
      WHERE u.role='penjual' AND u.is_active=1 AND p.stock>=3
      ORDER BY p.product_id ASC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
if(!$prod){ echo "No suitable product/seller.\n"; exit(1); }
$productId=(int)$prod['product_id']; $sellerId=(int)$prod['seller_id'];
$baseStock=(int)$prod['stock']; $baseSold=(int)$prod['sold_count']; $sellerEmail=$prod['email'];
$buyer=(int)$pdo->query("SELECT user_id FROM users WHERE role='pembeli' AND is_active=1 LIMIT 1")->fetchColumn();
$adminEmail=$pdo->query("SELECT email FROM users WHERE role='pentadbir' AND is_active=1 LIMIT 1")->fetchColumn();
echo "product=$productId seller=$sellerId($sellerEmail) buyer=$buyer admin=$adminEmail baseline stock=$baseStock sold=$baseSold\n";

$tokS = login($sellerEmail,$S);
[, $dashS]=http("$base/dashboard/seller.php",null,[],$S); $tokS=token_from($dashS)?:$tokS;
$tokA = login($adminEmail,$A);
[, $dashA]=http("$base/dashboard/admin.php",null,[],$A); $tokA=token_from($dashA)?:$tokA;
echo "seller+admin logged in\n";

$madeTxn=[]; $madePay=[]; $madeDisp=[]; $madeRet=[]; $madeCash=[];
function mkTxn($pdo,&$madeTxn,$buyer,$sellerId,$productId,$status,$payStatus,$ref,$qty=1,$daysAgo=0,$amount='10.00'){
    $pdo->prepare(
        "INSERT INTO transactions
            (buyer_id,seller_id,product_id,quantity,total_amount,payment_method,
             status,payment_type,payment_status,payment_reference,created_at)
         VALUES (?,?,?,?,?, 'Tunai', ?, 'cash', ?, ?, DATE_SUB(NOW(), INTERVAL ? DAY))"
    )->execute([$buyer,$sellerId,$productId,$qty,$amount,$status,$payStatus,$ref,$daysAgo]);
    $t=(int)$pdo->lastInsertId(); $madeTxn[]=$t; return $t;
}
$uid=0;
$R=function()use(&$uid){ $uid++; return 'E2ELGX_'.$uid.'_'.bin2hex(random_bytes(3)); };

try {
    // ================= L4 : stale vs fresh cash =================
    $txnOld = mkTxn($pdo,$madeTxn,$buyer,$sellerId,$productId,'Menunggu','unpaid_cash',$R(),1,10);
    $txnNew = mkTxn($pdo,$madeTxn,$buyer,$sellerId,$productId,'Menunggu','unpaid_cash',$R(),1,0);
    echo "\n== L4: cash expiry ==\n";
    $jobCmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/payment/expire_unpaid.php');
    $jobOut = shell_exec($jobCmd);
    $job = json_decode((string)$jobOut,true) ?: [];
    $stOld = $pdo->query("SELECT status,cancelled_by FROM transactions WHERE transaction_id=$txnOld")->fetch(PDO::FETCH_ASSOC);
    $stNew = $pdo->query("SELECT status FROM transactions WHERE transaction_id=$txnNew")->fetch(PDO::FETCH_ASSOC);
    check("job ran (JSON success)", ($job['success']??false)===true, $pass,$fail, substr((string)$jobOut,0,120));
    check("STALE cash order -> Dibatalkan by system", $stOld['status']==='Dibatalkan' && $stOld['cancelled_by']==='system', $pass,$fail, json_encode($stOld));
    check("stale txn listed in job cash_expired", in_array($txnOld, array_map('intval',$job['cash_expired']??[]), true), $pass,$fail, json_encode($job['cash_expired']??[]));
    check("FRESH cash order NOT expired (still Menunggu)", $stNew['status']==='Menunggu', $pass,$fail, json_encode($stNew));

    // ================= L6 : confirm_cash_payment upsert =================
    $txnLeg = mkTxn($pdo,$madeTxn,$buyer,$sellerId,$productId,'Menunggu','unpaid_cash',$R(),1,0,'10.00');
    // Simulate a legacy order with NO cash_payments row.
    $pdo->prepare("DELETE FROM cash_payments WHERE transaction_id=?")->execute([$txnLeg]);
    $before = $pdo->query("SELECT COUNT(*) FROM cash_payments WHERE transaction_id=$txnLeg")->fetchColumn();
    echo "\n== L6: confirm_cash_payment upsert (legacy order without row) ==\n";
    check("precondition: no cash_payments row exists", (int)$before===0, $pass,$fail, "count=$before");
    [$c,$b]=http("$base/dashboard/seller.php?action=confirm_cash_payment",
        ['transaction_id'=>$txnLeg,'amount_received'=>'10.00','payment_method_detail'=>'Hand-to-hand','seller_notes'=>'L6 upsert test','_csrf_token'=>$tokS],
        ['X-CSRF-Token: '.$tokS],$S);
    $after=$pdo->query("SELECT confirmed_by_seller,amount_received FROM cash_payments WHERE transaction_id=$txnLeg")->fetch(PDO::FETCH_ASSOC);
    check("confirm accepted (200 success)", $c===200 && (json_decode($b,true)['success']??false), $pass,$fail, substr($b,0,120));
    check("cash_payments row CREATED (upsert, was missing)", $after && (int)$after['confirmed_by_seller']===1 && (float)$after['amount_received']===10.00, $pass,$fail, json_encode($after));

    // ================= L8 : Memihak Penjual completes Disahkan order =================
    $txnD = mkTxn($pdo,$madeTxn,$buyer,$sellerId,$productId,'Disahkan','paid_cash',$R(),1,0,'10.00');
    $pdo->prepare("INSERT INTO disputes (transaction_id,filed_by,title,description,severity,status) VALUES (?,?,'Barang tidak sama','test','Tinggi','Terbuka')")->execute([$txnD,$buyer]);
    $dispD=(int)$pdo->lastInsertId(); $madeDisp[]=$dispD;
    echo "\n== L8: Memihak Penjual auto-completes a Disahkan order ==\n";
    [$c,$b]=http("$base/dashboard/admin.php?action=resolve_dispute",
        ['dispute_id'=>$dispD,'resolution'=>'Memihak Penjual','status'=>'Selesai','_csrf_token'=>$tokA],
        ['X-CSRF-Token: '.$tokA],$A);
    $jD=json_decode($b,true)?:[];
    $tD=$pdo->query("SELECT status,completed_by FROM transactions WHERE transaction_id=$txnD")->fetch(PDO::FETCH_ASSOC);
    check("resolve accepted (200 success)", $c===200 && ($jD['success']??false), $pass,$fail, substr($b,0,120));
    check("order -> Selesai (no longer stuck in Disahkan)", $tD['status']==='Selesai', $pass,$fail, json_encode($tD));
    check("completed_by = 'system' (ENUM-safe admin completion)", $tD['completed_by']==='system', $pass,$fail, json_encode($tD));

    // ================= L9 : one row per return despite duplicate payments =================
    $txnR = mkTxn($pdo,$madeTxn,$buyer,$sellerId,$productId,'Selesai','paid',$R(),1,0,'10.00');
    $pdo->prepare("INSERT INTO disputes (transaction_id,filed_by,title,description,severity,status) VALUES (?,?,'Pulangan needed','test','Sederhana','Selesai')")->execute([$txnR,$buyer]);
    $dispR=(int)$pdo->lastInsertId(); $madeDisp[]=$dispR;
    // Two COMPLETED payment rows for the same transaction.
    foreach([0,1] as $i){
        $pdo->prepare("INSERT INTO payments (transaction_id,payment_reference,payment_method,amount,status,refund_status,paid_at) VALUES (?,?, 'fpx', 10.00,'completed','none',NOW())")->execute([$txnR,$R()]);
        $madePay[]=(int)$pdo->lastInsertId();
    }
    $pdo->prepare("INSERT INTO return_shipments (dispute_id,transaction_id,status,notes,return_requested_at) VALUES (?,?,'pending','L9 dup-payment probe',NOW())")->execute([$dispR,$txnR]);
    $retR=(int)$pdo->lastInsertId(); $madeRet[]=$retR;
    echo "\n== L9: get_pending_returns yields one row despite 2 completed payments ==\n";
    [$c,$b]=http("$base/dashboard/admin_returns.php?action=get_pending_returns",null,['Accept: application/json'],$A);
    $jR=json_decode($b,true)?:[];
    $rowsForRet=array_values(array_filter($jR['returns']??[], fn($r)=>(int)$r['return_id']===$retR));
    check("request ok (200 success)", $c===200 && ($jR['success']??false), $pass,$fail, substr($b,0,100));
    check("return appears EXACTLY ONCE (no duplicate rows)", count($rowsForRet)===1, $pass,$fail, "rows=".count($rowsForRet));
    check("collapsed to a single latest payment_id", $rowsForRet && !empty($rowsForRet[0]['payment_id']), $pass,$fail, $rowsForRet?json_encode($rowsForRet[0]['payment_id']):'');

    // ================= L5 : get_orders exposes trust_profiles-based columns =================
    echo "\n== L5: seller get_orders uses trust_profiles (pembeli) ==\n";
    [$c,$b]=http("$base/dashboard/seller.php?action=get_orders",null,['Accept: application/json'],$S);
    $jO=json_decode($b,true)?:[];
    $first=$jO['orders'][0]??null;
    check("get_orders ok", $c===200 && ($jO['success']??false), $pass,$fail, substr($b,0,90));
    check("rows expose buyer_trust_evidence (trust_profiles column)", $first!==null && array_key_exists('buyer_trust_evidence',$first), $pass,$fail, $first?'keys ok':'no orders');
    check("query no longer selects the legacy mirror column", !str_contains((string)$b,'buyer_trust_score'), $pass,$fail);
} finally {
    // ---- Restore product baseline, then delete every crafted row (children first). ----
    $pdo->prepare("UPDATE products SET stock=?, sold_count=? WHERE product_id=?")->execute([$baseStock,$baseSold,$productId]);
    // Belt-and-suspenders: purge ANY E2ELGX_-prefixed leftovers (FK cascade clears
    // their payments/cash_payments/returns), so a prior aborted run cannot pollute.
    $pdo->prepare("DELETE FROM transactions WHERE payment_reference LIKE 'E2ELGX_%'")->execute();
    foreach($madeRet as $id) $pdo->prepare("DELETE FROM return_shipments WHERE return_id=?")->execute([$id]);
    foreach($madeDisp as $id) $pdo->prepare("DELETE FROM disputes WHERE dispute_id=?")->execute([$id]);
    foreach($madePay as $id) $pdo->prepare("DELETE FROM payments WHERE payment_id=?")->execute([$id]);
    foreach($madeCash as $id) $pdo->prepare("DELETE FROM cash_payments WHERE cash_payment_id=?")->execute([$id]);
    foreach($madeTxn as $id) $pdo->prepare("DELETE FROM cash_payments WHERE transaction_id=?")->execute([$id]);
    foreach($madeTxn as $id) $pdo->prepare("DELETE FROM transactions WHERE transaction_id=?")->execute([$id]);
    try { $pdo->prepare("CALL recalculate_trust_score(?)")->execute([$sellerId]); } catch (Exception $e) {}
    try { $pdo->prepare("CALL recalculate_buyer_trust_score(?)")->execute([$buyer]); } catch (Exception $e) {}
    echo "\ncleanup: ".count($madeTxn)." txns, ".count($madeDisp)." disputes, ".count($madeRet)." returns removed; product+trust restored\n";
}
echo "\n==================================\nRESULT: $pass passed, $fail failed\n==================================\n";
exit($fail===0?0:1);
