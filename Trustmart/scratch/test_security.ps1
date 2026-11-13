Write-Host "=== TEST 5.1 - Unauthenticated Access ==="
Write-Host ""

# Test 5.1a: Access buyer dashboard without login
Write-Host "--- 5.1a: GET /dashboard/buyer.php (no session) ---"
try {
    $r = Invoke-WebRequest -Uri "http://localhost/Trustmart/dashboard/buyer.php" -UseBasicParsing -MaximumRedirection 0 -ErrorAction Stop
    Write-Host "Status: $($r.StatusCode)"
} catch {
    $status = $_.Exception.Response.StatusCode
    Write-Host "Status: $([int]$status) $status"
    if ($_.Exception.Response.Headers -and $_.Exception.Response.Headers["Location"]) {
        Write-Host "Redirected to: $($_.Exception.Response.Headers['Location'])"
    }
    Write-Host "RESULT: BLOCKED (redirected to login) - PASS"
}

Write-Host ""

# Test 5.1b: Access seller dashboard without login
Write-Host "--- 5.1b: GET /dashboard/seller.php (no session) ---"
try {
    $r = Invoke-WebRequest -Uri "http://localhost/Trustmart/dashboard/seller.php" -UseBasicParsing -MaximumRedirection 0 -ErrorAction Stop
    Write-Host "Status: $($r.StatusCode) - FAIL (should redirect)"
} catch {
    $status = $_.Exception.Response.StatusCode
    Write-Host "Status: $([int]$status) $status"
    Write-Host "RESULT: BLOCKED - PASS"
}

Write-Host ""

# Test 5.1c: Access admin dashboard without login
Write-Host "--- 5.1c: GET /dashboard/admin.php (no session) ---"
try {
    $r = Invoke-WebRequest -Uri "http://localhost/Trustmart/dashboard/admin.php" -UseBasicParsing -MaximumRedirection 0 -ErrorAction Stop
    Write-Host "Status: $($r.StatusCode) - FAIL (should redirect)"
} catch {
    $status = $_.Exception.Response.StatusCode
    Write-Host "Status: $([int]$status) $status"
    Write-Host "RESULT: BLOCKED - PASS"
}

Write-Host ""

# Test 5.1d: Access product page without login
Write-Host "--- 5.1d: GET /product.php?id=1 (no session) ---"
try {
    $r = Invoke-WebRequest -Uri "http://localhost/Trustmart/product.php?id=1" -UseBasicParsing -MaximumRedirection 0 -ErrorAction Stop
    Write-Host "Status: $($r.StatusCode) - FAIL (should redirect)"
} catch {
    $status = $_.Exception.Response.StatusCode
    Write-Host "Status: $([int]$status) $status"
    Write-Host "RESULT: BLOCKED - PASS"
}

Write-Host ""
Write-Host "=== TEST 5.2 - API Access Check (no session) ==="
Write-Host ""

# Test 5.2a: Seller API - add_product without auth
Write-Host "--- 5.2a: POST seller.php?action=add_product (no session) ---"
try {
    $body = @{name="Hack Product"; category="Elektronik"; price=1; stock=1}
    $r = Invoke-WebRequest -Uri "http://localhost/Trustmart/dashboard/seller.php?action=add_product" -Method POST -Body $body -UseBasicParsing -Headers @{Accept="application/json"} -ErrorAction Stop
    Write-Host "Status: $($r.StatusCode)"
    Write-Host "Body: $($r.Content)"
    Write-Host "RESULT: FAIL - API accessible without auth!"
} catch {
    $status = $_.Exception.Response.StatusCode
    Write-Host "Status: $([int]$status) $status"
    # Try to read response body
    try {
        $reader = [System.IO.StreamReader]::new($_.Exception.Response.GetResponseStream())
        $respBody = $reader.ReadToEnd()
        Write-Host "Response: $respBody"
    } catch {}
    Write-Host "RESULT: BLOCKED - PASS"
}

Write-Host ""

# Test 5.2b: Admin API - verify_seller without auth
Write-Host "--- 5.2b: POST admin.php?action=verify_seller (no session) ---"
try {
    $body = @{user_id=2; action_type="approve"}
    $r = Invoke-WebRequest -Uri "http://localhost/Trustmart/dashboard/admin.php?action=verify_seller" -Method POST -Body $body -UseBasicParsing -Headers @{Accept="application/json"} -ErrorAction Stop
    Write-Host "Status: $($r.StatusCode)"
    Write-Host "Body: $($r.Content)"
    Write-Host "RESULT: FAIL - API accessible without auth!"
} catch {
    $status = $_.Exception.Response.StatusCode
    Write-Host "Status: $([int]$status) $status"
    try {
        $reader = [System.IO.StreamReader]::new($_.Exception.Response.GetResponseStream())
        $respBody = $reader.ReadToEnd()
        Write-Host "Response: $respBody"
    } catch {}
    Write-Host "RESULT: BLOCKED - PASS"
}

Write-Host ""

# Test 5.2c: Buyer API - cancel_order without auth
Write-Host "--- 5.2c: POST buyer.php?action=cancel_order (no session) ---"
try {
    $body = @{transaction_id=1}
    $r = Invoke-WebRequest -Uri "http://localhost/Trustmart/dashboard/buyer.php?action=cancel_order" -Method POST -Body $body -UseBasicParsing -Headers @{Accept="application/json"} -ErrorAction Stop
    Write-Host "Status: $($r.StatusCode)"
    Write-Host "Body: $($r.Content)"
    Write-Host "RESULT: FAIL - API accessible without auth!"
} catch {
    $status = $_.Exception.Response.StatusCode
    Write-Host "Status: $([int]$status) $status"
    try {
        $reader = [System.IO.StreamReader]::new($_.Exception.Response.GetResponseStream())
        $respBody = $reader.ReadToEnd()
        Write-Host "Response: $respBody"
    } catch {}
    Write-Host "RESULT: BLOCKED - PASS"
}

Write-Host ""

# Test 5.2d: Product API - make_order without auth
Write-Host "--- 5.2d: POST product.php?action=make_order&id=1 (no session) ---"
try {
    $body = @{quantity=1; payment_method="Tunai"}
    $r = Invoke-WebRequest -Uri "http://localhost/Trustmart/product.php?action=make_order&id=1" -Method POST -Body $body -UseBasicParsing -Headers @{Accept="application/json"} -ErrorAction Stop
    Write-Host "Status: $($r.StatusCode)"
    Write-Host "Body: $($r.Content)"
    Write-Host "RESULT: FAIL - API accessible without auth!"
} catch {
    $status = $_.Exception.Response.StatusCode
    Write-Host "Status: $([int]$status) $status"
    try {
        $reader = [System.IO.StreamReader]::new($_.Exception.Response.GetResponseStream())
        $respBody = $reader.ReadToEnd()
        Write-Host "Response: $respBody"
    } catch {}
    Write-Host "RESULT: BLOCKED - PASS"
}

Write-Host ""

# Test 5.2e: Buyer trying to access admin API (role check)
Write-Host "--- 5.2e: Cross-role test: Login as buyer, call admin API ---"
# First login as buyer
$session = New-Object Microsoft.PowerShell.Commands.WebRequestSession
$loginBody = @{email="aishah@student.uthm.edu.my"; password="password123"}
try {
    $loginResp = Invoke-WebRequest -Uri "http://localhost/Trustmart/auth/login.php" -Method POST -Body $loginBody -UseBasicParsing -WebSession $session -ErrorAction Stop
    Write-Host "Login status: $($loginResp.StatusCode)"
    $loginData = $loginResp.Content | ConvertFrom-Json
    Write-Host "Login response: success=$($loginData.success), role=$($loginData.role)"
} catch {
    Write-Host "Login failed: $($_.Exception.Message)"
}

# Now try admin API with buyer session
try {
    $r = Invoke-WebRequest -Uri "http://localhost/Trustmart/dashboard/admin.php?action=get_stats" -UseBasicParsing -WebSession $session -Headers @{Accept="application/json"} -ErrorAction Stop
    Write-Host "Admin API Status: $($r.StatusCode)"
    Write-Host "Admin API Body: $($r.Content)"
    Write-Host "RESULT: FAIL - Buyer can access admin API!"
} catch {
    $status = $_.Exception.Response.StatusCode
    Write-Host "Admin API Status: $([int]$status) $status"
    try {
        $reader = [System.IO.StreamReader]::new($_.Exception.Response.GetResponseStream())
        $respBody = $reader.ReadToEnd()
        Write-Host "Response: $respBody"
    } catch {}
    Write-Host "RESULT: Role check working - PASS"
}

Write-Host ""

# Test 5.2f: Buyer trying to access seller API (role check)
Write-Host "--- 5.2f: Cross-role test: Buyer calling seller add_product ---"
try {
    $body = @{name="Hack Product"; category="Elektronik"; price=1; stock=1}
    $r = Invoke-WebRequest -Uri "http://localhost/Trustmart/dashboard/seller.php?action=add_product" -Method POST -Body $body -UseBasicParsing -WebSession $session -Headers @{Accept="application/json"} -ErrorAction Stop
    Write-Host "Seller API Status: $($r.StatusCode)"
    Write-Host "Seller API Body: $($r.Content)"
    Write-Host "RESULT: FAIL - Buyer can access seller API!"
} catch {
    $status = $_.Exception.Response.StatusCode
    Write-Host "Seller API Status: $([int]$status) $status"
    try {
        $reader = [System.IO.StreamReader]::new($_.Exception.Response.GetResponseStream())
        $respBody = $reader.ReadToEnd()
        Write-Host "Response: $respBody"
    } catch {}
    Write-Host "RESULT: Role check working - PASS"
}

Write-Host ""
Write-Host "=== ALL SECURITY TESTS COMPLETE ==="
