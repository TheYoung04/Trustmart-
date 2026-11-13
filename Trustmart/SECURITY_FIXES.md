# TrustMart Payment Security Fixes - October 2026

## Executive Summary

Four critical security vulnerabilities in the TrustMart payment system have been identified and **FIXED**:

| # | Vulnerability | Severity | Status |
|---|---------------|----------|--------|
| 1 | Database Driver Mismatch (mysqli/PDO) | 🔴 CRITICAL | ✅ FIXED |
| 2 | Payment Callback Authentication Missing | 🔴 CRITICAL | ✅ FIXED |
| 3 | Hardcoded Database Credentials | 🔴 CRITICAL | ✅ FIXED |
| 4 | Hardcoded Localhost URL | 🔴 CRITICAL | ✅ FIXED |

---

## Detailed Fixes

### 1. Database Driver Mismatch (mysqli/PDO) ✅ FIXED

**Problem:** Payment files used mysqli (`$conn->prepare()`, `bind_param()`) but `config/db.php` only initialized PDO (`$pdo`), causing **complete payment system failure**.

**Fix Applied:**
- Converted all payment files to use PDO:
  - `payment/pay.php`
  - `payment/gateway_sim.php`
  - `payment/return.php`
  - `payment/callback.php`
- Replaced mysqli syntax:
  - `$conn->prepare()` → `$pdo->prepare()`
  - `$stmt->bind_param()` → `$stmt->execute([...])`
  - `$stmt->get_result()->fetch_assoc()` → `$stmt->fetch(PDO::FETCH_ASSOC)`
  - `$stmt->insert_id` → `$pdo->lastInsertId()`
  - `$stmt->affected_rows` → `$stmt->rowCount()`

**Impact:** Payment system now functional.

---

### 2. Payment Callback Authentication Missing ✅ FIXED

**Problem:** `payment/callback.php` had **NO authentication** or signature verification. An attacker could mark any transaction as "paid" by sending:

```bash
curl -X POST http://trustmart.com/payment/callback.php \
  -d "refno=TM20260101120001abc123&status_id=1"
```

This is a **CRITICAL fraud vulnerability** allowing free purchases.

**Fix Applied:**

#### 2.1 Added HMAC-SHA256 Signature System

Created signature generation and verification functions in `config/payment.php`:

```php
function generate_payment_signature($payment_ref, $status, $amount) {
    $data = $payment_ref . '|' . $status . '|' . number_format($amount, 2, '.', '');
    return hash_hmac('sha256', $data, PAYMENT_CALLBACK_SECRET);
}

function verify_payment_signature($payment_ref, $status, $amount, $signature) {
    $expected = generate_payment_signature($payment_ref, $status, $amount);
    return hash_equals($expected, $signature);
}
```

#### 2.2 Updated Gateway to Send Signatures

Modified `payment/gateway_sim.php` to:
1. Generate HMAC signature after payment completion
2. Send signed callback to `callback.php` with signature parameter
3. Include payment reference, status, amount, and signature in POST data

#### 2.3 Enforced Signature Verification in Callback

Updated `payment/callback.php` to:
1. **Require** signature parameter for simulation gateway
2. **Verify** signature matches expected value using `verify_payment_signature()`
3. **Reject** callbacks with missing or invalid signatures (HTTP 401/403)
4. Use timing-safe comparison (`hash_equals()`) to prevent timing attacks

**Security Benefits:**
- Attackers cannot forge valid signatures without `PAYMENT_CALLBACK_SECRET`
- All callback data (reference, status, amount) is cryptographically verified
- Protects against payment fraud and unauthorized transaction manipulation

**Impact:** Payment callback now secure and authenticated.

---

### 3. Hardcoded Database Credentials ✅ FIXED

**Problem:** Database password was hardcoded as empty string in `config/db.php`:

```php
define('DB_PASS', ''); // Security risk!
```

**Fix Applied:**

#### 3.1 Created Environment Configuration System

- **`config/env.php`**: Simple .env file loader with `env()` helper function
- **`.env.example`**: Template for environment variables (committed to repo)
- **`.gitignore`**: Ensures `.env` is never committed (protects secrets)
- **`CONFIG.md`**: Comprehensive deployment and security guide

#### 3.2 Updated Database Configuration

Modified `config/db.php` to read from environment:

```php
require_once __DIR__ . '/env.php';

define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_NAME', env('DB_NAME', 'trustmart'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));  // Now from .env!
```

#### 3.3 Deployment Instructions

Administrators must:
1. Copy `.env.example` to `.env`
2. Set secure database credentials in `.env`
3. Never commit `.env` to version control

**Impact:** Database credentials now environment-specific and secure.

---

### 4. Hardcoded Localhost URL ✅ FIXED

**Problem:** `PAYMENT_BASE_URL` was hardcoded to `http://localhost/Trustmart`, preventing deployment to production servers.

**Fix Applied:**

Updated `config/payment.php` to read from environment:

```php
define('PAYMENT_BASE_URL', env('PAYMENT_BASE_URL', 'http://localhost/Trustmart'));
define('PAYMENT_CALLBACK_SECRET', env('PAYMENT_CALLBACK_SECRET', 'default_dev_secret_...'));
```

Added to `.env.example`:

```env
PAYMENT_BASE_URL=http://localhost/Trustmart
PAYMENT_CALLBACK_SECRET=generate_random_secret_here
```

**Deployment Process:**
1. Set `PAYMENT_BASE_URL=https://your-domain.com` in production `.env`
2. Generate secure random secret (64+ characters) for `PAYMENT_CALLBACK_SECRET`
3. Restart web server to load new configuration

**Impact:** Payment system now deployable to any environment.

---

## Files Modified

### Created Files:
- `config/env.php` - Environment variable loader
- `.env.example` - Environment configuration template
- `.gitignore` - Git exclusion rules (protects .env)
- `CONFIG.md` - Configuration and deployment guide
- `SECURITY_FIXES.md` - This document
- `scratch/test_payment_security_fixes.php` - Security verification script
- `scratch/test_payment_integration.php` - Integration test script

### Modified Files:
- `config/db.php` - Now uses environment variables
- `config/payment.php` - Added signature functions, uses environment variables
- `payment/pay.php` - Converted mysqli → PDO
- `payment/gateway_sim.php` - Converted mysqli → PDO, generates signatures
- `payment/return.php` - Converted mysqli → PDO
- `payment/callback.php` - Converted mysqli → PDO, enforces signature verification

---

## Testing & Verification

### Automated Tests Created:

1. **`scratch/test_payment_security_fixes.php`**
   - Verifies environment loader works
   - Checks PDO database connection
   - Confirms signature functions exist
   - Tests signature generation/verification
   - Scans payment files for mysqli usage
   - Validates callback signature enforcement
   - Checks configuration files exist

2. **`scratch/test_payment_integration.php`**
   - End-to-end payment flow test
   - Creates test payment record
   - Generates and verifies signatures
   - Simulates callback with signature
   - Verifies payment/transaction updates
   - Tests unsigned callback rejection
   - Cleans up test data

### Run Tests:

```bash
# Security verification
php Trustmart/scratch/test_payment_security_fixes.php

# Integration test
php Trustmart/scratch/test_payment_integration.php
```

---

## Production Deployment Checklist

Before going live, ensure:

### Database Security:
- [ ] Set strong `DB_PASS` in `.env`
- [ ] Create dedicated MySQL user (not root)
- [ ] Grant only required privileges to database user
- [ ] Verify `.env` file permissions: `chmod 600 .env`

### Payment Security:
- [ ] Generate new 64-character `PAYMENT_CALLBACK_SECRET` in `.env`
- [ ] Update `PAYMENT_BASE_URL` to production domain (HTTPS!)
- [ ] Never use default/development secrets in production

### File Security:
- [ ] Verify `.env` is in `.gitignore`
- [ ] Confirm `.env` is NOT committed to repository
- [ ] Check `.env` is readable only by web server user

### Application Settings:
- [ ] Set `APP_ENV=production` in `.env`
- [ ] Set `APP_DEBUG=false` in `.env`
- [ ] Disable PHP `display_errors` in php.ini
- [ ] Configure proper error logging

### Testing:
- [ ] Run `test_payment_security_fixes.php` (all checks pass)
- [ ] Run `test_payment_integration.php` (all tests pass)
- [ ] Test actual payment flow in browser
- [ ] Verify callback signature rejection works

---

## Security Improvements Summary

| Aspect | Before | After |
|--------|--------|-------|
| **Payment System** | Non-functional (mysqli/PDO mismatch) | ✅ Fully functional with PDO |
| **Callback Auth** | None (anyone can mark orders paid) | ✅ HMAC-SHA256 signature required |
| **Database Password** | Hardcoded empty string | ✅ Environment variable (.env) |
| **Base URL** | Hardcoded localhost | ✅ Environment-specific configuration |
| **Git Security** | No .gitignore | ✅ .env excluded from repo |
| **Documentation** | None | ✅ CONFIG.md with deployment guide |
| **Testing** | Manual only | ✅ Automated security & integration tests |

---

## Additional Recommendations

### High Priority (Should Fix):

1. **Foreign Key Constraints** - Add to database schema for referential integrity
2. **Payment Status Validation** - Enforce `PAYMENT_STATUS_TRANSITIONS` in all flows
3. **Rate Limiting** - Add to password reset endpoints
4. **Error Messages** - Standardize (no stack traces in production)

### Medium Priority (Nice to Have):

5. **Content Security Policy** - Add CSP headers
6. **API Rate Limiting** - Per user/IP throttling
7. **Audit Logging** - Log all payment state changes
8. **Automated Monitoring** - Alert on failed callbacks

---

## Support

For questions or issues:

1. Review `CONFIG.md` for deployment guidance
2. Check test scripts for verification procedures
3. Review `.env.example` for configuration options

---

**Document Version:** 1.0  
**Last Updated:** October 1, 2026  
**Status:** All Critical Vulnerabilities Fixed ✅
