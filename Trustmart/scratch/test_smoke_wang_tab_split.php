<?php
// ============================================================
// scratch/test_smoke_wang_tab_split.php
// Read-only smoke check: the admin "Pulangan Wang" tab must
// exclude 'pending_return' payments (those belong on the
// separate "Pulangan Barang" page, admin_returns.php).
// ============================================================
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$base = 'http://localhost/Trustmart';
$jar  = sys_get_temp_dir() . '/tm_wang_split_' . getmypid() . '.cookie';
@unlink($jar);

function curl(string $url, ?array $post = null): array {
    global $jar;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code'=>$code, 'body'=>(string)$body];
}

$pass = 0; $fail = 0;
function check(string $n, bool $c, string $d = '') {
    global $pass, $fail;
    if ($c) { $pass++; echo "  PASS  $n\n"; }
    else    { $fail++; echo "  FAIL  $n  $d\n"; }
}

// Log in as admin.
$land = curl($base . '/public/');
if (!preg_match('/name="csrf-token" content="([^"]+)"/', $land['body'], $m)) {
    echo "cannot scrape CSRF\n"; exit(1);
}
curl($base . '/auth/login.php', [
    'email'=>$base ? 'admin@uthm.edu.my' : '',
    'password'=>'password123', '_csrf_token'=>$m[1],
]);

// 1) Default list must have no pending_return rows.
$r = json_decode(curl($base . '/dashboard/admin.php?action=get_refunds')['body'], true);
check('get_refunds returns success', !empty($r['success']));
$rows = $r['refunds'] ?? [];
$has_pending = false;
foreach ($rows as $row) {
    if (($row['refund_status'] ?? '') === 'pending_return') { $has_pending = true; break; }
}
check('default list excludes pending_return', !$has_pending,
      'found ' . count(array_filter($rows, fn($x) => ($x['refund_status'] ?? '') === 'pending_return')) . ' pending_return rows');

// 2) Explicit status=pending_return must now be ignored (falls through to no filter),
//    so we still get zero pending_return rows back.
$r2 = json_decode(curl($base . '/dashboard/admin.php?action=get_refunds&status=pending_return')['body'], true);
check('status=pending_return query is filtered out', !empty($r2['success']));
$rows2 = $r2['refunds'] ?? [];
$has2 = false;
foreach ($rows2 as $row) if (($row['refund_status'] ?? '') === 'pending_return') { $has2 = true; break; }
check('no pending_return even when explicitly requested', !$has2);

// 3) Summary no longer exposes pending_return_count.
$summary = $r['summary'] ?? null;
if (is_array($summary)) {
    check('summary drops pending_return_count',
          !array_key_exists('pending_return_count', $summary),
          'keys=' . implode(',', array_keys($summary)));
}

// 4) Sidebar still has the "Pulangan Barang" link (admin_returns.php).
$dash = curl($base . '/dashboard/admin.php');
check('sidebar keeps "Pulangan Barang" (admin_returns) link',
      strpos($dash['body'], 'admin_returns.php') !== false);

// 5) Wang tab no longer renders the pending_return filter button.
check('Wang tab filter row has no "Menunggu Pulangan Barang" button',
      strpos($dash['body'], 'data-filter="pending_return"') === false);

// 6) Pulangan Barang is now a TAB inside admin.php, not a link out.
check('Sidebar Pulangan Barang uses showTab("barang") not an <a href>',
      strpos($dash['body'], "showTab('barang')") !== false
      && strpos($dash['body'], 'href="admin_returns.php"') === false);
check('admin.php renders <div id="tab-barang"> container',
      strpos($dash['body'], 'id="tab-barang"') !== false);
check('TABS array includes "barang"',
      (bool) preg_match("/const\s+TABS\s*=\s*\[[^\]]*'barang'/", $dash['body']));

// 7) Visiting admin_returns.php with no action redirects to the tab.
$redir = curl($base . '/dashboard/admin_returns.php');
check('admin_returns.php (no action) => 302 redirect to admin.php?tab=barang',
      $redir['code'] === 302,
      'code=' . $redir['code']);

// 8) AJAX endpoints on admin_returns.php still work (Accept: json + no action bypass).
$ajax = curl($base . '/dashboard/admin_returns.php?action=get_pending_returns');
$j = json_decode($ajax['body'], true);
check('admin_returns.php AJAX still serves get_pending_returns',
      $ajax['code'] === 200 && !empty($j['success']));

@unlink($jar);
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
