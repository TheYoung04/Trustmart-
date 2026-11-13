<?php
// scratch/test_dispute_trust_effect.php — NUMERICALLY verify the claim:
//   "Resolving a dispute in the BUYER's favour lowers the SELLER's trust score
//    (via the 'disputes' component), while the winning BUYER is not penalised.
//    The refund money-movement itself is invisible to trust scoring."
//
// Method (isolates the dispute as the ONLY variable):
//   S0 = seller's true baseline profile.
//   Insert one extra 'Selesai' order for the seller  -> S1 (recalc fires on txn insert).
//   Insert an open dispute on that order.
//   Resolve it via the REAL admin endpoint (Memihak Pembeli, no return, keep-selesai)
//     -> S2 (recalc fires on dispute update trigger).
//   Compare S1 vs S2: seller disputes points + total score must DROP; buyer must not.
//   Cleanup deletes the crafted dispute+order and recomputes -> S3 (must equal S0).
//
// Self-cleaning (register_shutdown). Usage: php scratch/test_dispute_trust_effect.php [base-url]
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("CLI only\n"); }
require_once __DIR__ . '/../config/db.php';

$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
$jar  = sys_get_temp_dir() . '/tm_disp_trust.txt'; @unlink($jar);
$PASS = 0; $FAIL = 0;
function check($n,$c,$d=''){ global $PASS,$FAIL; if($c){$PASS++;echo "  PASS  $n\n";} else {$FAIL++;echo "  FAIL  $n  -- $d\n";} }
function http($url,$post=null){ global $jar; $ch=curl_init($url);
  curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>true,CURLOPT_MAXREDIRS=>5,
    CURLOPT_COOKIEJAR=>$jar,CURLOPT_COOKIEFILE=>$jar,CURLOPT_TIMEOUT=>20]);
  if($post!==null){curl_setopt($ch,CURLOPT_POST,true);curl_setopt($ch,CURLOPT_POSTFIELDS,http_build_query($post));}
  $b=curl_exec($ch);$c=curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);return [$c,(string)$b]; }

$SELLER = 16;   // mirza@student.uthm.edu.my — has real evidence (score 40.04, 3 completed)
$BUYER  = 1;    // aishah@student.uthm.edu.my
$madeTxn = null; $madeDisp = null;

function profile($pdo,$uid,$role){
  $p=$pdo->prepare("SELECT score,confidence,evidence_units,completed_transactions FROM trust_profiles WHERE user_id=? AND profile_role=?");
  $p->execute([$uid,$role]); $row=$p->fetch(PDO::FETCH_ASSOC) ?: [];
  $c=$pdo->prepare("SELECT component_code,points,metric_value,details FROM trust_score_components WHERE user_id=? AND profile_role=?");
  $c->execute([$uid,$role]);
  foreach($c->fetchAll(PDO::FETCH_ASSOC) as $comp){ $row['c_'.$comp['component_code'].'_points']=(float)$comp['points']; $row['c_'.$comp['component_code'].'_details']=$comp['details']; }
  return $row;
}

register_shutdown_function(function() use ($pdo,$SELLER,$BUYER,&$madeTxn,&$madeDisp){
  if($madeDisp){ $pdo->prepare("DELETE FROM return_shipments WHERE dispute_id=?")->execute([$madeDisp]);
                 $pdo->prepare("DELETE FROM disputes WHERE dispute_id=?")->execute([$madeDisp]); }
  if($madeTxn){  $pdo->prepare("DELETE FROM transactions WHERE transaction_id=?")->execute([$madeTxn]); }
  try{$pdo->prepare("CALL recalculate_trust_score(?)")->execute([$SELLER]);}catch(Exception $e){}
  try{$pdo->prepare("CALL recalculate_buyer_trust_score(?)")->execute([$BUYER]);}catch(Exception $e){}
  @unlink($jar);
  echo "\n[teardown] crafted rows removed + both profiles recomputed from clean tables\n";
});

echo "Seller #$SELLER / Buyer #$BUYER\n";
$S0 = profile($pdo,$SELLER,'penjual');
printf("S0 baseline seller: score=%.2f evidence=%.2f completed=%d disputes_pts=%.2f (%s)\n",
  $S0['score'],$S0['evidence_units'],$S0['completed_transactions'],$S0['c_disputes_points'],$S0['c_disputes_details']);

// ── craft one extra COMPLETED order for the seller ──────────────────
$prod = $pdo->prepare("SELECT product_id FROM products WHERE seller_id=? AND status='Aktif' LIMIT 1");
$prod->execute([$SELLER]); $pid=$prod->fetchColumn() ?: $pdo->query("SELECT product_id FROM products LIMIT 1")->fetchColumn();
$ins=$pdo->prepare("INSERT INTO transactions (buyer_id,seller_id,product_id,quantity,total_amount,payment_method,payment_type,status,confirmed_at,completed_at,created_at)
  VALUES (?,?,?,1,50.00,'Tunai','cash','Selesai',NOW(),NOW(),NOW())");
$ins->execute([$BUYER,$SELLER,$pid]);
$madeTxn=(int)$pdo->lastInsertId();
$S1 = profile($pdo,$SELLER,'penjual'); $B1 = profile($pdo,$BUYER,'pembeli');
printf("S1 after +1 Selesai order (BEFORE dispute): score=%.2f evidence=%.2f completed=%d disputes_pts=%.2f (%s)\n",
  $S1['score'],$S1['evidence_units'],$S1['completed_transactions'],$S1['c_disputes_points'],$S1['c_disputes_details']);

// ── open dispute on that order ──────────────────────────────────────
$d=$pdo->prepare("INSERT INTO disputes (transaction_id,filed_by,title,description,severity,status) VALUES (?,?,?,?, 'Sederhana','Terbuka')");
$d->execute([$madeTxn,$BUYER,'Ujian: barang tidak sampai','Crafted dispute to verify trust effect (auto-cleaned).']);
$madeDisp=(int)$pdo->lastInsertId();

// ── resolve via the REAL admin endpoint (buyer-favourable) ──────────
[$c,$b]=http("$base/index.php");
$adminEmail=$pdo->query("SELECT email FROM users WHERE role='pentadbir' LIMIT 1")->fetchColumn();
[$c,$b]=http("$base/auth/login.php",['email'=>$adminEmail,'password'=>'password123','_csrf_token'=>(preg_match('/name="csrf-token" content="([^"]+)"/',$b,$m)?$m[1]:'')]);
check('admin login', (json_decode($b,true)['success']??false)===true, "http=$c");
[$c,$page]=http("$base/dashboard/admin.php");
$tok = preg_match('/name="csrf-token" content="([^"]+)"/',$page,$m2)?$m2[1]:'';
[$c,$b]=http("$base/dashboard/admin.php?action=resolve_dispute",[
  'dispute_id'=>$madeDisp,'resolution'=>'Memihak Pembeli','status'=>'Selesai',
  'settle_return_required'=>'0','settle_fulfilment'=>'Kekal Selesai','settle_notes'=>'trust-effect test','_csrf_token'=>$tok]);
$rj=json_decode($b,true);
check('resolve_dispute endpoint succeeded', ($rj['success']??false)===true, "http=$c body=".substr($b,0,160));
echo "  endpoint said: ".($rj['message']??'')."\n";

$S2 = profile($pdo,$SELLER,'penjual'); $B2 = profile($pdo,$BUYER,'pembeli');
printf("S2 after BUYER-FAVOURABLE dispute: score=%.2f disputes_pts=%.2f (%s)\n",$S2['score'],$S2['c_disputes_points'],$S2['c_disputes_details']);
printf("B1 buyer (before): score=%.2f disputes_pts=%.2f (%s)\n",$B1['score'],$B1['c_disputes_points'],$B1['c_disputes_details']);
printf("B2 buyer (after) : score=%.2f disputes_pts=%.2f (%s)\n",$B2['score'],$B2['c_disputes_points'],$B2['c_disputes_details']);

echo "\n---- ASSERTIONS ----\n";
$c1=(int)trim(explode(' ',(string)$S1['c_disputes_details'])[0]);
$c2=(int)trim(explode(' ',(string)$S2['c_disputes_details'])[0]);
check('seller adverse-dispute count increased by exactly 1', $c2 === $c1+1, "S1=$c1 S2=$c2 (details: {$S2['c_disputes_details']})");
check('seller DISPUTES component DROPPED', $S2['c_disputes_points'] < $S1['c_disputes_points'], "S1={$S1['c_disputes_points']} S2={$S2['c_disputes_points']}");
check('seller TOTAL score DROPPED', $S2['score'] < $S1['score'], "S1={$S1['score']} S2={$S2['score']}");
check('buyer disputes component UNCHANGED (winning is not adverse for buyer)', abs($B2['c_disputes_points']-$B1['c_disputes_points'])<0.001, "B1={$B1['c_disputes_points']} B2={$B2['c_disputes_points']}");
check('buyer NOT penalised on total score (order kept Selesai)', abs($B2['score']-$B1['score'])<0.001, "B1={$B1['score']} B2={$B2['score']}");
printf("\nSeller disputes pts: %.2f -> %.2f (delta %.2f) | total score: %.2f -> %.2f (delta %.2f)\n",
  $S1['c_disputes_points'],$S2['c_disputes_points'],$S2['c_disputes_points']-$S1['c_disputes_points'],
  $S1['score'],$S2['score'],$S2['score']-$S1['score']);

// ── cleanup happens in shutdown; verify restore here afterwards in a second run is not needed ──
echo "\n==== RESULT: $PASS PASS / $FAIL FAIL ====\n";
$ok = $FAIL===0;
// confirm restore
register_shutdown_function(function() use ($pdo,$SELLER,$S0){
  $s3=profile($pdo,$SELLER,'penjual');
  printf("[restore-check] seller score back to %.2f (baseline %.2f) => %s\n",$s3['score'],$S0['score'],
    (abs($s3['score']-$S0['score'])<0.01?'OK':'MISMATCH'));
});
exit($ok?0:1);
