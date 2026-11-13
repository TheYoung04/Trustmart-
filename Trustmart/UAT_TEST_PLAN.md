# TrustMart Comprehensive UAT Test Plan
**Version:** 1.0  
**Date:** October 1, 2026  
**Tester:** System Validation  
**Environment:** Development/Staging

---

## Test Objectives

1. Verify all user journeys work end-to-end
2. Confirm security measures are effective
3. Validate payment and refund functionality
4. Test trust score calculations
5. Ensure proper authorization for all roles
6. Identify UX issues and bugs
7. Test error handling and edge cases

---

## Test Roles

### 1. Pembeli (Buyer) Role
**Test Users:**
- New buyer (registration flow)
- Existing buyer (login flow)
- Seller account buying products (dual role)

### 2. Penjual (Seller) Role
**Test Users:**
- Unverified seller (pending verification)
- Verified seller (full access)
- Seller with active orders

### 3. Pentadbir (Admin) Role
**Test Users:**
- Admin account with full privileges

---

## Test Scenarios by Role

## 🛒 PEMBELI (BUYER) TEST SCENARIOS

### P1. Registration & Authentication
- [ ] P1.1: Register new buyer account
  - Valid matric number format
  - Strong password enforcement
  - Email validation
  - CSRF token present
  - Success redirect to dashboard
- [ ] P1.2: Login with valid credentials
  - Session created properly
  - Trust score displayed
  - Redirect to buyer dashboard
- [ ] P1.3: Login with invalid credentials
  - Error message displayed
  - Rate limiting after 5 attempts
  - Account not locked permanently
- [ ] P1.4: Forgot password flow
  - Email sent (check logs)
  - Reset token generated
  - New password accepted
  - Old password no longer works
- [ ] P1.5: Session security
  - Session regenerated after login
  - Logout clears session
  - Cannot access after logout

### P2. Product Browsing
- [ ] P2.1: View product marketplace
  - All active products shown
  - Search functionality works
  - Category filter works
  - Pagination works
  - Trust score badges visible
- [ ] P2.2: View single product
  - Product details complete
  - Seller info displayed
  - Stock shown correctly
  - Price formatted properly
  - Cannot see own products to buy
- [ ] P2.3: Cannot buy own products
  - Error message if seller = buyer
  - Transaction blocked

### P3. Cash Payment Orders
- [ ] P3.1: Place cash order
  - Select payment method: Tunai
  - Quantity validation (>0, <=stock)
  - Order created with status 'Menunggu'
  - Stock decremented immediately
  - Redirect to buyer dashboard
- [ ] P3.2: Cancel cash order (Menunggu)
  - Order status → Dibatalkan
  - Stock restored
  - cancelled_by = 'buyer'
  - Cannot cancel after seller confirms
- [ ] P3.3: Confirm received (Disahkan → Selesai)
  - Only works if status = Disahkan
  - completed_by = 'buyer'
  - Cannot cancel after completion

### P4. Online Payment Orders
- [ ] P4.1: Place online order
  - Select payment type: Online
  - Order created with payment_status: unpaid
  - Redirect to payment selection page
- [ ] P4.2: Payment method selection
  - FPX option available
  - E-Wallet option available
  - ToyyibPay option (if enabled)
  - CSRF token present
- [ ] P4.3: Simulate FPX payment
  - Redirect to gateway simulator
  - Select bank
  - 3-second delay simulation
  - 95% success rate
  - Payment callback triggered
  - Return page shows status
- [ ] P4.4: Payment success flow
  - Payment status → paid
  - Transaction payment_status → paid
  - paid_at timestamp recorded
  - Receipt link available
  - Can view receipt
- [ ] P4.5: Payment failure flow
  - Payment status → failed
  - Transaction remains Menunggu
  - Can retry payment
  - "Bayar Sekarang" button visible
- [ ] P4.6: Cancel paid online order
  - Refund triggered automatically
  - payment.refund_status → completed (simulation)
  - transaction.payment_status → refunded
  - Message confirms refund
  - Stock restored

### P5. Reviews & Ratings
- [ ] P5.1: Submit review (after Selesai)
  - Rating 1-5 stars required
  - Comment optional (max 2000 chars)
  - Cannot review twice
  - Trust score recalculated
- [ ] P5.2: Cannot review before completion
  - Error if status != Selesai
  - Button not shown in UI
- [ ] P5.3: Review appears in history
  - Visible in order list
  - Stars displayed correctly

### P6. Disputes
- [ ] P6.1: File dispute
  - Title required (max 200 chars)
  - Description required (max 5000 chars)
  - Severity: Rendah/Sederhana/Mendesak
  - Dispute created successfully
- [ ] P6.2: View dispute status
  - Shows current status
  - Admin resolution visible
  - Cannot file duplicate

### P7. Trust Score & Profile
- [ ] P7.1: View buyer trust score
  - Score displayed in header
  - Buyer-specific score (not seller score)
  - Updates after transactions
- [ ] P7.2: Profile page
  - View personal info
  - Cannot edit verified fields
  - Password change works

### P8. Dashboard Features
- [ ] P8.1: Order list with filters
  - All statuses: Menunggu/Disahkan/Selesai/Dibatalkan
  - Pagination works
  - Payment status badges shown
  - Action buttons context-aware
- [ ] P8.2: Product browsing from dashboard
  - Search products
  - Filter by category
  - Add to cart → order flow

---

## 🏪 PENJUAL (SELLER) TEST SCENARIOS

### S1. Registration & Verification
- [ ] S1.1: Register as seller
  - Role selection: Penjual
  - verification_status: Menunggu
  - is_verified: false
  - Cannot add products yet
- [ ] S1.2: Wait for PTU verification
  - Dashboard shows "Menunggu Pengesahan PTU"
  - Limited functionality
- [ ] S1.3: After admin approval
  - is_verified: true
  - verification_status: Disahkan
  - Can add products
  - Can receive orders

### S2. Product Management
- [ ] S2.1: Add new product
  - Name required (max 200 chars)
  - Category validation
  - Description (max 5000 chars)
  - Price validation (>0, <=99999999.99)
  - Stock validation (>=0)
  - Image upload (JPG/PNG/WEBP, max 2MB)
  - MIME type verified
  - Product created with status: Aktif
- [ ] S2.2: Upload product image
  - File size checked (<2MB)
  - Dimensions validated (<24MP)
  - MIME validated (not just extension)
  - Random filename generated
  - Cannot upload PHP files
- [ ] S2.3: Edit existing product
  - All fields editable
  - Image optional on update
  - Changes saved
- [ ] S2.4: Toggle product status
  - Aktif ↔ Tidak Aktif
  - Inactive products not buyable
- [ ] S2.5: Soft delete product
  - Status → Dipadam
  - Not shown in marketplace
  - Cannot delete if active orders exist

### S3. Order Management
- [ ] S3.1: Receive new order
  - Notification/count updated
  - Order shows in pending list
  - Buyer details visible
  - Payment type shown (cash/online)
- [ ] S3.2: Confirm cash order (Menunggu → Disahkan)
  - Status transition allowed
  - confirmed_at timestamp
  - Trust score updated
- [ ] S3.3: Cannot confirm unpaid online order
  - Error if payment_status != paid
  - Message explains payment required
  - Order blocked in UI
- [ ] S3.4: Confirm paid online order
  - Allowed after payment_status = paid
  - Status → Disahkan
  - Trust score updated
- [ ] S3.5: Cancel order (seller initiated)
  - Only from Menunggu/Disahkan
  - Stock restored
  - cancelled_by = 'seller'
  - If paid online → refund triggered
  - Trust score penalty applied
- [ ] S3.6: Wait for buyer confirmation
  - After Disahkan, wait for buyer
  - Auto-complete after timeout (future feature)

### S4. Sales Analytics
- [ ] S4.1: Dashboard statistics
  - Total sales count
  - Revenue (completed orders only)
  - Pending orders count
  - Products count
- [ ] S4.2: Order history
  - Filter by status
  - Pagination
  - Export capability
- [ ] S4.3: Product performance
  - Sold count accurate
  - Stock levels correct

### S5. Seller as Buyer
- [ ] S5.1: Browse other sellers' products
  - Can see all products except own
  - Can place orders
  - Separate buyer trust score used
- [ ] S5.2: Cannot buy own products
  - Error message
  - Self-purchase blocked
  - Trust score manipulation prevented

### S6. Trust Score (Seller)
- [ ] S6.1: Seller trust score calculation
  - Based on reviews received
  - Completion rate
  - Cancellation penalty
  - Response time
  - Dispute resolution
- [ ] S6.2: Trust score components
  - Rating (35 points max)
  - Fulfillment (20 points)
  - Disputes (15 points)
  - Response time (15 points)
  - PTU verification (15 points)
- [ ] S6.3: Trust level badges
  - Baharu / Awal / Membina / Sederhana / Dipercayai / Sangat Dipercayai

---

## 🔧 PENTADBIR (ADMIN) TEST SCENARIOS

### A1. Authentication
- [ ] A1.1: Admin login
  - Role check enforced
  - Redirect to admin dashboard
  - Cannot access buyer/seller dashboards

### A2. Seller Verification
- [ ] A2.1: View pending sellers
  - List shows verification_status: Menunggu
  - Seller details visible
  - Product/order counts shown
- [ ] A2.2: Approve seller
  - is_verified → true
  - verification_status → Disahkan
  - Trust score identity bonus applied
  - Seller notified (if email implemented)
- [ ] A2.3: Reject seller
  - verification_status → Ditolak
  - Reason recorded
  - Cannot add products
- [ ] A2.4: View all sellers
  - Filter: pending/verified/rejected/all
  - Pagination works
  - Search functionality

### A3. Dispute Resolution
- [ ] A3.1: View all disputes
  - Filter by status
  - Severity shown
  - Buyer/seller details
- [ ] A3.2: Resolve dispute (Favor Buyer)
  - Resolution recorded
  - Outcome applied
  - Trust scores updated
  - Status → Selesai
- [ ] A3.3: Resolve dispute (Favor Seller)
  - Resolution recorded
  - No penalties to seller
  - Status → Selesai
- [ ] A3.4: Dismiss dispute
  - No action taken
  - Status → Selesai

### A4. User Management
- [ ] A4.1: View all users
  - Pembeli/Penjual/Pentadbir
  - Search by name/matric/email
  - Pagination
- [ ] A4.2: Suspend user
  - is_active → false
  - User cannot login
  - Active sessions invalidated
- [ ] A4.3: Reactivate user
  - is_active → true
  - User can login again

### A5. Transaction Management
- [ ] A5.1: View all transactions
  - Filter by status/payment type
  - Date range filter
  - Search by buyer/seller/product
  - Pagination
- [ ] A5.2: Transaction summary stats
  - Total count
  - Total amount
  - Completed amount
  - Online paid amount
- [ ] A5.3: Export transactions CSV
  - All filtered data exported
  - Proper CSV formatting
  - Opens in Excel/Sheets

### A6. Refund Management (NEW)
- [ ] A6.1: View refund queue
  - Filter: requested/processing/completed/failed
  - Payment details shown
  - Buyer/seller info
  - Refund error messages visible
- [ ] A6.2: Retry failed refund
  - Calls process_payment_refund()
  - Attempt counter incremented
  - Success/failure shown
- [ ] A6.3: Mark refund completed manually
  - After manual bank transfer
  - Notes field for reference
  - refund_status → completed
  - payment_status → refunded
- [ ] A6.4: Refund summary stats
  - Failed count
  - Processing count
  - Completed count
  - Total amounts

### A7. Analytics & Reports
- [ ] A7.1: Platform statistics
  - Total users by role
  - Active products
  - Total transactions
  - Revenue metrics
- [ ] A7.2: Trust score distribution
  - Average scores
  - Level distribution
  - Trend analysis
- [ ] A7.3: Payment method breakdown
  - Cash vs Online
  - Success rates
  - Refund rates

---

## 🔒 SECURITY TEST SCENARIOS

### SEC1. Authentication Security
- [ ] SEC1.1: Session fixation
  - Session ID changes after login
  - Old session invalid
- [ ] SEC1.2: Session hijacking prevention
  - HttpOnly cookies
  - SameSite=Strict
  - Secure flag (on HTTPS)
- [ ] SEC1.3: Brute force protection
  - Rate limiting after 5 failed attempts
  - Cooldown period
  - No account lockout (just delay)
- [ ] SEC1.4: Password security
  - Bcrypt with cost 12
  - Minimum length enforced
  - Password rehashing on upgrade
- [ ] SEC1.5: Password change security
  - Session regenerated after change
  - Old passwords invalid

### SEC2. Authorization Tests
- [ ] SEC2.1: Role-based access control
  - Buyer cannot access seller dashboard
  - Seller cannot access admin panel
  - Admin cannot access user dashboards directly
- [ ] SEC2.2: Resource ownership
  - Cannot edit other user's products
  - Cannot cancel other user's orders
  - Cannot view other user's payments
- [ ] SEC2.3: Admin privilege escalation
  - Non-admin cannot call admin APIs
  - Role check on server side
  - Not just UI hiding

### SEC3. CSRF Protection
- [ ] SEC3.1: CSRF tokens on forms
  - Token present in meta tag
  - Token in all POST forms
  - Token validated server-side
- [ ] SEC3.2: CSRF in AJAX
  - Auto-patched fetch()
  - Token in headers
  - Validation works
- [ ] SEC3.3: CSRF token invalidation
  - Tokens tied to session
  - Old tokens rejected

### SEC4. SQL Injection Tests
- [ ] SEC4.1: Search injection attempts
  - `' OR '1'='1` in search
  - Prepared statements used
  - No SQL errors leaked
- [ ] SEC4.2: ID parameter injection
  - `?id=1 OR 1=1` blocked
  - Type casting enforced
  - Parameterized queries
- [ ] SEC4.3: Complex injection
  - UNION attacks blocked
  - Stacked queries blocked
  - Time-based blind SQL blocked

### SEC5. XSS Tests
- [ ] SEC5.1: Stored XSS attempts
  - `<script>alert(1)</script>` in product name
  - htmlspecialchars() applied
  - Script not executed
- [ ] SEC5.2: Reflected XSS
  - XSS in URL parameters
  - Output escaped
  - No script execution
- [ ] SEC5.3: DOM-based XSS
  - JavaScript innerHTML checks
  - User input sanitized

### SEC6. File Upload Security
- [ ] SEC6.1: MIME type validation
  - finfo_file() used (not extension)
  - PHP files rejected
  - Shell scripts rejected
- [ ] SEC6.2: File size validation
  - Max 2MB enforced
  - Decompression bomb prevented
- [ ] SEC6.3: Directory traversal
  - `../../` in filename blocked
  - Random filename generated
  - Upload path not user-controlled
- [ ] SEC6.4: Web server config
  - PHP execution disabled in uploads/
  - Direct access returns image (not executed)

### SEC7. Payment Security
- [ ] SEC7.1: Payment callback signature
  - HMAC SHA-256 required
  - Tampering detected
  - Invalid signatures rejected
- [ ] SEC7.2: Payment amount verification
  - Amount matches product × quantity
  - Cannot manipulate payment amount
  - Price checked at payment time
- [ ] SEC7.3: Race condition prevention
  - FOR UPDATE locks used
  - Idempotent operations
  - No double-processing
- [ ] SEC7.4: Refund authorization
  - Only owner can trigger refund
  - Cannot refund twice
  - Amount verified

---

## 🐛 EDGE CASE & ERROR HANDLING TESTS

### EDGE1. Concurrent Operations
- [ ] EDGE1.1: Last item in stock
  - Two users buy simultaneously
  - Only one succeeds
  - Other gets "stock changed" error
  - No overselling
- [ ] EDGE1.2: Simultaneous payment callbacks
  - Two callbacks for same payment
  - Only one processes
  - Second gets "already processed"
  - No double-credit
- [ ] EDGE1.3: Concurrent cancellations
  - Buyer and seller cancel same order
  - One succeeds
  - Other gets proper error
  - Stock restored once only

### EDGE2. Invalid Inputs
- [ ] EDGE2.1: Negative quantities
  - Rejected at validation
  - Clear error message
- [ ] EDGE2.2: Negative prices
  - Rejected at validation
  - Cannot create product
- [ ] EDGE2.3: Zero stock orders
  - "Out of stock" message
  - Order blocked
- [ ] EDGE2.4: SQL special characters
  - Handled properly in all fields
  - No errors or injection
- [ ] EDGE2.5: Unicode/emoji in text
  - UTF-8 handled correctly
  - Display works
  - Database stores correctly

### EDGE3. Boundary Conditions
- [ ] EDGE3.1: Maximum price (99,999,999.99)
  - Accepted and displayed correctly
  - Calculations don't overflow
- [ ] EDGE3.2: Maximum text length
  - 200 char product name
  - 5000 char description
  - 2000 char review
  - Truncation or rejection
- [ ] EDGE3.3: Empty optional fields
  - NULL values handled
  - No crashes
  - Display works
- [ ] EDGE3.4: Pagination edge cases
  - Page 0, negative page
  - Page beyond total
  - Per_page = 0, negative, huge

### EDGE4. Network & API Failures
- [ ] EDGE4.1: Payment gateway timeout
  - User not charged
  - Order remains pending
  - Can retry
- [ ] EDGE4.2: Refund API failure
  - Error logged
  - Order still cancels
  - Admin can retry manually
- [ ] EDGE4.3: Database connection loss
  - Graceful error page
  - No sensitive info leaked
  - Transaction rollback works
- [ ] EDGE4.4: Email sending failure
  - User flow not blocked
  - Logged for admin review
  - Retry mechanism (if implemented)

### EDGE5. Missing/Corrupted Data
- [ ] EDGE5.1: Product deleted mid-order
  - Foreign key constraint
  - Proper error handling
- [ ] EDGE5.2: User deleted mid-transaction
  - Foreign key constraint
  - Transaction preserved
- [ ] EDGE5.3: Missing payment record
  - Handled gracefully
  - No crash
  - Clear error message
- [ ] EDGE5.4: Corrupted session data
  - Re-login required
  - Graceful degradation

### EDGE6. State Transitions
- [ ] EDGE6.1: Invalid status changes
  - Menunggu → Selesai (skip Disahkan) blocked
  - Selesai → Menunggu blocked
  - Proper error messages
- [ ] EDGE6.2: Payment status transitions
  - unpaid → paid ✓
  - paid → unpaid ✗
  - refunded → paid ✗
- [ ] EDGE6.3: Refund status transitions
  - none → requested ✓
  - completed → failed ✗
  - Proper state machine

---

## 📊 PERFORMANCE TESTS

### PERF1. Response Times
- [ ] PERF1.1: Page load times
  - Dashboard < 2 seconds
  - Product list < 1 second
  - Search results < 1 second
- [ ] PERF1.2: Database queries
  - N+1 queries avoided
  - Proper indexes used
  - Query time < 100ms
- [ ] PERF1.3: Image loading
  - Optimized sizes
  - Lazy loading (if implemented)
  - No blocking

### PERF2. Scalability
- [ ] PERF2.1: Large dataset handling
  - 1000+ products
  - 10,000+ transactions
  - Pagination works
  - No memory issues
- [ ] PERF2.2: Trust score calculation
  - Completes in < 500ms
  - Doesn't block UI
  - Indexes used effectively

---

## 📱 UI/UX TESTS

### UX1. Responsive Design
- [ ] UX1.1: Mobile view
  - Navigation works
  - Forms usable
  - Tables scrollable
- [ ] UX1.2: Tablet view
  - Layout adapts
  - Touch targets sized properly
- [ ] UX1.3: Desktop view
  - Full features available
  - Optimal layout

### UX2. Accessibility
- [ ] UX2.1: Keyboard navigation
  - Tab order logical
  - Skip links present
  - Focus indicators
- [ ] UX2.2: Screen reader support
  - ARIA labels present
  - Alt text on images
  - Semantic HTML
- [ ] UX2.3: Color contrast
  - WCAG AA compliant
  - Text readable

### UX3. Error Messages
- [ ] UX3.1: User-friendly messages
  - In Malay (Bahasa Melayu)
  - Clear and actionable
  - No technical jargon
- [ ] UX3.2: Field validation feedback
  - Inline errors
  - Success indicators
  - Helpful hints

---

## ✅ TEST EXECUTION CHECKLIST

### Prerequisites
- [ ] Database migrated to latest schema (v7)
- [ ] Test users created (buyer, seller, admin)
- [ ] Sample products added
- [ ] Payment gateway configured
- [ ] .env file properly configured

### Environment Setup
- [ ] Development server running
- [ ] Database accessible
- [ ] Error logging enabled
- [ ] Browser DevTools ready

### Test Data Requirements
- [ ] 3 buyer accounts
- [ ] 3 seller accounts (1 unverified, 2 verified)
- [ ] 1 admin account
- [ ] 10+ sample products
- [ ] Various transaction states

---

## 📝 BUG REPORTING TEMPLATE

```
BUG ID: BUG-YYYY-MM-DD-XXX
Title: [Clear, concise description]
Severity: Critical / High / Medium / Low
Role Affected: Pembeli / Penjual / Pentadbir / All
Module: Authentication / Products / Orders / Payments / etc.

Steps to Reproduce:
1. 
2. 
3. 

Expected Result:


Actual Result:


Screenshots/Logs:


Additional Notes:

```

---

## 🎯 SUCCESS CRITERIA

### Must Pass (Critical)
- All authentication flows work
- No SQL injection vulnerabilities
- No XSS vulnerabilities
- Payment and refund work correctly
- Authorization checks enforced
- CSRF protection active
- No data loss in transactions

### Should Pass (High Priority)
- All user journeys complete successfully
- Trust scores calculate correctly
- Error messages are user-friendly
- Performance acceptable
- No major UX issues

### Nice to Have (Medium Priority)
- All edge cases handled
- Accessibility fully compliant
- Mobile-responsive
- Analytics accurate

---

**Test Plan Status:** Ready for Execution  
**Estimated Time:** 4-6 hours for complete UAT  
**Last Updated:** October 1, 2026
