Write-Host "=== DETAILED RESPONSE BODIES ==="
Write-Host ""

# Use curl for reliable response body capture
Write-Host "--- 5.2a: Seller API no auth (response body) ---"
$r = curl.exe -s -X POST "http://localhost/Trustmart/dashboard/seller.php?action=add_product" -H "Accept: application/json" -d "name=HackProduct&category=Elektronik&price=1&stock=1"
Write-Host $r

Write-Host ""
Write-Host "--- 5.2b: Admin API no auth (response body) ---"
$r = curl.exe -s -X POST "http://localhost/Trustmart/dashboard/admin.php?action=verify_seller" -H "Accept: application/json" -d "user_id=2&action_type=approve"
Write-Host $r

Write-Host ""
Write-Host "--- 5.2c: Buyer API no auth (response body) ---"
$r = curl.exe -s -X POST "http://localhost/Trustmart/dashboard/buyer.php?action=cancel_order" -H "Accept: application/json" -d "transaction_id=1"
Write-Host $r

Write-Host ""
Write-Host "--- 5.2e: Cross-role (login as buyer, try admin) ---"
# Login as buyer first
$loginResp = curl.exe -s -c "scratch\cookies.txt" -X POST "http://localhost/Trustmart/auth/login.php" -d "email=aishah@student.uthm.edu.my&password=password123"
Write-Host "Login: $loginResp"

# Try admin API with buyer cookie
$adminResp = curl.exe -s -b "scratch\cookies.txt" "http://localhost/Trustmart/dashboard/admin.php?action=get_stats" -H "Accept: application/json"
Write-Host "Admin API as buyer: $adminResp"

Write-Host ""
Write-Host "--- 5.2f: Cross-role (buyer trying seller add_product) ---"
$sellerResp = curl.exe -s -b "scratch\cookies.txt" -X POST "http://localhost/Trustmart/dashboard/seller.php?action=add_product" -H "Accept: application/json" -d "name=HackProduct&category=Elektronik&price=1&stock=1"
Write-Host "Seller API as buyer: $sellerResp"

# Cleanup
Remove-Item "scratch\cookies.txt" -ErrorAction SilentlyContinue
