# TrustMart — UAT Plan (Master)

> Senior-QA engagement for **every user role**, derived from the actual code
> (auth guards, dashboard action handlers, payment/return flow, chat API), not
> assumptions. Any inference is tagged `ASSUMPTION:`.

## 1. Roles discovered (ground truth)

Roles come from `users.role` ENUM and are enforced by `require_role()` in
`includes/auth_check.php`. Three roles exist; no others are present in the repo.

| Role | Meaning | Guard usage (from code) |
|---|---|---|
| `pembeli` | Buyer | `buyer.php` → `require_role(['pembeli','penjual'])` |
| `penjual` | Seller | `seller.php` → `require_role('penjual')` |
| `pentadbir` | Admin (PTU) | `admin.php` → `require_role('pentadbir')` |

Shared surfaces: `product.php`, `receipt.php`, `profile.php` → any logged-in
role. `payment/pay.php`, `payment/return.php`, `payment/gateway_sim.php` →
`['pembeli','penjual']`. `chat_api.php` → logged-in user.
`dashboard/admin_returns.php` → `pentadbir` (but **orphaned**, see DEFECTS).

`require_role()` behaviour (verified): unauthenticated API (`Accept: json`) →
**401** JSON with `redirect`; wrong role API → **403** JSON; wrong role page →
**403** HTML "Akses Ditolak"; unauthenticated page → **302** to login.

## 2. Permission matrix (role × surface/action)

Legend: ✅ allowed · ⛔ blocked by guard (403) · ➖ not applicable · 📄 page · ⚙ API action

| Surface / action | pembeli | penjual | pentadbir |
|---|:--:|:--:|:--:|
| `buyer.php` (📄) | ✅ | ✅ (by design) | ⛔ |
| `buyer.php?cancel_order` (⚙) | ✅ (own) | ✅ (own) | ⛔ |
| `buyer.php?confirm_received` (⚙) | ✅ (own) | ✅ (own) | ⛔ |
| `buyer.php?submit_review` (⚙) | ✅ (own, completed) | ✅ | ⛔ |
| `seller.php` (📄) | ⛔ | ✅ | ⛔ |
| `seller.php?add_product` (⚙) | ⛔ | ✅ (if Disahkan) | ⛔ |
| `seller.php?edit_product` / `delete_product` (⚙) |  | ✅ (own only) | ⛔ |
| `seller.php?update_order_status` (⚙) | ⛔ | ✅ (own) | ⛔ |
| `seller.php?confirm_cash_payment` (⚙) |  | ✅ (own) | ⛔ |
| `admin.php` (📄) |  | ⛔ | ✅ |
| `admin.php?get_sellers/get_disputes/get_users/get_transactions/get_refunds/get_stats/get_analytics/get_ai_risks` (⚙) | ⛔ | ⛔ | ✅ |
| `admin.php?verify_seller/resolve_dispute/retry_refund/mark_refund_completed/toggle_user_status/ai_set_hold` (⚙) | ⛔ | ⛔ | ✅ |
| `product.php` view (📄) | ✅ | ✅ | ✅ |
| `product.php?make_order` (⚙) | ✅ | ✅ (not own product) | ✅ (role allowed) |
| `payment/pay.php`,`return.php`,`gateway_sim.php` (📄/⚙) | ✅ | ✅ | ⛔ (role not allowed) |
| `chat_api.php` (⚙) | ✅ | ✅ | ✅ (logged-in) |
| `profile.php` (📄) | ✅ | ✅ | ✅ |
| `admin_returns.php` (📄) | ⛔ | ⛔ | ✅ *(broken — DEFECT-001)* |

## 3. Tech stack & test framework

- PHP 8.2, MariaDB (XAMPP/Windows), server-rendered pages + fetch() JSON APIs,
  ToyyibPay integration (with a local `gateway_sim.php`), PHP `mail()` helpers.
- **No** PHPUnit / Playwright / Cypress / composer in the repo. The de-facto
  framework is PHP-CLI + curl/cookie-jar scripts (`scratch/test_e2e_*.php`).
- Per the prompt ("use the framework already in the repo; ask before
  installing"), automation reuses that convention:
  `tests/uat/lib/uat_lib.php` (login-as-role, CSRF-aware JSON requests,
  assertion recorder, DB cleanup) + per-role specs + `tests/uat/run_all.php`.
- `ASSUMPTION:` Playwright is the recommended **UI** layer for the MANUAL cases
  below; not installed (needs approval).

## 4. How to run

```powershell
# start MySQL + the preview router first (see DEPLOYMENT.md), then:
C:\xampp\php\php.exe Trustmart\tests\uat\run_all.php http://localhost:8080
# or one role:
C:\xampp\php\php.exe Trustmart\tests\uat\pentadbir\uat_pentadbir.php http://localhost:8080
```
Specs are self-cleaning: crafted rows (e.g. the seller product CRUD cycle) are
removed at teardown; demo baseline is left intact.

## 5. Cross-role scenarios (end-to-end)

1. **Purchase → pay → complete → review**: pembeli `make_order` → `pay.php` →
   `gateway_sim`/callback → seller `update_order_status` → buyer
   `confirm_received` → `submit_review`. Trust recomputes via DB triggers.
2. **Cash order → seller confirm**: buyer cash order (`unpaid_cash`) → seller
   `confirm_cash_payment` (`paid_cash`). (Regression: `scratch/test_cash_payment_regression.php`.)
3. **Dispute → settlement → refund**: buyer/seller raises dispute → admin
   `resolve_dispute` (Memihak Pembeli) → refund queued (`requested`/`pending_return`)
   → admin refund tab → `retry_refund`/`mark_refund_completed`. Buyer-favourable
   outcome lowers the seller trust score (verified: `scratch/test_dispute_trust_effect.php`).
4. **Seller verification**: penjual registers → admin `verify_seller` approve →
   seller can `add_product` (blocked until `Disahkan`).

## 6. Per-role plans

- [uat-pembeli.md](./uat-pembeli.md)
- [uat-penjual.md](./uat-penjual.md)
- [uat-pentadbir.md](./uat-pentadbir.md)

Status legend used in the tables: **AUTO** = covered by an executable spec,
**MANUAL** = needs a human/browser, **PASS/FAIL** = last automated result.

See [UAT_SUMMARY.md](./UAT_SUMMARY.md) and [DEFECTS.md](./DEFECTS.md) for outcomes.
