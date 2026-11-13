<?php
/**
 * scratch/test_e2e_dispute_settlement.php — LIVE E2E for Fix #7.
 * Drives the real admin.php?action=resolve_dispute endpoint over HTTP against
 * a running Apache+MySQL. Proves a buyer-favourable decision now (a) persists an
 * explicit settlement, (b) enqueues the refund onto the admin queue, (c) never
 * auto-restocks, and (d) is idempotent. Self-cleans crafted rows + restores stock.
 * Run: php scratch/test_e2e_dispute_settlement.php   [http://localhost/Trustmart]
 */
$base = rtrim($argv[1] ?? 'http://localhost/Trustmart', '/');
$root = dirname(__DIR__);
require_once $root . '/config/db.php';

$pass = 0; $fail = 0;
function check($n, $c, &$pass, &$fail, $d = '') { echo ($c?"  [PASS] ":"  [FAIL] ").$n.($d?"  ($d)":"")."\n"; $c?$pass++:$fail++; }
$jar = sys_get_temp_dir().'/tm_disp.txt'; @unlink($jar);
function http($url,$post=null,$hdr=[]) { global $jar; $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIEJAR=>$jar,CURLOPT_COOKIEFILE=>$jar,CURLOPT_TIMEOUT=>20]);
    if($hdr) curl_setopt($ch,CURLOPT_HTTPHEADER,$hdr);
    if($post!==null){curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($post));}
    $b=curl_exec($ch); $c=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch); return [$c,(string)$b];
}
function token_from($b){ if(preg_match('/name="csrf-token" content="([^"]+)"/',$b,$m))return $m[1]; if(preg_match('/name="_csrf_token" value="([^"]+)"/',$b,$m))return $m[1]; return ''; }

echo "Target: $base\n";
$adminEmail = $pdo->query("SELECT email FROM users WHERE role='pentadbir' AND is_active=1 LIMIT 1")->fetchColumn();
$buyer = $pdo->query("SELECT u.user_id FROM users u WHERE u.role='pembeli' AND u.is_active=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC)['user_id'];
$prod = $pdo->query("SELECT product_id, seller_id FROM products ORDER BY product_id ASC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$productId=(int)$prod['product_id']; $sellerId=(int)$prod['seller_id'];
$snap = $pdo->query("SELECT stock,sold_count FROM products WHERE product_id=$productId")->fetch(PDO::FETCH_ASSOC);
echo "admin=$adminEmail buyer=$buyer product=$productId seller=$sellerId (stock={$snap['stock']},sold={$snap['sold_count']})\n";

[, $page]=http("$base/public/"); $tok=token_from($page);
http("$base/auth/login.php", ['email'=>$adminEmail,'password'=>'password123','_csrf_token'=>$tok], ['X-CSRF-Token: '.$tok,'Accept: application/json']);
[, $dash]=http("$base/dashboard/admin.php"); $tok=token_from($dash)?:$tok;
echo "logged in (admin dashboard bytes=".strlen($dash).")\n";

$madeTxn=[]; $madeDisp=[];
// craft a paid order (+optional completed gateway payment) and an OPEN dispute
function craft($pdo,&$madeTxn,&$madeDisp,$buyer,$sellerId,$productId,$online,$amount){
    $ref='E2EDISP_'.$online.'_'.$amount.'_'.bin2hex(random_bytes(3));
    $pdo->prepare("INSERT INTO transactions (buyer_id,seller_id,product_id,quantity,total_amount,payment_method,status,payment_type,payment_status,payment_reference,created_at)
        VALUES (?,?,?,1,?,'E-Wallet','Disahkan',?,'paid',?,NOW())")->execute([$buyer,$sellerId,$productId,$amount,$online?'online':'cash',$ref]);
    $txn=(int)$pdo->lastInsertId(); $madeTxn[]=$txn;
    if($online){ $pdo->prepare("INSERT INTO payments (transaction_id,payment_reference,payment_method,amount,status,refund_status,paid_at) VALUES (?,?,'fpx',?,'completed','none',NOW())")->execute([$txn,$ref,$amount]); }
    $pdo->prepare("INSERT INTO disputes (transaction_id,filed_by,title,description,severity,status) VALUES (?,?,'Barang rosak','test','Sederhana','Terbuka')")->execute([$txn,$buyer]);
    $disp=(int)$pdo->lastInsertId(); $madeDisp[]=$disp;
    return [$txn,$disp,$ref];
}
function resolve($tok,$disp,$settle){ global $base;
    $post=array_merge(['dispute_id'=>$disp,'resolution'=>'Memihak Pembeli','status'=>'Selesai','_csrf_token'=>$tok],$settle);
    return http("$base/dashboard/admin.php?action=resolve_dispute",$post,['X-CSRF-Token: '.$tok]);
}
function disp_row($pdo,$d){ return $pdo->query("SELECT status,resolution,settlement_status,settlement_refund_amount,settlement_return_required,settlement_fulfilment,settled_by FROM disputes WHERE dispute_id=$d")->fetch(PDO::FETCH_ASSOC); }
function pay_row($pdo,$ref){ $r=$pdo->query("SELECT status,refund_status,refund_method FROM payments WHERE payment_reference='$ref'")->fetch(PDO::FETCH_ASSOC); return $r?:null; }
function txn_status($pdo,$t){ return $pdo->query("SELECT status,cancelled_by FROM transactions WHERE transaction_id=$t")->fetch(PDO::FETCH_ASSOC); }
function prod_now($pdo,$p){ return $pdo->query("SELECT stock,sold_count FROM products WHERE product_id=$p")->fetch(PDO::FETCH_ASSOC); }

try {
    // ===== A: online paid, buyer-favourable, Kekal Selesai =====
    [$txnA,$dispA,$refA]=craft($pdo,$madeTxn,$madeDisp,$buyer,$sellerId,$productId,true,'200.00');
    [$c,$b]=resolve($tok,$dispA,['settle_refund_amount'=>'150.00','settle_return_required'=>'1','settle_fulfilment'=>'Kekal Selesai','settle_notes'=>'separuh bayaran']);
    $d=disp_row($pdo,$dispA); $p=pay_row($pdo,$refA); $t=txn_status($pdo,$txnA); $pn=prod_now($pdo,$productId);
    echo "\n== A: buyer-favourable settlement (online) ==\n";
    check("resolve accepted (200 success)", $c===200 && (json_decode($b,true)['success']??false), $pass,$fail, substr($b,0,90));
    check("dispute closed Memihak Pembeli/Selesai", $d['status']==='Selesai' && $d['resolution']==='Memihak Pembeli', $pass,$fail);
    check("settlement persisted (queued, amount, return, fulfilment)", $d['settlement_status']==='queued' && (float)$d['settlement_refund_amount']===150.00 && (int)$d['settlement_return_required']===1 && $d['settlement_fulfilment']==='Kekal Selesai', $pass,$fail, json_encode($d));
    check("refund ENQUEUED on payment (pending_return/admin because return required)", $p && $p['refund_status']==='pending_return' && $p['refund_method']==='admin', $pass,$fail, json_encode($p));
    check("payment NOT auto-paid-back yet (still completed)", $p && $p['status']==='completed', $pass,$fail);
    check("order kept (no void, no restock)", $t['status']==='Disahkan', $pass,$fail, json_encode($t));
    check("NO restock: product stock/sold unchanged", $pn['stock']===$snap['stock'] && $pn['sold_count']===$snap['sold_count'], $pass,$fail, json_encode($pn));

    // ===== B: idempotency — resolving again must not double-enqueue =====
    [$c2,$b2]=resolve($tok,$dispA,['settle_refund_amount'=>'200.00','settle_return_required'=>'0','settle_fulfilment'=>'Dibatalkan']);
    $d2=disp_row($pdo,$dispA); $p2=pay_row($pdo,$refA); $t2=txn_status($pdo,$txnA);
    echo "\n== B: idempotency (re-resolve same dispute) ==\n";
    check("second resolve rejected (422)", $c2===422, $pass,$fail, "http=$c2");
    check("settlement amount unchanged (still 150, not overwritten)", (float)$d2['settlement_refund_amount']===150.00, $pass,$fail);
    check("refund still single 'pending_return' (not re-enqueued)", $p2 && $p2['refund_status']==='pending_return', $pass,$fail, json_encode($p2));
    check("order not voided by replay", $t2['status']==='Disahkan', $pass,$fail);

    // ===== C: buyer-favourable with Dibatalkan fulfilment -> void, still NO restock =====
    [$txnC,$dispC,$refC]=craft($pdo,$madeTxn,$madeDisp,$buyer,$sellerId,$productId,true,'120.00');
    [$c,$b]=resolve($tok,$dispC,['settle_refund_amount'=>'120.00','settle_return_required'=>'1','settle_fulfilment'=>'Dibatalkan']);
    $t=txn_status($pdo,$txnC); $d=disp_row($pdo,$dispC); $pn=prod_now($pdo,$productId);
    echo "\n== C: void-fulfilment path (no restock) ==\n";
    check("order -> Dibatalkan by admin", $t['status']==='Dibatalkan' && $t['cancelled_by']==='admin', $pass,$fail, json_encode($t));
    check("settlement fulfilment recorded", $d['settlement_fulfilment']==='Dibatalkan', $pass,$fail);
    check("STILL no restock after void", $pn['stock']===$snap['stock'] && $pn['sold_count']===$snap['sold_count'], $pass,$fail, json_encode($pn));

    // ===== D: cash paid order (no gateway payment) -> queued decision, no false enqueue =====
    [$txnD,$dispD,$refD]=craft($pdo,$madeTxn,$madeDisp,$buyer,$sellerId,$productId,false,'90.00');
    [$c,$b]=resolve($tok,$dispD,['settle_refund_amount'=>'90.00','settle_return_required'=>'0','settle_fulfilment'=>'Kekal Selesai']);
    $d=disp_row($pdo,$dispD); $p=pay_row($pdo,$refD);
    echo "\n== D: cash order settlement (no gateway payment) ==\n";
    check("settlement decision persisted (queued)", $d['settlement_status']==='queued' && (float)$d['settlement_refund_amount']===90.00, $pass,$fail);
    check("no phantom payment row enqueued", $p===null, $pass,$fail);

    // ===== E: L2 regression — Selesai order + Dibatalkan fulfilment must VOID, not silent-drop =====
    // Craft the order, then promote it to Selesai (as if the buyer confirmed
    // receipt) before filing the dispute. The old code silently dropped the
    // fulfilment here because Selesai was not in the whitelist. The fix must
    // apply the void with admin attribution and still skip stock restoration.
    [$txnE,$dispE,$refE]=craft($pdo,$madeTxn,$madeDisp,$buyer,$sellerId,$productId,true,'75.00');
    $pdo->prepare("UPDATE transactions SET status='Selesai' WHERE transaction_id=?")->execute([$txnE]);
    // Also move the open-dispute filing to reflect that the buyer complained AFTER delivery.
    [$c,$b]=resolve($tok,$dispE,['settle_refund_amount'=>'75.00','settle_return_required'=>'1','settle_fulfilment'=>'Dibatalkan','settle_notes'=>'L2: Selesai -> Dibatalkan honored']);
    $jsonE=json_decode($b,true)?:[];
    $tE=txn_status($pdo,$txnE); $dE=disp_row($pdo,$dispE); $pE=pay_row($pdo,$refE); $pnE=prod_now($pdo,$productId);
    echo "\n== E: L2 - Selesai + Dibatalkan fulfilment (regression) ==\n";
    check("resolve accepted (200 success)", $c===200 && ($jsonE['success']??false), $pass,$fail, substr($b,0,140));
    check("SETTLEMENT NOTE reflects the Selesai->Dibatalkan transition (not silent)",
        isset($jsonE['message']) && (str_contains($jsonE['message'], 'Selesai telah dibatalkan') || str_contains($jsonE['message'], 'dibatalkan oleh PTU')),
        $pass,$fail, substr($jsonE['message']??'',0,220));
    check("order -> Dibatalkan by admin (from Selesai)", $tE['status']==='Dibatalkan' && $tE['cancelled_by']==='admin', $pass,$fail, json_encode($tE));
    check("settlement_fulfilment recorded as Dibatalkan", $dE['settlement_fulfilment']==='Dibatalkan', $pass,$fail);
    check("refund still enqueued (pending_return because return required)", $pE && $pE['refund_status']==='pending_return', $pass,$fail, json_encode($pE));
    check("NO restock after Selesai->Dibatalkan void", $pnE['stock']===$snap['stock'] && $pnE['sold_count']===$snap['sold_count'], $pass,$fail, json_encode($pnE));
} finally {
    foreach($madeDisp as $id) $pdo->prepare("DELETE FROM disputes WHERE dispute_id=?")->execute([$id]);
    foreach($madeTxn as $id){ $pdo->prepare("DELETE FROM payments WHERE transaction_id=?")->execute([$id]); $pdo->prepare("DELETE FROM transactions WHERE transaction_id=?")->execute([$id]); }
    $pdo->prepare("UPDATE products SET stock=?, sold_count=? WHERE product_id=?")->execute([$snap['stock'],$snap['sold_count'],$productId]);
    // Neutralize trust-trigger side effects: the dispute delete recompute ran
    // while the crafted transaction still existed, so recompute once more from
    // the now-clean tables (recalculate_* procedures are full aggregate recomputes).
    try { $pdo->prepare("CALL recalculate_trust_score(?)")->execute([$sellerId]); } catch (Exception $e) {}
    try { $pdo->prepare("CALL recalculate_buyer_trust_score(?)")->execute([$buyer]); } catch (Exception $e) {}
    echo "\ncleanup: ".count($madeTxn)." orders + disputes removed; product + trust restored\n";
}
echo "\n==================================\nRESULT: $pass passed, $fail failed\n==================================\n";
exit($fail===0?0:1);
