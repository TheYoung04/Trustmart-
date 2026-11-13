# TrustMart Logical Issues & Business Logic Flaws Report
**Analysis Date:** October 1, 2026  
**Scope:** Complete business logic, workflows, state machines, and edge cases  
**Issues Found:** 15 Logical Flaws (5 High Severity, 5 Medium, 5 Low)

---

## 🔴 HIGH SEVERITY ISSUES (Fix Before Production)

### H1. Payment Reconciliation Gap - Money Captured but Order Shows Unpaid
**Severity:** 🔴 CRITICAL  
**Location:** `config/payment.php` lines 232-310 (`apply_payment_gateway_result`)

**Problem:**
When a payment callback succeeds for an already-cancelled or completed order:
- Payment record: `status='completed'` (money captured from buyer)
- Transaction record: `payment_status='unpaid'` (intentionally not updated)
- Return state: `'needs_reconciliation'`

**Impact:**
- Buyer's money is taken but order shows "payment required"
- Admin refund queue gets the item, but buyer dashboard filters it out
- No automatic reconciliation process
- Manual intervention required for EVERY late payment

**Business Scenario:**
1. Buyer creates order, navigates to payment gateway
2. Buyer closes browser (thinks payment failed)
3. Buyer cancels order in your system
4. Gateway processes payment successfully 30 seconds later
5. Callback arrives, captures money, but order is already cancelled
6. Result: Buyer charged for cancelled order, no automatic refund

**Fix:**
```php
// In apply_payment_gateway_result()
if (!in_array($payment['order_status'], ['Menunggu','Disahkan'], true)) {
    // Auto-queue refund instead of just logging
    $refund_stmt = $pdo->prepare("
        UPDATE payments 
        SET refund_status = 'requested',
            refund_requested_at = CURRENT_TIMESTAMP,
            refund_method = 'auto'
        WHERE payment_id = ?
    ");
    $refund_stmt->execute([$payment['payment_id']]);
    
    // Process refund immediately
    require_once __DIR__ . '/payment.php';
    process_payment_refund($pdo, $payment['payment_id'], 
        'Late payment for non-payable order', 'auto');
}
```

---

### H2. Abandoned Payment Superseding Race Condition
**Severity:** 🔴 CRITICAL  
**Location:** `payment/pay.php` lines 128-150

**Problem:**
When a buyer rapidly retries payment (double-click, slow network):
1. Request 1: Creates payment P1 (status='pending')
2. Request 2: Finds P1 as "stale pending", marks it 'failed', creates P2
3. Gateway callback for P1 arrives after P2 exists
4. Callback finds P1 with status='failed', blocks the update
5. **Buyer's money from P1 is captured but never credited**

**Race Window:** 2-10 seconds between payment creation and callback

**Impact:**
- Lost payments that are captured but not credited
- Buyer shows as unpaid but money was taken
- Requires manual reconciliation

**Fix:**
```php
// Don't supersede pending payments that are < 2 minutes old
$stale = $pdo->prepare("
    SELECT payment_id FROM payments
    WHERE transaction_id = ? 
    AND status = 'pending'
    AND created_at < (NOW() - INTERVAL 2 MINUTE)  -- ← Add time check
");
```

---

### H3. Dispute Refund Without Return Enforcement
**Severity:** 🔴 CRITICAL  
**Location:** `dashboard/admin.php` lines 267-342, `config/schema_update_v11.sql`

**Problem:**
When admin resolves dispute in buyer's favor:
1. Sets `settlement_return_required=1` in database
2. Queues refund
3. Refund processes immediately
4. **No system enforces buyer actually returns the goods**

**Impact:**
- Buyer gets full refund + keeps the product
- Seller loses money + goods
- Only the flag is set, no workflow enforces it

**Business Scenario:**
1. Buyer receives laptop, claims "not as described"
2. Admin rules in buyer's favor, refund RM 3,000
3. System flags "return required" but refunds immediately
4. Buyer never returns laptop
5. Seller loses RM 3,000 + laptop

**Fix:**
Add refund hold until return confirmed:
```sql
-- In admin dispute resolution
UPDATE payments
SET refund_status = 'pending_return',  -- New state
    refund_hold_reason = 'Awaiting return shipment'
WHERE payment_id = ?;

-- Add return tracking table
CREATE TABLE return_shipments (
    return_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dispute_id INT UNSIGNED NOT NULL,
    tracking_number VARCHAR(100),
    carrier VARCHAR(50),
    status ENUM('pending','in_transit','received','verified') DEFAULT 'pending',
    shipped_at TIMESTAMP NULL,
    received_at TIMESTAMP NULL
);

-- Only release refund after return received
```

---

### H4. Cash Order Payment Status Undefined
**Severity:** 🔴 HIGH  
**Location:** `product.php` line 145, `dashboard/seller.php` line 328

**Problem:**
Cash orders have:
- `payment_type='cash'`
- `payment_status=NULL` (not 'unpaid', not 'paid', literally NULL)

**Issues:**
1. Seller can confirm cash order without verifying payment received
2. Buyer can mark as completed without paying
3. No audit trail for cash payment status
4. Trust score counts ALL completions equally (paid or not)

**Impact:**
- No enforcement of cash payment
- Buyers can complete orders without paying
- Sellers have no proof of payment

**Fix:**
```php
// In product.php order creation
$payment_status = ($payment_type === 'online') ? 'unpaid' : 'unpaid_cash';

// In seller.php confirmation
if ($new_status === 'Disahkan') {
    if ($txn['payment_type'] === 'cash' && $txn['payment_status'] === 'unpaid_cash') {
        // Require seller to confirm cash received
        $pdo->rollBack();
        die(json_encode([
            'success' => false,
            'message' => 'Sila sahkan pembayaran tunai diterima sebelum mengesahkan pesanan'
        ]));
    }
}

// Add cash payment confirmation button in seller UI
```

---

### H5. Stock Restoration Race on Cancellation
**Severity:** 🔴 HIGH  
**Location:** `dashboard/seller.php` lines 369-385, `dashboard/buyer.php` lines 106-110

**Problem:**
If buyer and seller cancel the same order simultaneously:
1. Buyer request: Locks transaction, restores stock (+5)
2. Seller request: Waits for lock, gets lock, restores stock again (+5)
3. Result: Stock increased by 10 instead of 5

**Why Current Lock Doesn't Prevent This:**
The lock is on `transactions` table (FOR UPDATE), but the status check happens AFTER lock acquisition. If timing is perfect:
- Buyer: status='Menunggu', proceeds with cancel
- Seller: status='Menunggu' (read before buyer's UPDATE commits), proceeds with cancel
- Both restore stock

**Impact:**
- Artificial stock inflation
- Overselling becomes possible later
- Inventory count incorrect

**Fix:**
```php
// Use CASE in UPDATE to make stock restoration atomic and idempotent
$pdo->prepare("
    UPDATE products
    SET stock = CASE 
        WHEN ? THEN stock + ?  -- Only add if order wasn't already cancelled
        ELSE stock 
    END,
    sold_count = GREATEST(sold_count - ?, 0)
    WHERE product_id = ?
")->execute([
    $previous_status !== 'Dibatalkan',  // Only restore if this is first cancel
    $txn['quantity'],
    $txn['quantity'],
    $txn['product_id']
]);
```

---

## 🟡 MEDIUM SEVERITY ISSUES (Fix Post-Launch)

### M1. Buyer Cannot Cancel After Seller Confirms
**Severity:** 🟡 MEDIUM  
**Location:** `dashboard/buyer.php` lines 48-72

**Problem:**
Buyers can only cancel orders with status='Menunggu'. Once seller clicks "confirm" (status='Disahkan'), buyer CANNOT self-cancel even if:
- Payment hasn't been made yet
- Goods haven't been shipped
- Seller just confirmed but took no action

**Impact:**
- Forces buyers into dispute system for simple cancellations
- Unnecessary admin overhead
- Poor user experience

**Business Scenario:**
1. Buyer orders phone case (RM 50)
2. Changes mind 10 minutes later
3. Seller already clicked "confirm" (but hasn't shipped)
4. Buyer clicks cancel → "Must file dispute"
5. Dispute created for simple buyer's remorse

**Fix:**
Allow buyer cancellation of 'Disahkan' orders within timeframe (e.g., 24 hours) or before shipping:
```php
if ($txn['status'] === 'Disahkan') {
    // Allow cancel if less than 24 hours since confirmation
    $confirmed_ago = strtotime('now') - strtotime($txn['confirmed_at']);
    if ($confirmed_ago > 86400) {  // 24 hours
        $pdo->rollBack();
        die(json_encode([
            'success' => false,
            'message' => 'Pesanan telah disahkan lebih 24 jam. Sila hubungi penjual atau failkan aduan.'
        ]));
    }
}
```

---

### M2. Trust Score Manipulation via Shill Accounts
**Severity:** 🟡 MEDIUM  
**Location:** `product.php` lines 90-95, `config/schema_update_v5.sql` lines 220-228

**Problem:**
System prevents self-purchase but doesn't detect:
- Coordinated trading between 2+ accounts
- Same buyer making many quick purchases
- Immediate completions without realistic delivery time
- All 5-star reviews posted within minutes

**Exploit:**
1. Seller creates 3 buyer accounts (friends' emails)
2. Each buyer orders, immediately completes, gives 5 stars
3. After 3 transactions:
   - evidence_units = 3
   - confidence = 57.8%
   - Perfect ratings → score ~45-50/100
4. No genuine marketplace activity, but appears trustworthy

**Impact:**
- Fake reputation building
- Unfair competition against honest sellers
- Buyer trust in system eroded

**Fix:**
Add velocity checks and anomaly detection:
```sql
-- In trust_score procedure, add suspicious pattern detection
DECLARE v_rapid_completions INT DEFAULT 0;
DECLARE v_review_timing_suspicious INT DEFAULT 0;

-- Flag: More than 3 completions in 24 hours
SELECT COUNT(*) INTO v_rapid_completions
FROM transactions
WHERE seller_id = p_seller_id
  AND status = 'Selesai'
  AND completed_at >= (NOW() - INTERVAL 24 HOUR);

-- Flag: Reviews posted within 1 hour of completion
SELECT COUNT(*) INTO v_review_timing_suspicious
FROM reviews r
JOIN transactions t ON t.transaction_id = r.transaction_id
WHERE r.seller_id = p_seller_id
  AND TIMESTAMPDIFF(MINUTE, t.completed_at, r.created_at) < 60;

-- Apply penalty if suspicious
IF v_rapid_completions > 3 OR v_review_timing_suspicious > 5 THEN
    SET v_score = v_score * 0.8;  -- 20% penalty for suspicious activity
END IF;
```

---

### M3. Seller Buying Creates Dual Trust Profiles
**Severity:** 🟡 MEDIUM  
**Location:** `dashboard/buyer.php` lines 8-9, 20-21, `config/schema_update_v5.sql`

**Problem:**
A seller account can shop from other sellers (same UTHM email restriction), which:
- Creates `trust_profiles` with `profile_role='pembeli'` for the seller
- Seller now has TWO trust scores (penjual: 85/100, pembeli: 0/100)
- UI inconsistency: Which score to show where?

**Display Confusion:**
- Product page: Shows seller's "penjual" trust score (correct)
- Chat/messaging: Shows which score?
- Transaction history: Buyer sees seller's buyer score or seller score?

**Impact:**
- User confusion
- Misleading trust displays
- UI bugs showing wrong trust score

**Fix:**
Always show role-appropriate trust score:
```php
// In trust_display helper
function get_user_trust_for_context($pdo, $user_id, $context_role) {
    // When viewing as seller, show penjual profile
    // When viewing as buyer, show pembeli profile
    $stmt = $pdo->prepare("
        SELECT * FROM trust_profiles
        WHERE user_id = ? AND profile_role = ?
        LIMIT 1
    ");
    $stmt->execute([$user_id, $context_role]);
    return $stmt->fetch() ?: ['score' => 0, 'level' => 'Baharu'];
}

// Usage: 
$seller_trust = get_user_trust_for_context($pdo, $seller_id, 'penjual');
$buyer_trust = get_user_trust_for_context($pdo, $buyer_id, 'pembeli');
```

---

### M4. Dispute Time Limits Missing
**Severity:** 🟡 MEDIUM  
**Location:** `dashboard/buyer.php` lines 546-568

**Problem:**
No time limit on filing disputes. Buyers can:
1. Receive goods, mark as 'Selesai'
2. Wait 6 months
3. File dispute claiming non-delivery
4. No shipping records/tracking to prove delivery

**Impact:**
- Stale disputes that are impossible to resolve
- Sellers have no protection after reasonable time
- Evidence (photos, messages) may be deleted/lost

**Fix:**
```php
// In submit_dispute action
$order_age_days = (strtotime('now') - strtotime($txn['completed_at'])) / 86400;
$dispute_deadline = 30;  // 30 days after completion

if ($txn['status'] === 'Selesai' && $order_age_days > $dispute_deadline) {
    $pdo->rollBack();
    die(json_encode([
        'success' => false,
        'message' => "Aduan tidak boleh difailkan selepas {$dispute_deadline} hari dari penghantaran selesai."
    ]));
}
```

---

### M5. Partial Refunds Not Supported
**Severity:** 🟡 MEDIUM  
**Location:** `dashboard/admin.php` line 296, `config/payment.php` line 313

**Problem:**
Admin can specify `settlement_refund_amount` (e.g., RM 50 of RM 100 order), but `process_payment_refund()` always refunds the FULL `payment.amount`.

**Impact:**
- Cannot refund partial amounts for:
  - Partial defects (1 of 2 items damaged)
  - Service discounts
  - Shipping-only refunds
- Forces all-or-nothing resolution

**Fix:**
```php
// Modify process_payment_refund to accept custom amount
function process_payment_refund($pdo, $payment_id, $refund_reason = 'Order cancelled', 
                                $refund_method = 'auto', $refund_amount = null) {
    // ...
    $refund_amount = $refund_amount ?? $payment['amount'];  // Default to full
    
    // Validate
    if ($refund_amount > $payment['amount']) {
        return ['success' => false, 'error' => 'Refund amount exceeds payment'];
    }
    
    $refund_result = $toyyibpay->refundPayment([
        'billCode' => $bill_code,
        'payment_reference' => $payment['payment_reference'],
        'refund_amount' => $refund_amount,  // Use custom amount
        'refund_reason' => $refund_reason
    ]);
}
```

---

## 🟢 LOW SEVERITY ISSUES (Future Enhancement)

### L1. Order Completion Without Delivery Proof
**Severity:** 🟢 LOW  
**Location:** `dashboard/buyer.php` lines 172-203

**Problem:**
Buyers can mark ANY order with status='Disahkan' as completed, regardless of:
- Whether goods were delivered
- Time since confirmation
- Shipping/tracking status

**Exploit:**
Malicious buyer could immediately mark as received, then file dispute claiming non-delivery.

**Fix:**
Add optional delivery confirmation:
```php
// Add delivery_confirmed_at column
ALTER TABLE transactions 
ADD COLUMN delivery_confirmed_at TIMESTAMP NULL AFTER confirmed_at;

// Only allow completion after reasonable delivery time or explicit confirmation
if ($txn['status'] === 'Disahkan') {
    $hours_since_confirm = (time() - strtotime($txn['confirmed_at'])) / 3600;
    if ($hours_since_confirm < 24 && !$txn['delivery_confirmed_at']) {
        die(json_encode([
            'message' => 'Sila tunggu 24 jam atau dapatkan kod pengesahan dari penjual'
        ]));
    }
}
```

---

### L2. Product Deletion Hides Transaction History
**Severity:** 🟢 LOW  
**Location:** `dashboard/seller.php` lines 249-278

**Problem:**
Seller can delete product (status='Dipadam') after orders complete, hiding it from catalog browse. Other buyers can't see:
- Past reviews for this product
- Transaction history
- Evidence of previous sales

**Impact:**
- Sellers can hide bad products after getting negative reviews
- Buyer research is incomplete

**Fix:**
```php
// Don't allow deletion if product has reviews
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM reviews r
    JOIN transactions t ON t.transaction_id = r.transaction_id
    WHERE t.product_id = ?
");
$stmt->execute([$product_id]);
if ($stmt->fetchColumn() > 0) {
    die(json_encode([
        'message' => 'Produk dengan ulasan tidak boleh dipadam. Sila set sebagai Tidak Aktif.'
    ]));
}
```

---

### L3. Trust Score Recalculation on Every INSERT
**Severity:** 🟢 LOW  
**Location:** `config/schema_update_v5.sql` lines 473-507

**Problem:**
Trust score recalculates on transaction INSERT, even though new order has no outcome yet (still 'Menunggu').

**Impact:**
- Unnecessary database load
- Temporary score inaccuracy
- Wasted CPU on every order

**Fix:**
```sql
-- Modify trigger to only recalc on terminal states
CREATE TRIGGER after_transaction_update
AFTER UPDATE ON transactions
FOR EACH ROW
BEGIN
    IF OLD.status <> NEW.status 
       AND NEW.status IN ('Selesai', 'Dibatalkan') THEN
        -- Only recalc when order reaches terminal state
        CALL recalculate_trust_score(NEW.seller_id);
        CALL recalculate_buyer_trust_score(NEW.buyer_id);
    END IF;
END
```

---

### L4. Dispute Stock Inconsistency
**Severity:** 🟢 LOW  
**Location:** `dashboard/admin.php` line 328

**Problem:**
- Regular cancellations restore stock
- Dispute-driven cancellations DON'T restore stock
- Comment says: "goods may already be delivered"

**Issue:**
Two cancellations for same reason (goods never delivered) have different stock outcomes.

**Fix:**
Add admin choice for stock restoration in settlement:
```php
$restore_stock = (int)($_POST['settle_restore_stock'] ?? 0);

if ($fulfillment === 'Dibatalkan' && $restore_stock) {
    $pdo->prepare("
        UPDATE products
        SET stock = stock + ?,
            sold_count = GREATEST(sold_count - ?, 0)
        WHERE product_id = ?
    ")->execute([$quantity, $quantity, $product_id]);
}
```

---

### L5. Trust Score Confidence Formula Doesn't Distinguish Repeat vs Unique Buyers
**Severity:** 🟢 LOW  
**Location:** `config/schema_update_v5.sql` lines 220-228

**Problem:**
Evidence calculation: `SUM(LEAST(e.event_count, 3))` caps each buyer at 3 transactions.

**Scenario:**
- Seller A: 10 orders from buyer X (counts as 3), + 1 each from Y,Z,W = 6 evidence units
- Seller B: 1 order each from A,B,C,D,E,F = 6 evidence units

Same evidence_units but very different business patterns (depth vs breadth).

**Impact:**
Doesn't reward having many unique customers vs few repeat customers.

**Fix:**
Weight unique counterparties more:
```sql
SET v_evidence_units = 
    (v_counterparties * 0.5) +  -- 0.5 per unique buyer
    (SELECT SUM(LEAST(event_count, 3)) * 0.5  -- 0.5 per transaction (max 3)
     FROM transaction_evidence);
```

---

## 📊 Issue Summary

| Priority | Count | Description |
|----------|-------|-------------|
| 🔴 High | 5 | Critical business logic flaws causing financial loss or security issues |
| 🟡 Medium | 5 | Significant UX problems or manipulation vectors |
| 🟢 Low | 5 | Minor inconsistencies or future enhancements |
| **Total** | **15** | **Complete logical analysis** |

---

## 🎯 Recommended Fix Priority

### Phase 1 (Before Production - Week 1):
1. **H1** - Payment reconciliation gap (auto-refund late payments)
2. **H3** - Dispute refund enforcement (hold refund until return)
3. **H4** - Cash payment tracking (add unpaid_cash status)

### Phase 2 (Before Production - Week 2):
4. **H2** - Payment superseding race (time-based stale check)
5. **H5** - Stock restoration race (atomic CASE statement)

### Phase 3 (Post-Launch - Month 1):
6. **M1** - Buyer cancel after confirm (24-hour window)
7. **M4** - Dispute time limits (30-day deadline)
8. **M5** - Partial refunds (custom refund amount)

### Phase 4 (Post-Launch - Month 2):
9. **M2** - Trust score manipulation detection (velocity checks)
10. **M3** - Dual trust profile display (role-based queries)

### Phase 5 (Future Enhancements):
11-15. Low severity issues (polish and optimization)

---

## 🔍 Testing Recommendations

For each issue, add these test scenarios:

**H1 - Payment Reconciliation:**
```
Test: Late payment on cancelled order
1. Create order
2. Cancel order
3. Simulate successful payment callback
4. Verify: Auto-refund initiated, no manual reconciliation needed
```

**H3 - Dispute Refund:**
```
Test: Buyer gets refund without return
1. Create completed order
2. File dispute, admin rules for buyer with return required
3. Verify: Refund held until return_shipment.status='verified'
```

**M2 - Shill Accounts:**
```
Test: Rapid completions trigger penalty
1. Create seller account
2. Create 5 orders that complete within 1 hour each
3. Verify: Trust score shows suspicious activity penalty
```

---

**Analyzed By:** Kiro AI Agent (with context-gatherer)  
**Analysis Date:** October 1, 2026  
**Analysis Method:** Deep workflow analysis + sub-agent investigation  
**Status:** ⚠️ 5 HIGH SEVERITY ISSUES FOUND

---

## 📌 Key Takeaway

Your system's **core workflows are solid**, but there are **5 critical edge cases** that could cause financial loss or poor user experience in production. The good news: all are fixable with targeted code changes (no architecture redesign needed).

The issues are mostly **timing problems** (races, late callbacks) and **incomplete workflows** (refunds without returns, missing audit trails). Once these 5 high-priority issues are fixed, the system will be production-ready.

