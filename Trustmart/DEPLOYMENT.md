# TrustMart — Deployment Checklist

Target environment: shared hosting / VPS running Apache or LiteSpeed with
PHP-FPM. Database **MySQL** (MariaDB 10.4+ is an acceptable drop-in — the app
uses the PDO `mysql:` driver only, no MySQL-8-only syntax).
Expected footprint: **< 100 MB** (whole app + sample uploads ≈ 4 MB, SQL dump ≈ 55 KB).

Work through the sections in order and tick each line before going live.

---

## 1. Server / runtime requirements

| Requirement | Value | Verified from |
|---|---|---|
| PHP | **8.2+** (app uses readonly-free modern syntax, `str_contains`, typed returns) | codebase-wide |
| Extensions | `pdo_mysql`, `mbstring`, `fileinfo`, **`curl`** (ToyyibPay API calls in `payment/toyyibpay_helper.php`), `openssl` (curl HTTPS with `SSL_VERIFYPEER=true`) | `config/db.php`, image MIME validation on upload, `toyyibpay_helper.php::makeRequest()` |
| Database | MySQL / MariaDB, charset `utf8mb4` | `config/db.php` |
| Web server | Apache/LiteSpeed with `.htaccess` support (or Nginx + equivalent rules) | root-relative includes |
| Mail transport | `sendmail` or SMTP (PHP `mail()` must actually deliver) | `includes/mail_helper.php` |
| Cron | PHP CLI available to cron, else HTTP scheduler | `payment/expire_unpaid.php`, `ai_trust_worker.php` |
| Storage | < 100 MB disk | measured |

Extension check on the deployed box:

```bash
php -m | grep -Ei 'pdo_mysql|mbstring|fileinfo|curl'   # all four must appear
php -r 'echo PHP_VERSION, PHP_EOL;'                    # >= 8.2
php -r 'var_dump(extension_loaded("curl"), function_exists("curl_init"));'
```

`fileinfo` is required because product-image uploads are type-sniffed rather
than trusted by extension; `mbstring` is required for the Malay-language text
and `htmlspecialchars` handling in the shared header/footer.

---

## 2. Files to deploy — exclude dev-only paths

Copy the **contents of `Trustmart/`** to the web root (`public_html/` or
`/var/www/html`). **Exclude** every path below; they are development
artefacts, some of which are directly reachable over HTTP and leak internals.

| Exclude | Why |
|---|---|
| `scratch/` | ~50 ad-hoc test/debug scripts, several open DB connections and print schema/data; some perform destructive writes (`db_cleanup.php`, `db_reset_admin_password.php`, `_cleanup_e2e_txn.php`) |
| `tests/` | role-based UAT scripts + `UAT_FINAL_RESULTS.md` — reveals expected behaviour and test accounts |
| `db_backups/` | full SQL dumps including password hashes and seeded demo users |
| `logs/` | never deploy live logs; `logs/payment_callback.log` contains transaction identifiers (create the directory empty) |
| `*.png` screenshots (repo root) | ~70 UAT/verification screenshots, ~5 MB, contain real account names and transaction numbers |
| `preview_router.php` | local XAMPP preview helper only |
| `DEPLOYMENT.md`, `CONFIG.md`, `implementation_plan.md`, `DEEP_AUDIT_REPORT.md`, `SECURITY_FIXES.md`, `UI_UX_ASSESSMENT_REPORT.md`, `UAT_TEST_PLAN.md` | internal documentation; `SECURITY_FIXES.md` / `DEEP_AUDIT_REPORT.md` enumerate vulnerabilities and file paths |
| `.git/`, `.gitignore` | version-control metadata |
| `.env` (local dev copy) | **never** ship the development `.env`; generate a fresh one from §3 |
| `public/assets/…` source maps / unused dev assets | none currently — keep as is |

Recommended transfer (from the repo root, excluding the list above):

```bash
rsync -av --delete \
  --exclude 'scratch/' --exclude 'tests/' --exclude 'db_backups/' \
  --exclude 'logs/*' --exclude '*.png' --exclude '*.md' \
  --exclude '.git/' --exclude '.gitignore' --exclude '.env' \
  --exclude 'preview_router.php' --exclude 'uploads/products/*' \
  Trustmart/ user@host:/var/www/html/
```

Keep `uploads/products/` content out of the sync (the seed images are demo
data) but **keep the directory itself**.

Also delete the empty-directory stub `uploads/products/Jam_tangan` (no
extension, not a real asset) after upload.

---

## 3. Configuration — `.env`

```bash
cd /var/www/html
cp .env.production .env
chmod 600 .env
```

Use the shipped [`​.env.production`](.env.production) template. Flags that
matter for security:

| Key | Production value | What it disables |
|---|---|---|
| `TRUSTMART_DEV_MODE` | **`false`** | (a) `activation_link` / `reset_link` being returned in JSON responses of `auth/register.php` and `auth/forgot_password.php` — tokens then travel **by email only** (`includes/mail_helper.php::trustmart_allow_demo_links()`); (b) the fake gateway `payment/gateway_sim.php`, so no one can self-authorise a payment (`config/payment.php::payment_simulation_allowed()`) |
| `APP_ENV` / `APP_DEBUG` | `production` / `false` | general debug posture |

> **Naming note:** the codebase has no `DB_PRODUCTION` or `DB_DEV_MODE`
> variables (grep-verified). `TRUSTMART_DEV_MODE=false` is the flag that
> performs the hardening described above. `config/db.php` does **not** contain
> a `?dev=` credential dump — it already suppresses error detail deliberately
> (`error_log(...)` then a generic JSON 503), so credentials are never echoed.
> Unknown `.env` keys are silently ignored by `config/env.php`, so use the
> exact names in the template — a typo provides no protection at all.
>
> **`PAYMENT_BASE_URL` gotcha (critical for the gateway).**
> `payment/toyyibpay_helper.php::createBill()` builds the gateway URLs by
> **appending** paths to this value:
> `PAYMENT_BASE_URL . '/payment/callback.php'` and
> `PAYMENT_BASE_URL . '/payment/return.php?ref=…'`.
> So `PAYMENT_BASE_URL` must be the base **without** a trailing `/payment`, and
> its path segment must match your layout:
> - app at web root → `https://YOUR-DOMAIN` (callback = `https://YOUR-DOMAIN/payment/callback.php`)
> - app in a folder → `https://YOUR-DOMAIN/Trustmart`
> A trailing slash is tolerable, a duplicated `/payment` is not
> (`/payment/payment/callback.php` → 404 → the gateway never reaches you and
> every order stays unpaid). §9 step 1 proves the exact URL before paying.

`TRUSTMART_DEV_MODE` already defaults to `false` when the key is absent
(`config/env.php`), and demo links additionally require a loopback request —
but set it explicitly anyway so intent is auditable.

Fill in every blank `CHANGE_ME` / `PASTE_...` / empty secret:

- `TOYYIBPAY_SECRET_KEY`, `TOYYIBPAY_CATEGORY_CODE` — from your toyyibPay
  dashboard (Settings → Integrations → *User Secret Key*; and the
  *CategoryCode* of the category you create for TrustMart bills). These two
  values **cannot be invented** — `ToyyibPayHelper::__construct()` throws
  "ToyyibPay credentials not configured" if either is empty, and a wrong/pasted
  placeholder returns `[KEY-DID-NOT-EXIST]` or `[CATEGORY-NOT-MATCH]` from the
  API. §9 step 0 verifies them before any real money moves.
- `DB_USER`, `DB_PASS` — dedicated least-privilege DB account (no `root`)
- `TRUSTMART_BASE_URL`, `PAYMENT_BASE_URL` — real `https://` origin; used to
  build clickable email links and never derived from the `Host` header
- `PAYMENT_CALLBACK_SECRET`, `TRUSTMART_CRON_KEY`, `AI_TRUST_CRON_KEY` —
  generate: `php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"`
- Leave `AI_TRUST_LLM_ENABLED=false` unless paid third-party analysis of
  chat/review text is approved (it sends user content off-server and should
  stay bounded by `AI_TRUST_LLM_MAX_CALLS`).

---

## 4. Database

1. Create database `trustmart` with `utf8mb4_unicode_ci`, then create the
   least-privilege user for §3.
2. Import the schema:
   ```bash
   mysql -u APP_DB_USER -p trustmart < trustmart.sql
   ```
3. Apply the **ordered** migration chain — `schema_update.sql` then
   `schema_update_v2.sql` … `v11.sql` (later versions add columns the app now
   reads, e.g. refund/payment-sync state in v10–v11). Skipping them causes
   "Unknown column" fatals at checkout and on the receipt page.
   ```bash
   php config/migrate.php            # preferred: CLI runner
   ```
   **Do not** use phpMyAdmin for the migrations that contain `DELIMITER`
   blocks (triggers/procedures) — the delimiter directives are not understood
   there and leave triggers missing. Use the MySQL CLI or `config/migrate.php`,
   which handles `DELIMITER`.
4. Verify triggers/procedures landed:
   ```sql
   SHOW TRIGGERS;                   -- trust-score / stock triggers present
   SELECT COUNT(*) FROM information_schema.columns
     WHERE table_schema='trustmart'; -- compare against the latest migration
   ```
5. **Do not** import `db_backups/*.sql` — those are local snapshots containing
   demo accounts and password hashes.
6. Remove or lock the seeded demo accounts from `trustmart.sql` (admin/buyer/
   seller demo emails are documented in the project's demo-account notes) before
   real users register.

---

## 5. Write permissions & ownership

The app writes at runtime to exactly these locations:

| Path | Written by | Notes |
|---|---|---|
| `uploads/products/` | seller product creation | image uploads; must be writable by the PHP user |
| `logs/` | payment callback + worker output | `logs/payment_callback.log` |
| `db_backups/` | admin-side backup/migration tooling | writable only if that feature is used; otherwise keep read-only |
| `.env` | never written | `600`, web-inaccessible |

```bash
# Debian/Ubuntu + PHP-FPM
sudo chown -R www-data:www-data uploads logs db_backups
sudo find uploads -type d -exec chmod 755 {} \;
sudo find uploads -type f -exec chmod 644 {} \;
chmod 755 logs db_backups
chmod 600 .env
```

**Harden the upload directory** — it accepts user files, so stop PHP execution
inside it (defence against a malicious image-with-PHP payload):

```apache
# uploads/.htaccess
php_flag engine off
Options -ExecCGI -Indexes
<FilesMatch "\.(php|phtml|php[0-9]|pht|cgi|pl|py|sh)$">
  Require all denied
</FilesMatch>
```

```apache
# root .htaccess — protect dotfiles and internal dirs from HTTP reads
<FilesMatch "^\.">
  Require all denied
</FilesMatch>
RedirectMatch 404 ^/(scratch|tests|db_backups|config)/
```

Also deny `.env`, `*.sql`, `*.md`, and `logs/` over HTTP, and add
`SetHandler None`/`Require all denied` for `db_backups` if it stays deployed.

---

## 6. Mail (sendmail / SMTP)

`includes/mail_helper.php` calls PHP's built-in `mail()` directly — there is no
SMTP library, so **transport is configured on the server, in `php.ini`, not in
the app**.

- **Linux/sendmail:** confirm `sendmail_path` in `php.ini`
  (e.g. `sendmail_path = /usr/sbin/sendmail -t -i`) and that the binary
  exists. On shared hosting, use the host's Mail Transfer Agent.
- **SMTP-only host:** PHP `mail()` cannot reach an authenticated SMTP server
  on its own; either point `sendmail_path` at an MSMTP/ssmtp wrapper, or add an
  SMTP library (PHPMailer + `composer`) — a code change, so plan for it.
- **Deliverability:** set `MAIL_FROM` to an address on the deployed domain, and
  publish **SPF**, **DKIM** and **DMARC** DNS records for that domain, or
  activation/reset mail lands in spam.
- **Do not** rely on the in-page demo link fallback (`TRUSTMART_DEV_MODE`) in
  production — email delivery must genuinely work for registration and
  password reset.

Smoke test on the server:

```bash
php -r 'var_dump(mail("you@yourdomain.com", "TrustMart mail test", "OK"));'
# then check: mail log, spam folder, and header for the correct From domain
```

---

## 7. Cron jobs

Both scripts are safe under repeat runs (idempotent state handling), and both
detect CLI vs HTTP themselves: when invoked over **HTTP** they require the
matching `?key=`/`X-Cron-Key` secret; over **CLI** the key check is skipped.

| Script | Purpose | Interval | Guard key |
|---|---|---|---|
| `payment/expire_unpaid.php` | expires unpaid transactions | every 5–15 min | `TRUSTMART_CRON_KEY` |
| `ai_trust_worker.php` | recomputes AI trust scores / risk holds | every 15–30 min | `AI_TRUST_CRON_KEY` |

> `payment/expire_unpaid.php` compares against SQL `NOW()` (not PHP time), so
> the **DB server clock and timezone must be correct** — a drifted MySQL clock
> silently expires paid orders or keeps dead ones open. Verify with
> `SELECT NOW();` and compare to the host clock.

**cPanel / Shared hosting (CLI):**

```cron
*/15 * * * * php -q /home/USER/public_html/payment/expire_unpaid.php
*/30 * * * * php -q /home/USER/public_html/ai_trust_worker.php
```

**System crontab (VPS):**

```cron
*/15 * * * * www-data php -q /var/www/html/payment/expire_unpaid.php >> /var/log/trustmart_cron.log 2>&1
*/30 * * * * www-data php -q /var/www/html/ai_trust_worker.php     >> /var/log/trustmart_cron.log 2>&1
```

**If PHP CLI is unavailable** (URL pinger / cPanel "Get URL" job), set the two
cron keys in `.env` and use:

```
https://YOUR-DOMAIN/payment/expire_unpaid.php?key=<TRUSTMART_CRON_KEY>
https://YOUR-DOMAIN/ai_trust_worker.php?key=<AI_TRUST_CRON_KEY>
```

Prefer the header form (`X-Cron-Key`) over the query string if your scheduler
supports custom headers — a `?key=` value can end up in access logs.

---

## 8. Web-server & TLS

- Serve over **HTTPS only**; force redirect and set `HSTS`.
- `session.cookie_secure=1`, `session.cookie_httponly=1`,
  `session.cookie_samesite=Lax` in `php.ini` (login/session cookies carry the
  role and user id used by `includes/auth_check.php`).
- `expose_php=Off`, `display_errors=Off`, `log_errors=On` (error_log points at
  a path outside the docroot).
- Confirm the CSRF helpers emit correctly on every page — `csrf_meta_tag()` and
  `csrf_script()` are output in `includes/header.php`; a missing meta tag
  breaks all AJAX posts (`chat_api.php`, dashboards).
- `upload_max_filesize`/`post_max_size` ≥ the largest product image you accept.

---

> **Note on repo layout:** this checklist assumes the **contents** of `Trustmart/`
> are deployed to the web root (so `/payment/expire_unpaid.php`, `/ai_trust_worker.php`
> and `/.env` sit at the top level). If you instead deploy the folder as
> `public_html/Trustmart/`, prefix every path accordingly and set
> `TRUSTMART_BASE_URL`/`PAYMENT_BASE_URL` to `https://YOUR-DOMAIN/Trustmart`.
> `.env` is resolved from `config/env.php` as one directory **above** `config/`,
> so the file must live in the app root, not inside `config/`.
>
> `.env.example` (dev) and `.env.production` (template, secret-free) are fine to
> commit; the generated `.env` is already git-ignored.

---

## 9. Live payment smoke test (real gateway checkout + callback)

Run this **on the deployed server, after §3–§8**, before letting real buyers in.
It is the only check that proves the money path works end-to-end, because
`TOYYIBPAY_ENABLED=true` is now the sole online payment route (the simulator is
disabled by `TRUSTMART_DEV_MODE=false`).

**Strongly recommended:** run steps 0–9 first against the **sandbox**
(`TOYYIBPAY_API_URL=https://dev.toyyibpay.com` + sandbox account credentials +
sandbox category code) so no real money moves, then repeat step 4+ with live
credentials. Sandbox and live credentials are **not** interchangeable — mixing
them returns `[KEY-DID-NOT-EXIST]` / `[CATEGORY-NOT-MATCH]`.

### Step 0 — Prove the config actually loaded (no DB, no payment)

```bash
cd /var/www/html
grep -nE 'CHANGE_ME|PASTE_' .env && echo "!! placeholder values still present" || echo "OK: no placeholders"

cat > /tmp/cfgcheck.php <<'PHP'
<?php
require_once getenv('TM_ROOT') . '/config/payment.php';
printf("TOYYIBPAY_ENABLED      = %s (must be bool(true))\n", var_export(TOYYIBPAY_ENABLED, true));
printf("TOYYIBPAY_API_URL      = %s\n", TOYYIBPAY_API_URL);
printf("SECRET_KEY configured  = %s (must not be empty or PASTE_...)\n", var_export(TOYYIBPAY_SECRET_KEY, true));
printf("CATEGORY_CODE configured = %s\n", var_export(TOYYIBPAY_CATEGORY_CODE, true));
printf("TRUSTMART_DEV_MODE     = %s (must be false)\n", var_export(TRUSTMART_DEV_MODE, true));
printf("simulator allowed      = %s (must be false)\n", var_export(payment_simulation_allowed(), true));
printf("PAYMENT_BASE_URL       = %s\n", PAYMENT_BASE_URL);
printf("expected callback URL  = %s/payment/callback.php\n", rtrim(PAYMENT_BASE_URL, '/'));
PHP
TM_ROOT="$(pwd)" php -d display_errors=1 /tmp/cfgcheck.php
```

Also confirm the deployed copy carries no dev-only paths:

```bash
for d in scratch tests db_backups config logs; do
  code=$(curl -s -o /dev/null -w '%{http_code}' "https://YOUR-DOMAIN/$d/")
  echo "$d -> $code (want 403/404)"
done
```

- [ ] Every "must" above is satisfied; no `PASTE_`/`CHANGE_ME` remains
- [ ] `TOYYIBPAY_ENABLED` is `bool(true)` — `env()` converts `"true"` to a real
      boolean, but a stray value like `1`/`yes` stays a string and still passes
      `define(..., env(...))`, so check the type, not just truthiness

### Step 1 — Prove the callback endpoint is reachable from the public internet

ToyyibPay's servers POST to your callback; nothing on your side triggers it.
Their docs state **"Callback URL cannot be received in localhost"** — so this
must be tested on the public host.

```bash
curl -sS -i -X POST "https://YOUR-DOMAIN/payment/callback.php" \
     -d 'refno=PROBE0001&status_id=3&billcode=probe&amount=1.00'
# You WANT: HTTP 404 "Payment not found" — that single response proves the URL
# routes, PHP executes, and the DB is reachable, while changing nothing (the ref
# does not exist). HTTP 400 "Invalid callback parameters" means the POST fields
# are not arriving (rewrite rule / wrong path). A 403 or 404 from Apache/Nginx
# instead of callback.php means §5's .htaccess is blocking the gateway.
```

- [ ] Response body comes from `callback.php` itself (404 `Payment not found`),
      not from Apache/Nginx
- [ ] `logs/payment_callback.log` gained a line with `{"refno":"PROBE0001"...}` —
      if not, the directory is not writable and you will be debugging blind
      (`callback.php:13-17` writes with `@`, so failures are silent)
- [ ] HTTPS only, valid certificate, no redirect to HTTP (a redirect on POST
      can drop the body)

### Step 2 — Prove the API credentials work (no bill, no charge)

```bash
cd /var/www/html
cat > /tmp/apicheck.php <<'PHP'
<?php
require_once getenv('TM_ROOT') . '/payment/toyyibpay_helper.php';
try {
    $h = new ToyyibPayHelper();          // throws if disabled or creds blank
    echo "OK: helper constructed (enabled + credentials present)\n";
} catch (Throwable $e) {
    echo "FAIL: ", $e->getMessage(), "\n"; exit(1);
}
PHP
TM_ROOT="$(pwd)" php /tmp/apicheck.php
```

Then verify the key + category pair against the API itself (the helper exposes no
category wrapper, so use curl — this is the only step that actually authenticates
against toyyibPay):

```bash
API=$(grep '^TOYYIBPAY_API_URL' .env | cut -d= -f2)
KEY=$(grep '^TOYYIBPAY_SECRET_KEY' .env | cut -d= -f2)
CAT=$(grep '^TOYYIBPAY_CATEGORY_CODE' .env | cut -d= -f2)
curl -sS -X POST "$API/index.php/api/getCategoryDetails" \
  -d "userSecretKey=$KEY&categoryCode=$CAT"
```

- [ ] Returns the category JSON — **not** `[KEY-DID-NOT-EXIST]`,
      `[CATEGORY-NOT-MATCH]`, `[CATEGORY-IS-INACTIVE]`, and not an HTML page
- [ ] The category is **active** and its payment channels (FPX / card / eWallet /
      DuitNow QR) are enabled for your account — `createBill` requests
      `billPaymentChannel = 0` (FPX only)

### Step 3 — Confirm the timeout window is workable

Real FPX logins + bank authorization can take several minutes.
`PAYMENT_TIMEOUT_MINUTES = 30` (`config/payment.php:67`) and
`payment/expire_unpaid.php` closes unpaid attempts older than that using
**SQL `NOW()`**.

```sql
SELECT NOW(), @@global.time_zone, @@session.time_zone;   -- compare to `date` on the host
```

- [ ] MySQL clock/timezone correct (`Asia/Kuala_Lumpur` behaviour as intended)
- [ ] 30 minutes is enough for a real bank login, or raise
      `PAYMENT_TIMEOUT_MINUTES` before launch (a bill expired server-side at
      toyyibPay while the order is still open = a reconciliation case)

### Step 4 — Real checkout walk-through

Use a **test buyer account** and the **cheapest live product** (create a RM1.00
product as the test seller; note toyyibPay rejects bills below RM1.00:
`billAmount should more than 1.00`).

1. Log in as the buyer (never the simulator: confirm no FPX/e-Wallet simulator
   cards render — `pay.php:286` hides them when dev mode is off, and
   `pay.php:295` shows the single "ToyyibPay (FPX/Card)" option).
2. Buy an online-payment product → order appears as `Menunggu` / `Belum Bayar`.
3. Open **My Orders → Bayar Sekarang** → `payment/pay.php?txn=<id>` →
   select **ToyyibPay** → *Teruskan ke Pembayaran*.
4. [ ] Browser lands on `https://toyyibpay.com/<BillCode>` — i.e. the
       **bill page renders and the amount shown equals the order total**.
       **This single view catches the two most likely defects** (see §11):
       an amount 100× off, or a `billName/billDescription` parameter rejection.
       A `Ralat membuat pembayaran: ToyyibPay error: …` on `pay.php` means the
       API rejected the bill — read the message verbatim before continuing.
5. [ ] `payments` row for this ref now has `payment_url` = the bill link and
       `gateway_response` containing `{"billCode":"…"}`:
       ```sql
       SELECT payment_id, payment_reference, payment_method, status, amount,
              payment_url, gateway_response
         FROM payments ORDER BY payment_id DESC LIMIT 1;
       ```
       `payment_method` **must** be `toyyibpay` — `callback.php:67` only
       performs the server-side bill verification for that value.
6. Complete the payment on the gateway (sandbox: use the Bank Simulator).
7. [ ] Browser is redirected to `payment/return.php?ref=<ref>` (this is
       `billReturnUrl` — it proves the base-URL math in §3 was right).
       The page may legitimately show **"Pembayaran Dalam Process/pending"**
       for a few seconds: `return.php:52` reads *your DB*, which is updated by
       the **asynchronous callback**, not by the redirect. Treat "pending" here
       as unproven, not as success — Step 5 is the verdict.

### Step 5 — Verify the callback fired and state converged

```sql
-- order state
SELECT t.transaction_id, t.status, t.payment_status, t.paid_at, t.total_amount,
       p.payment_reference, p.status AS pay_status, p.paid_at AS pay_paid_at,
       p.amount, p.gateway_response
  FROM transactions t JOIN payments p ON p.transaction_id = t.transaction_id
 WHERE t.transaction_id = <TXN_ID>;

-- exactly one completed attempt
SELECT COUNT(*) AS completed_attempts FROM payments
 WHERE transaction_id = <TXN_ID> AND status = 'completed';

-- stock was actually decremented
SELECT stock, sold_count FROM products WHERE product_id = <PRODUCT_ID>;
```

```bash
tail -n 20 logs/payment_callback.log        # the raw POST payload from toyyibPay
```

- [ ] `payments.status = 'completed'`, `paid_at` set, `gateway_response` holds
      the real callback payload
- [ ] `transactions.payment_status = 'paid'`, `paid_at` set
- [ ] `completed_attempts = 1`
- [ ] Buyer dashboard shows **✓ Dibayar**, and `receipt.php?txn=<id>` renders
      with no fatal (this page has broken on schema drift before)
- [ ] Amount check: `payments.amount` equals the order snapshot. Note
      `billChargeToCustomer = 1` (`toyyibpay_helper.php:63`) means the buyer is
      charged the processing fee **on top** — so the gateway's `amount` may
      exceed yours deliberately. Confirm which figure you intend to reconcile,
      and that it does not trip the verification in Step 6
- [ ] **Payload audit — this is where the latent integration bugs surface.**
      Read the logged POST body and confirm all three keys the code requires:
      `refno`, `status_id`, `amount`. Standard toyyibPay FPX callbacks send
      `status` (1=success, 2=pending, 3=fail) and often **no `status_id`**, and
      they sign with `hash` = `MD5(userSecretKey + status + order_id + refno + "ok")`.
      `callback.php:24` requires `status_id` (`/^[A-Za-z0-9_]+$/`) and
      `callback.php:31` rejects the whole callback if `status` (read from
      `status_id`) is empty → **HTTP 400 and the order never becomes paid**.
      Also confirm `refno` echoes the `billExternalReferenceNo` you sent —
      toyyibPay documents `order_id` for that, so if `refno` is blank/mismatched
      you get **404 `Payment not found`** on every real callback.

**If the callback logged but state did not converge**, the fix is in the code,
not the config — most likely one of:

| Symptom in log/DB | Cause | Where |
|---|---|---|
| `400 Invalid callback parameters`, payload has `status` not `status_id` | reads only `status_id` | `payment/callback.php:24,31,95` — fall back to `status` |
| `404 Payment not found`, `refno` ≠ your `payment_reference` but `order_id` matches | wrong identity key | `payment/callback.php:23` — prefer `order_id` fallback |
| `400 Payment verification failed` although the bill is paid | `verifyPayment` compares callback `amount` (fee-inclusive) to the API's bill amount, and strict `=== '1'` | `payment/toyyibpay_helper.php:114-119` |
| Bill page shows the wrong amount (e.g. RM15 → RM0.15) | `billAmount` sent as `"15.00"` but the API expects **cents** (1500) | `payment/toyyibpay_helper.php:53` |
| `billName exceed limit. Max 30 length` / parameter-format error | product name truncated to 100 chars, not sanitised to `[A-Za-z0-9 _]` ≤ 30 | `payment/toyyibpay_helper.php:49-50` |

Fix, redeploy, and **re-run Steps 4–6 from scratch** — a paid sandbox bill
cannot be re-paid.

### Step 6 — Duplicate/replay safety (idempotency)

Replay the exact callback you captured in Step 5 (substitute your real values):

```bash
curl -sS -i -X POST "https://YOUR-DOMAIN/payment/callback.php" \
  -d 'refno=<REF>&status_id=1&amount=<AMOUNT>&billcode=<BILLCODE>&transaction_id=<GATEWAY_TXN>'
# Expect HTTP 200 "Payment already processed" (apply_payment_gateway_result
# returns already_processed; the row lock + status IN ('pending','processing')
# guard makes a second completion impossible).
```

- [ ] Response is `already processed`, and **no** state moved: `paid_at`
      unchanged, `sold_count` not decremented twice, still exactly one
      `completed` payment row
- [ ] A **late `failed`** notification cannot un-pay it:
      replay with `status_id=3` → must still return success-state
      (`config/payment.php:268-286` only moves `pending`/`processing` rows)
- [ ] A forged callback for someone else's ref is refused: POST a
      `payment_method='fpx'` (simulator-era) ref **without** `signature` →
      **401**, and with `signature=deadbeef` → **403**
- [ ] `payment/gateway_sim.php?ref=<ref>` is not usable in production
      (should refuse — it re-checks `payment_simulation_allowed()`)

### Step 7 — Refund leg (only if refunds are expected in production)

`process_payment_refund()` calls `POST /index.php/api/refundBill` when
`payment_method = 'toyyibpay'`. **That endpoint is not in the public toyyibPay
API reference** (it appears to need enterprise/merchant enablement), so assume
it will fail until confirmed. Cancel/refund the paid test order as the buyer
(or resolve a dispute as admin) and watch:

```sql
SELECT refund_status, refund_reference, refund_error, refund_attempts, status
  FROM payments WHERE transaction_id = <TXN_ID>;
```

- [ ] `refund_status` is `processing`/`completed` with a `refund_reference`,
      **or** `failed` with an `refund_error` you have read — never silently
      blank
- [ ] `payments.status`/`transactions.payment_status` only show `refunded` when
      the refund is genuinely confirmed (`config/payment.php:432-459`)
- [ ] Stock/`sold_count` restored consistently, and the buyer sees the correct
      refund badge
- [ ] If `refundBill` is unsupported on your account, record the manual refund
      procedure before launch — buyers will otherwise be told "pulangan wang
      sedang diproses" forever

### Step 8 — Abandoned-payment / retry path (highest-risk edge)

Start a bill, close the tab without paying, then click **Bayar Sekarang** again:

- [ ] First: `pay.php:72-89` **resumes** the still-valid `payment_url` (no new
      bill). Confirm you are returned to the *same* BillCode.
- [ ] Then let the attempt go stale (> `PAYMENT_TIMEOUT_MINUTES`, or run
      `php payment/expire_unpaid.php`) and pay again: `pay.php:146-164` marks
      the old pending attempt `failed` and creates a **new** bill.
- [ ] **Reconciliation trap to test explicitly:** pay the **old/superseded**
      bill (still open at toyyibPay) after the new one exists. Its callback
      finds a payment already in `failed`, so the guarded `UPDATE` matches 0
      rows and the code reports `already_processed`
      (`config/payment.php:241-265`) — the money is taken and **nothing is
      fulfilled or flagged**. If this returns 200 with no `needs_reconciliation`
      entry in `logs/`, do not launch with real money; fix the superseded-bill
      path first.
- [ ] `error_log` / `logs/payment_callback.log` records a `LATE PAYMENT …
      needs reconciliation` line for exactly that case

### Step 9 — Cleanup and attestation

- [ ] Restore test data: put the test product's `stock`/`sold_count` back, and
      delete or mark-complete the test transaction + payment rows (via a
      reviewed script — the destructive helpers in `scratch/` must **not** be
      on the production server, per §2)
- [ ] Remove the RM1.00 test product, or clearly mark it as test inventory
- [ ] Suspend/delete the test buyer + test seller accounts
- [ ] Confirm `logs/` is **not** web-readable — it now holds real bill codes,
      gateway payloads and buyer email addresses
- [ ] Record in the release log: date, BillCode(s) used, sandbox vs live, the
      captured callback payload, and every SQL result above
- [ ] Only after all steps pass: advertise the site to real users

---

## 10. Pre-launch verification (must pass before advertising the URL)

- [ ] **§9 live payment smoke test completed and signed off** (the money path)
- [ ] `php -m` shows `pdo_mysql`, `mbstring`, `fileinfo`, `curl`; PHP ≥ 8.2
- [ ] `TRUSTMART_DEV_MODE=false` effective — register a fresh account from a
      **non-loopback** IP and confirm the JSON response contains **no**
      `activation_link`; the mail must arrive instead
- [ ] `payment/gateway_sim.php` returns an access error (simulator disabled) and
      online checkout routes to the real gateway
- [ ] A DB failure returns the generic JSON 503 with **no** credentials or stack
      trace in the body (check with wrong `DB_PASS` temporarily, then revert)
- [ ] `scratch/`, `tests/`, `db_backups/`, `logs/*.log`, `*.sql`, `*.md`,
      `*.png` all return **403/404** over HTTP
- [ ] Direct request to a `.php` file inside `uploads/` does not execute
- [ ] Product image upload succeeds as a seller, and the image renders
- [ ] Both cron jobs ran at least once without error (check `logs/` and the
      order/trust state afterwards); `SELECT NOW();` matches the host clock
- [ ] Login → checkout → payment callback → receipt path verified live in a
      browser (receipt page has historically broken on schema drift)
- [ ] Admin, seller and buyer dashboards load with no fatal in the server error
      log
- [ ] Demo/test accounts from `trustmart.sql` removed or suspended
- [ ] Off-site backup of the database scheduled (`mysqldump` + rotation)

---

## 11. Known production gaps

1. **Payment integration is config-complete but not code-verified** —
   `TOYYIBPAY_ENABLED=true` with real credentials now opens the online path,
   but §9 Step 5 lists five unverified assumptions in
   `payment/toyyibpay_helper.php` / `payment/callback.php` (bill amount in
   **cents**, `status_id` vs `status`, `refno` vs `order_id`, amount
   fee-inclusion mismatch, `billName` charset/length). None of them can be
   proven from config alone — they are only exposed by a real bill + real
   callback. **Treat §9 Steps 4–6 as blocking.**
2. **Superseded-bill reconciliation hole** — paying an abandoned/failed bill
   after a retry yields `already_processed` with no funds captured on the order
   (§9 Step 8). Money is taken and the order stays unpaid.
3. **`refundBill` is not in the public toyyibPay API reference** — automated
   refunds may be unavailable on a standard account (§9 Step 7); confirm with
   toyyibPay or document a manual refund procedure.
4. **`PAYMENT_GATEWAY_MODE` is dead config** — `config/payment.php:14` defines
   it and nothing reads it. The real switches are `TOYYIBPAY_ENABLED` plus
   `payment_simulation_allowed()`; setting `PAYMENT_GATEWAY_MODE=toyyibpay`
   does nothing at all.
5. **No SMTP library** — `mail()` only (see §6). Authenticated SMTP needs a
   code change or an MSMTP wrapper.
6. **Migrations are manual and order-sensitive** — keep a note in the release
   record of which `schema_update_vN.sql` files have been applied.
7. **`db_backups/` and `logs/` were committed with content** — scrub them from
   history if the repo is ever made public (they contain real transaction ids
   and demo password hashes).
8. **Timezone** — set `date.default_timezone` in `php.ini` to the business
   zone (Malaysia, `Asia/Kuala_Lumpur`) and keep the MySQL timezone
   consistent; expiry and receipt timestamps depend on both.
