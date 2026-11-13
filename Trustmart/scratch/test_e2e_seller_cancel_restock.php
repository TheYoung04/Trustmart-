<?php
/**
 * scratch/test_e2e_seller_cancel_restock.php — LIVE E2E for L3.
 * Proves a seller cancelling an order restores stock ONLY when cancelling from
 * 'Menunggu' (goods never dispatched), and does NOT restock when cancelling an
 * already-confirmed 'Disahkan' order (goods handed over) -- matching admin
 * dispute settlement's deliberate no-restock policy.
 *
 * Drives the real dashboard/seller.php?action=update_order_status endpoint over
 * HTTP against a running Apache+MySQL. Self-cleans crafted rows + restores the
 * product stock/sold_count baseline.
 * Run: php scratch/test_e2e_seller_cancel_restock.php  [http://localhost/Trustmart]
 */
$base = rtrim($argv[1] ?? 'http://localhost/Trustmart', '/');
$root = dirname(__DIR__);
require_once $root . '/config/db.php';

$pass = 0; $fail = 0;
function check($n, $c, &$pass, &$fail, $d = '') { echo ($c?"  [PASS] ":"  [FAIL] ").$n.($d?"  ($d)":"")."\n"; $c?$pass++:$fail++; }
$jar = sys_get_temp_dir().'/tm_seller_cancel.txt'; @unlink($jar);
function http($url,$post=null,$hdr=[]) { global $jar; $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIEJAR=>$jar,CURLOPT_COOKIEFILE=>$jar,CURLOPT_TIMEOUT=>20]);
    if($hdr) curl_setopt($ch,CURLOPT_HTTPHEADER,$hdr);
    if($post!==null){curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($post));}
    $b=curl_exec($ch); $c=curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch); return [$c,(string)$b];
}
function token_from($b){ if(preg_match('/name="csrf-token" content="([^"]+)"/',$b,$m))return $m[1]; if(preg_match('/name="_csrf_token" value="([^"]+)"/',$b,$m))return $m[1]; return ''; }

echo "Target: $base\n";

// Pick a product owned by an active seller with enough stock to hold two orders.
$prod = $pdo->query(
    "SELECT p.product_id, p.seller_id, p.stock, p.sold_count, u.email
       FROM products p
       JOIN users u ON u.user_id = p.seller_id
      WHERE u.role='penjual' AND u.is_active=1 AND p.stock >= 3
      ORDER BY p.product_id ASC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);
if (!$prod) { echo "No suitable seller/product found.\n"; exit(1); }
$productId=(int)$prod['product_id']; $sellerId=(int)$prod['seller_id'];
$baseStock=(int)$prod['stock']; $baseSold=(int)$prod['sold_count'];
$sellerEmail=$prod['email'];

// A buyer id for the crafted orders (any active pembeli).
$buyer = (int)$pdo->query("SELECT user_id FROM users WHERE role='pembeli' AND is_active=1 LIMIT 1")->fetchColumn();

echo "seller=$sellerEmail product=$productId buyer=$buyer (baseline stock=$baseStock sold=$baseSold)\n";

// Login the SELLER.
[, $page]=http("$base/public/"); $tok=token_from($page);
http("$base/auth/login.php", ['email'=>$sellerEmail,'password'=>'password123','_csrf_token'=>$tok], ['X-CSRF-Token: '.$tok,'Accept: application/json']);
[, $dash]=http("$base/dashboard/seller.php"); $tok=token_from($dash)?:$tok;
echo "logged in (seller dashboard bytes=".strlen($dash).")\n";

$madeTxn=[];
// Craft a Menunggu/Disahkan order AND simulate product.php's creation-time stock
// decrement (stock-qty, sold+qty) so the baseline reflects a live order.
function craft($pdo,&$madeTxn,$buyer,$sellerId,$productId,$status,$qty){
    $ref='E2EL3_'.$status.'_'.bin2hex(random_bytes(3));
    $pdo->prepare(
        "INSERT INTO transactions
            (buyer_id,seller_id,product_id,quantity,total_amount,payment_method,
             status,payment_type,payment_status,payment_reference,created_at)
         VALUES (?,?,?,?,?, 'Tunai', ?, 'cash', 'unpaid_cash', ?, NOW())"
    )->execute([$buyer,$sellerId,$productId,$qty,number_format($qty*10,2,'.',''),$status,$ref]);
    $txn=(int)$pdo->lastInsertId(); $madeTxn[]=$txn;
    // simulate checkout decrement
    $pdo->prepare("UPDATE products SET stock=stock-?, sold_count=sold_count+? WHERE product_id=?")
       ->execute([$qty,$qty,$productId]);
    return $txn;
}
function prod_now($pdo,$p){ return $pdo->query("SELECT stock,sold_count FROM products WHERE product_id=$p")->fetch(PDO::FETCH_ASSOC); }
function txn_row($pdo,$t){ return $pdo->query("SELECT status,cancelled_by FROM transactions WHERE transaction_id=$t")->fetch(PDO::FETCH_ASSOC); }
function cancel($tok,$txn){ global $base;
    return http("$base/dashboard/seller.php?action=update_order_status",
        ['transaction_id'=>$txn,'status'=>'Dibatalkan','_csrf_token'=>$tok],
        ['X-CSRF-Token: '.$tok]);
}

try {
    // ===== A: cancel from Menunggu -> RESTOCK =====
    $qtyA=1;
    $txnA=craft($pdo,$madeTxn,$buyer,$sellerId,$productId,'Menunggu',$qtyA);   // stock=$baseStock-1
    [$c,$b]=cancel($tok,$txnA);
    $jsonA=json_decode($b,true)?:[];
    $tA=txn_row($pdo,$txnA); $pA=prod_now($pdo,$productId);
    echo "\n== A: cancel from Menunggu (restock expected) ==\n";
    check("cancel accepted (200 success)", $c===200 && ($jsonA['success']??false), $pass,$fail, substr($b,0,120));
    check("order -> Dibatalkan by seller", $tA['status']==='Dibatalkan' && $tA['cancelled_by']==='seller', $pass,$fail, json_encode($tA));
    check("STOCK RESTORED (+$qtyA) -> back to baseline $baseStock", (int)$pA['stock']===$baseStock, $pass,$fail, json_encode($pA));
    check("sold_count decremented (-$qtyA) -> back to baseline $baseSold", (int)$pA['sold_count']===$baseSold, $pass,$fail, json_encode($pA));

    // ===== B: cancel from Disahkan -> NO RESTOCK =====
    $qtyB=1;
    $txnB=craft($pdo,$madeTxn,$buyer,$sellerId,$productId,'Disahkan',$qtyB);   // stock=$baseStock-1, sold=$baseSold+1
    $stockBeforeB=(int)prod_now($pdo,$productId)['stock'];
    $soldBeforeB=(int)prod_now($pdo,$productId)['sold_count'];
    [$c,$b]=cancel($tok,$txnB);
    $jsonB=json_decode($b,true)?:[];
    $tB=txn_row($pdo,$txnB); $pB=prod_now($pdo,$productId);
    echo "\n== B: cancel from Disahkan (NO restock expected) ==\n";
    check("cancel accepted (200 success)", $c===200 && ($jsonB['success']??false), $pass,$fail, substr($b,0,120));
    check("order -> Dibatalkan by seller", $tB['status']==='Dibatalkan' && $tB['cancelled_by']==='seller', $pass,$fail, json_encode($tB));
    check("STOCK UNCHANGED (goods already handed over)", (int)$pB['stock']===$stockBeforeB, $pass,$fail, "before=$stockBeforeB now={$pB['stock']}");
    check("sold_count UNCHANGED (still counts the dispatched unit)", (int)$pB['sold_count']===$soldBeforeB, $pass,$fail, "before=$soldBeforeB now={$pB['sold_count']}");
} finally {
    // Restore exact baseline, then remove crafted rows.
    $pdo->prepare("UPDATE products SET stock=?, sold_count=? WHERE product_id=?")
       ->execute([$baseStock,$baseSold,$productId]);
    foreach($madeTxn as $id){ $pdo->prepare("DELETE FROM payments WHERE transaction_id=?")->execute([$id]); $pdo->prepare("DELETE FROM cash_payments WHERE transaction_id=?")->execute([$id]); $pdo->prepare("DELETE FROM transactions WHERE transaction_id=?")->execute([$id]); }
    echo "\ncleanup: ".count($madeTxn)." orders removed; product baseline restored\n";
}
echo "\n==================================\nRESULT: $pass passed, $fail failed\n==================================\n";
exit($fail===0?0:1);
