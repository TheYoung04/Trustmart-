# TrustMart Configuration Guide

## Initial Setup

### 1. Environment Configuration

Copy the example environment file and customize it:

```bash
cp .env.example .env
```

Then edit `.env` with your actual values:

```env
# Database Configuration
DB_HOST=localhost
DB_NAME=trustmart
DB_USER=root
DB_PASS=your_secure_password_here

# Payment Configuration
PAYMENT_BASE_URL=http://localhost/Trustmart
PAYMENT_CALLBACK_SECRET=generate_random_secret_here_min_32_chars
```

### 2. Generate Secure Payment Secret

For production, generate a secure random secret:

**On Linux/Mac:**
```bash
openssl rand -hex 32
```

**On Windows PowerShell:**
```powershell
-join ((48..57) + (65..90) + (97..122) | Get-Random -Count 64 | % {[char]$_})
```

**Online:**
Visit https://www.random.org/strings/ and generate a 64-character alphanumeric string.

Copy the generated secret to your `.env` file:
```env
PAYMENT_CALLBACK_SECRET=your_generated_secret_here
```

### 3. Security Checklist

- [ ] `.env` file created and configured
- [ ] `PAYMENT_CALLBACK_SECRET` set to random 32+ character string
- [ ] `.env` is listed in `.gitignore` (already done)
- [ ] Database password is NOT empty in production
- [ ] `PAYMENT_BASE_URL` matches your actual domain/URL

## Environment Variables Reference

### Database Settings

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `DB_HOST` | Yes | localhost | Database server hostname |
| `DB_NAME` | Yes | trustmart | Database name |
| `DB_USER` | Yes | root | Database username |
| `DB_PASS` | Yes | (empty) | Database password |
| `DB_CHARSET` | No | utf8mb4 | Character set |

### Payment Settings

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `PAYMENT_BASE_URL` | Yes | http://localhost/Trustmart | Base URL for callbacks |
| `PAYMENT_CALLBACK_SECRET` | **CRITICAL** | auto-generated | HMAC secret for callback auth |
| `PAYMENT_GATEWAY_MODE` | No | simulation | Gateway mode (simulation/toyyibpay) |

### ToyyibPay Settings (Optional)

| Variable | Required | Default | Description |
|----------|----------|---------|-------------|
| `TOYYIBPAY_ENABLED` | No | false | Enable ToyyibPay gateway |
| `TOYYIBPAY_SECRET_KEY` | If enabled | (empty) | ToyyibPay userSecretKey |
| `TOYYIBPAY_CATEGORY_CODE` | If enabled | (empty) | ToyyibPay categoryCode |
| `TOYYIBPAY_API_URL` | No | https://dev.toyyibpay.com | API endpoint |

## Deployment Checklist

### Before Going Live

1. **Database Security**
   - Set strong `DB_PASS` in `.env`
   - Never use `root` user in production
   - Create dedicated MySQL user with limited privileges

2. **Payment Security**
   - Generate new `PAYMENT_CALLBACK_SECRET` (64+ characters)
   - Update `PAYMENT_BASE_URL` to production domain
   - Use HTTPS for `PAYMENT_BASE_URL`

3. **File Permissions**
   - Ensure `.env` is readable only by web server user
   - Set `.env` permissions: `chmod 600 .env`

4. **Version Control**
   - Verify `.env` is in `.gitignore`
   - Never commit `.env` to repository
   - Share `.env.example` instead

5. **Application Settings**
   - Set `APP_ENV=production` in `.env`
   - Set `APP_DEBUG=false` in `.env`
   - Disable PHP error display in production

## Troubleshooting

### "Database connection failed"

Check your `.env` file:
- Verify `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` are correct
- Test MySQL connection: `mysql -h DB_HOST -u DB_USER -p DB_NAME`

### "Payment callback authentication failed"

The `PAYMENT_CALLBACK_SECRET` must match between:
- The server generating signatures (gateway_sim.php)
- The server verifying signatures (callback.php)

If you changed the secret, restart your web server.

### Environment variables not loading

1. Check `.env` file exists in `Trustmart/` directory
2. Verify file format (KEY=VALUE, one per line)
3. Check for syntax errors (no spaces around `=`)
4. Restart web server after changes

## Example Production .env

```env
# Production Database
DB_HOST=localhost
DB_NAME=trustmart_prod
DB_USER=trustmart_user
DB_PASS=Str0ng_P@ssw0rd_H3r3!

# Production URLs
PAYMENT_BASE_URL=https://trustmart.example.com

# Security (generate new values!)
PAYMENT_CALLBACK_SECRET=a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6

# Application
APP_ENV=production
APP_DEBUG=false

# ToyyibPay (if using)
TOYYIBPAY_ENABLED=true
TOYYIBPAY_SECRET_KEY=your_toyyibpay_secret_key
TOYYIBPAY_CATEGORY_CODE=your_category_code
TOYYIBPAY_API_URL=https://toyyibpay.com
```
