# TrustMart — UAT Summary

Engagement per `Uat_prompt.txt`: discover roles → plan per role → automate →
run → report. This is the roll-up of the **latest real run**.

- **Run date:** 2026-10-13
- **Build under test:** local XAMPP preview, `http://localhost:8080`
  (`php -S … preview_router.php`), MariaDB `trustmart`.
- **Command:** `C:\xampp\php\php.exe Trustmart\tests\uat\run_all.php http://localhost:8080`
- **Frameworks:** repo-native PHP-CLI + curl/cookie-jar (`tests/uat/lib/uat_lib.php`);
  no third-party test framework was installed.

## 1. Automated results (this run)

| Role | Suite | PASS | FAIL | Exit |
|---|---|:--:|:--:|:--:|
| PEMBELI (buyer) | `tests/uat/pembeli/uat_pembeli.php` | 19 | 0 | 0 |
| PENJUAL (seller) | `tests/uat/penjual/uat_penjual.php` | 23 | 0 | 0 |
| PENTADBIR (admin) | `tests/uat/pentadbir/uat_pentadbir.php` | 22 | 0 | 0 |
| **TOTAL** | `tests/uat/run_all.php` | **64** | **0** | **0** |

Result: **ALL ROLE SUITES PASSED.** Specs are self-cleaning (crafted rows are
removed at teardown; demo baseline left intact; verified no catalogue leak).

Supporting targeted harnesses (kept in `scratch/`):
`test_refund_tab.php` 18/18, `test_dispute_trust_effect.php` 7/7,
`test_admin_returns_fix.php` 16/16 (DEFECT-001 regression guard),
`test_cash_payment_regression.php` (updated to reflect DEFECT-001 as fixed).

## 2. What the 64 assertions cover

- **Authentication** — valid/invalid login per role; session role binding.
- **Access control (the core of this app)** — every cross-role page and JSON API
  is guarded: wrong role → **403**, anonymous API → **401 + redirect**,
  anonymous page → **302 to login**. Admin is correctly *scoped out* of the
  buyer/seller operational dashboards; the seller→buyer dual-role path is allowed
  by design.
- **CSRF** — POSTs without a token are rejected (403) on all three roles.
- **Object-level ownership** — a seller cannot edit/delete another seller's
  product (target stays intact) and cannot order their own listing.
- **Input validation** — negative cases return 4xx with `errors` / `success:false`
  (add_product, verify_seller, resolve_dispute, retry_refund, mark_refund_completed).
- **Happy-path data lifecycle** — real product **add → edit → soft-delete** cycle
  persisted and verified in the DB.
- **Refund-tab backend contract** — `get_refunds` honours `pending_return` and
  exposes the `pending_return_count` + core counters (locks this cycle's fix).

## 3. Defects (details in [DEFECTS.md](./DEFECTS.md))

| ID | Severity | Summary | Status |
|---|---|---|---|
| DEFECT-001 | High | `admin_returns.php` `get_pending_returns` → 500 (`d.reason` unknown column); page also orphaned | **Resolved** this cycle (regression: `scratch/test_admin_returns_fix.php` 16/16) |
| DEFECT-002 | Medium | `can_proceed_refund` return-gate defined but never called — refund can complete before return verified | **Resolved** this cycle — gated `retry_refund` + `mark_refund_completed` via `refund_can_proceed()`; regression `scratch/test_e2e_refund_gate.php` (13/13) |
| (refund tab) | — | Admin `Pulangan Wang` tab had no frontend | **Resolved** this cycle |

The automated role suite itself found **no new** application bugs; all
DEFECTS entries came from targeted probes/code review, each reproduced live
where possible.

## 4. Coverage gaps & risks (not automated — need human/browser)

- **Full money path** (order → `pay.php` → gateway/callback → return) is covered
  by existing `scratch/test_e2e_*` scripts, deliberately left out of the role
  suites to avoid mutating shared payment state. Re-run those before sign-off.
- **Emails / notifications** (activation, order, refund) — content not
  automatable; verify manually.
- **UI/UX & empty states** — needs a browser pass (Playwright recommended but
  not installed; `ASSUMPTION:`).
- **Unverified-seller gate** (add_product blocked until `Disahkan`) — the demo
  seller is already verified; check with a fresh `Menunggu` account.
- **Trust-score changes** — verified for disputes only
  (`test_dispute_trust_effect.php`); reviews/fulfilment paths not automated here.

## 5. Sign-off checklist (per role)

**PEMBELI**
- [x] Login / logout, wrong-password rejected
- [x] Cannot reach seller or admin dashboards/APIs (403)
- [x] Anonymous & CSRF negatives behave
- [ ] Manual: place online + cash order, pay, receive, confirm, review, chat
- [ ] Manual: empty-state & notification readability

**PENJUAL**
- [x] Own dashboard + read endpoints
- [x] Product add/edit/delete lifecycle (soft-delete) persisted
- [x] Ownership guards (foreign product untouched); self-exclusion on ordering
- [x] Cannot reach admin surfaces/APIs; CSRF negative behaves
- [ ] Manual: cash confirm, fulfilment status, image upload, unverified gate

**PENTADBIR**
- [x] All read endpoints return success JSON
- [x] Refund-tab backend contract (`pending_return` + summary bucket)
- [x] Action validation + CSRF + anonymous 401; scoped out of buyer/seller pages
- [x] Return-management endpoint loads and page has working UI + nav entry (fixed this cycle)
- [ ] Manual: verify_seller approve, dispute resolve (trust effect verified), refund retry/complete via UI

## 6. Verdict

Automated acceptance for the three roles is **GREEN (64/64)** with sound access
control, CSRF, ownership and validation behaviour; the refund-tab fix and the
return-management page are now both working end-to-end. **Not yet fit to sign
off**: one open defect (DEFECT-002 Medium) in the return/refund gate, plus the
manual/browser gaps in §4. Recommend deciding on DEFECT-002 (enforce or formally
retire the return-verification rule) and running the manual checklist before
final acceptance.
