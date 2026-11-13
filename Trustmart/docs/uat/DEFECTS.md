# TrustMart — UAT Defects

Defects found during this UAT cycle. Each is **grounded in the code** and, where
possible, **reproduced live** against the running app. The automated role suite
(`tests/uat/`) surfaced **no new** application bugs (64/64 pass); these entries
come from targeted probes and code review around the payment/return subsystem.

Severity: Critical · High · Medium · Low.

---

## DEFECT-001 — Return-management page cannot load its data (`admin_returns.php`)

- **Role:** pentadbir (Admin)
- **Related test:** UAT-PENTADBIR-106
- **Severity:** High
- **Status:** **RESOLVED** this cycle (regression guard: `scratch/test_admin_returns_fix.php` — 16/16 PASS)
- **File / line:** `dashboard/admin_returns.php:38`

**Symptom (was)**
Opening the return-shipment manager and calling its data endpoint returned an
error instead of the pending-returns list.

**Steps that reproduced it (live, this cycle)**
1. Log in as `admin@uthm.edu.my`.
2. `GET /dashboard/admin_returns.php` → **200** (the outer page HTML rendered — bare stub).
3. The page's AJAX data load hits `GET /dashboard/admin_returns.php?action=get_pending_returns`.
4. **Actual at the time:** `HTTP 500` with body `{"success":false,"message":"Database error"}`.
   Server log: `[get_pending_returns] ... Unknown column 'd.reason'` (PDOException 42S22/1054).

**Expected:** `200` with `{"success":true,"returns":[…]}`.

**Root cause**
The `get_pending_returns` query projected `d.reason AS dispute_reason`
(`admin_returns.php:38`), but the `disputes` table has **no `reason` column** —
it uses `title` and `description` (confirmed via `SHOW COLUMNS FROM disputes`).
MySQL raised *Unknown column* and the surrounding `catch` converted it to a
generic 500.

**Impact / notes (before fix)**
- The whole return-verification UI was non-functional (no rows could ever load).
- The page was additionally **orphaned**: no sidebar link or any file referenced
  `admin_returns.php`, so even after the SQL fix there was no in-app entry point.
- `update_return_status` / `waive_return` handlers in the same file were sound;
  only the read query was broken.

**Fix applied (this cycle)**
- `admin_returns.php:38` — `d.reason AS dispute_reason` → `d.title AS dispute_reason`.
- Added a **"Pulangan Barang"** link in the `dashboard/admin.php` sidebar pointing
  at `admin_returns.php`.
- Replaced the bare stub body of `admin_returns.php` with a real UI that uses the
  shared `header.php`/`footer.php`, renders the pending-returns table, offers
  filter chips (Semua / Menunggu / Dalam Penghantaran / Diterima), and wires the
  three admin actions (Tandakan Diterima, Sahkan & Pulkinkan, Langgarkan) to the
  existing endpoints.
- New regression guard: `scratch/test_admin_returns_fix.php` (16 assertions,
  static + live + fixture, self-cleaning).

**Verification** — endpoint returns `200` + `success:true`, crafted fixture row
surfaces with populated `dispute_reason`, `product_name`, `buyer_name`,
`seller_name`, `tracking_number`; anonymous call still 401; admin.php sidebar now
contains the nav link. Original UAT role suite remains at 64/64.

---

## DEFECT-002 — Refund return-gate procedure is defined but never enforced

- **Role:** pentadbir (Admin) / payment subsystem
- **Related tests:** UAT-PENTADBIR-102/103 (refund tab actions); regression `scratch/test_e2e_refund_gate.php`
- **Severity:** Medium
- **Status:** **RESOLVED** (enforced, not retired)
- **File / lines:** `config/schema_update_v12.sql:74-122` (`can_proceed_refund`);
  wired into `dashboard/admin.php` (`retry_refund`, `mark_refund_completed`) via
  new top-level helper `refund_can_proceed()`.

**Symptom / root cause**
`can_proceed_refund` was authored to block a refund until a required
return shipment reaches `status='verified'`. Repo-wide grep showed it was
**created but never `CALL`ed** by any application code. The admin refund
actions (`retry_refund`, `mark_refund_completed`) mutate `payments.refund_status`
directly without consulting this gate.

**Impact**
A refund whose settlement requires the buyer to return the goods
(`settlement_return_required = 1`) could be marked completed / retried before the
physical return was verified — the intended business rule was not enforced.
This was a real bypass, not theoretical: `mark_refund_completed`'s allowlist
permitted a `requested` refund to complete, and `process_payment_refund`'s
`pending_return` guard never fires for `failed`/`requested` states, so a
return-required refund could be released while the goods were unreturned.

**Resolution (enforce):** added `refund_can_proceed(PDO,int):array` (calls the
authoritative procedure) and gated both actions: on `can_proceed=0` they return
HTTP 409 with the procedure's `p_reason` and perform no state change. Legitimate
release remains entirely through `admin_returns.php` (verify → flips to
`requested`, or waive), so the gate adds no dead-end.
**Verified:** `scratch/test_e2e_refund_gate.php` 13/13 (blocks `requested`+unverified
and `failed`+unverified; allows after `verified`; allows when no return required;
anon POST rejected; self-cleaning).

---

## RESOLVED this cycle — Admin refund tab had no frontend

- **Role:** pentadbir (Admin)
- **Related tests:** UAT-PENTADBIR-030..032 (backend contract), 102/103 (UI)
- **Was:** The `Pulangan Wang` tab was a dead shell — no sidebar nav link, no
  `showTab('pulangan')` lazy-load branch, and `loadRefunds()`/`filterRefunds()`
  were referenced by `onclick` but defined nowhere; `get_refunds` also ignored
  the `pending_return` status and its summary lacked a `pending_return_count`
  bucket.
- **Now (fixed in `dashboard/admin.php`):** nav link + `showTab` branch added;
  `loadRefunds`/`filterRefunds`/`renderRefunds`/`goToRefundsPage`/`retryRefund`/
  `markRefundCompleted` implemented against the existing endpoints;
  `'pending_return'` added to `$allowed_statuses` and a `pending_return_count`
  summary bucket added. Verified live (Browser) — rows/badges/filters render with
  no console errors; regression harness `scratch/test_refund_tab.php` = 18/18.

---

## Non-defect observations (for the record)

- **Refunds don't move the trust score directly.** The money state
  (`payments.refund_status` / `payment_status`) is not read by
  `recalculate_trust_score` / `recalculate_buyer_trust_score` and has no trigger.
  A buyer-favourable *dispute resolution* is what lowers the seller's score
  (verified: `scratch/test_dispute_trust_effect.php`). This is by design, not a bug.
- **Two "failures" seen during suite authoring were test-code assumptions, not
  app bugs** (a seller `delete_product` is a soft-delete `status='Dipadam'`; the
  demo seller already had prior adverse disputes). Corrected in the specs without
  touching application code.
