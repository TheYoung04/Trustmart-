<?php
// ============================================================
// scratch/test_smoke_checkout_payment_picker.php
// Verifies the product-checkout UI: only the payment TYPE radio
// pair is present; the redundant "Kaedah pembayaran" dropdown
// is gone and the JS no longer sends a payment_method field.
// Read-only; no orders are created.
// ============================================================
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$base = 'http://localhost/Trustmart';
$jar  = sys_get_temp_dir() . '/tm_checkout_picker_' . getmypid() . '.cookie';
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
    return ['code' => $code, 'body' => (string)$body];
}

$pass = 0; $fail = 0;
function check(string $n, bool $c, string $d = ''): void {
    global $pass, $fail;
    if ($c) { $pass++; echo "  PASS  $n\n"; }
    else    { $fail++; echo "  FAIL  $n  $d\n"; }
}

// Login as a buyer (haziq has a penjual role too but buyer.php flow works)
$land = curl($base . '/public/');
preg_match('/name="csrf-token" content="([^"]+)"/', $land['body'], $m);
curl($base . '/auth/login.php', [
    'email' => 'aishah@student.uthm.edu.my',
    'password' => 'password123',
    '_csrf_token' => $m[1],
]);

// Fetch a real product page (product_id=1 is the calculus book, in stock)
$prod = curl($base . '/product.php?id=1');
check('product.php returns 200', $prod['code'] === 200, "code={$prod['code']}");

echo "\n== Checkout picker ==\n";
check('payment-method dropdown removed',
      !str_contains($prod['body'], 'id="payment-method"'));
check('no "Kaedah pembayaran" sr-only label',
      !str_contains($prod['body'], 'Kaedah pembayaran'));
check('"Pindahan Bank" option string gone from HTML',
      !str_contains($prod['body'], '<option value="Pindahan Bank">'));
check('cash radio (value="cash") still present',
      str_contains($prod['body'], 'name="payment_type" value="cash"'));
check('online radio (value="online") still present',
      str_contains($prod['body'], 'name="payment_type" value="online"'));
check('syncPaymentMethod() removed',
      !str_contains($prod['body'], 'syncPaymentMethod'));
check('placeOrder no longer reads #payment-method',
      !preg_match("#getElementById\\('payment-method'\\)#", $prod['body']));
check('placeOrder body sends only quantity + payment_type',
      (bool) preg_match('#body:\s*new URLSearchParams\(\{\s*quantity:\s*qty,\s*payment_type:\s*paymentType\s*\}\)#', $prod['body']));

echo "\n== Server-side derivation ==";
// Static assertion on the source (we do not POST make_order to keep DB untouched)
$src = file_get_contents(__DIR__ . '/../product.php');
check('make_order derives payment_method from payment_type',
      (bool) preg_match("#\\\$payment_method\s*=\s*\(\\\$payment_type\s*===\s*'online'\)\s*\?\s*'E-Wallet'\s*:\s*'Tunai';#", $src));
check('make_order no longer reads POST payment_method',
      !preg_match("#\\\$payment_method\s*=\s*trim\(\\\$_POST\['payment_method'\]#", $src));

@unlink($jar);
curl($base . '/auth/logout.php');

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
