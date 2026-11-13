# TrustMart — System Brief

Concise technical brief for supervisor consultation and viva reference.
Audience: examiner / PTU supervisor. Reads as a developer document, so internal
terms (evidence units, Bayesian prior) are used deliberately; every string the
*user* sees is plain Malay with no internal jargon.

| | |
|---|---|
| Build | local XAMPP, `http://localhost/Trustmart`, MariaDB `trustmart` |
| Stack | PHP 8.2 (procedural, no framework) · MySQL/MariaDB stored procedures + triggers · vanilla JS · one shared CSS design system |
| Score formula | `TM-TRUST-2.0` · AI layer `TM-AI-1.0` |
| Brief compiled | 1 Nov 2026 |
| Presenter deck | `TrustMart_Konsultasi_SV.pptx` (13 slides, Malay) |

Every number below was read from source in this session (`trustmart.sql`,
`includes/trust_score.php`, `config/ai_trust.php`, `docs/uat/UAT_SUMMARY.md`) or
from an executed test run. Live row counts are deliberately **not** quoted —
they move as guards create and remove data, so a count in a document goes stale
immediately.

---

## 1. What it is

A C2C online marketplace (buyer ↔ seller ↔ administrator) where the differentiating
feature is not the shop, it is **reputation**: every seller and buyer carries an
explainable trust score computed from their own transaction record, and that score
is surfaced at the exact moments a stranger has to decide whether to trust them —
product cards, product detail, the chat header, checkout, and the admin's risk
queue.

The scoring authority is the **database**, not the application: MySQL stored
procedures and triggers recompute profiles on state change, PHP only reads and
presents. That makes the score tamper-resistant from the app layer and identical
for every surface that displays it.

## 2. Problem addressed

Marketplace fraud on student-to-student trading comes from three shapes: a seller
who takes payment and never delivers, a buyer who claims non-delivery, and
collusion rings that inflate each other's ratings. A naive "average star rating"
fails on all three — one review out of one is shown as 5/5, and a handful of
planted five-star reviews looks like a good seller. TrustMart's answer is a score
that is *deliberately pessimistic when evidence is thin*, robust to a single
reviewer's weight, and penalised by resolved disputes rather than by opinion.

## 3. Roles and lifecycle

| Role | Malay | Console tabs (verified `showTab()` keys) |
|---|---|---|
| Administrator (PTU) | `pentadbir` | `admin.php` — `dash`, `pengesahan` (seller verification), `aduan`, `pengguna`, `transaksi`, `pulangan`, `barang`, `analitik`. The AI risk panel sits on the dashboard tab, not a separate one |
| Seller | `penjual` | `seller.php` — `dash`, `produk` (add / edit / soft-delete), `pesanan` (confirm, reject, cancel, cash-receipt confirmation), `chat`, plus profile and buyer-console links |
| Buyer | `pembeli` | `buyer.php` — `explore` (catalogue), `pesanan` (pay, dispute, receipt, review), `chat`, plus the profile link |

Each console's tabs are plain in-page sections toggled by `showTab()` and refilled
by AJAX, so a role never leaves its own dashboard. A seller also has a buyer
persona, which is why the seller→buyer switch is an explicit link rather than a
role rewrite.

Lifecycle: **register → email activation → login → seller PTU verification →
product listing → order → payment → confirm/dispute → completion → trust score.**
A seller who is not verified cannot be bought from, and a user's role is bound to
the session — cross-role pages and JSON APIs return `403`, anonymous APIs `401`,
anonymous pages redirect `302` to login.

### 3.1 Account type: Pelajar UTHM / Staf UTHM

Two things are independent: the **role** (which console you get) and the
**account type** (`users.user_type` — who you are in the university). Every member
is either a student (`pelajar`, carries `matric_no`) or a staff member (`staf`,
carries `staff_no`). A staff account buys, sells, chats and is scored exactly like
a student one: it is *not* a fourth role, carries no extra permission, and gets no
badge on product cards or in chat. That scope was the deliberate decision — the
requirement was "staff can also use this", not "staff can administer this".

Enforcement is a type/domain pairing checked server-side in `auth/register.php`, and
that pairing is what makes the mailbox meaningful as proof of status (there is no
separate PTU verification of employment, by design):

| Type | Identifier | Mailbox | Unit field |
|---|---|---|---|
| `pelajar` | `matric_no` — `^[A-Z]{1,2}\d{6,7}$` | `@student.uthm.edu.my` | 6 faculties; PTU not selectable |
| `staf` | `staff_no` — 3–30 chars, `^[A-Z0-9][A-Z0-9\-\/.]{2,29}$` | `@uthm.edu.my` | the 6 faculties **+ PTU** |

`@student.uthm.edu.my` does not *end with* `@uthm.edu.my` (the character before
`uthm` is a dot), so `str_ends_with()` on the two domains is mutually exclusive and
both mismatches are separate `422`s: "staf + a student mailbox" and "pelajar + a
staff mailbox". The staff-number pattern is deliberately permissive because UTHM does
not publish one format — an over-strict pattern would reject real officers.
The identifier is unique per column, and the account type is chosen at registration
and **not editable afterwards**: the identifier, the domain and all the trust history
under the account depend on it, and flipping it would silently re-attribute a staff
member's record as a student's.

Display: every surface that used to print a matric number now prints one projected
column — `COALESCE(NULLIF(x.matric_no, ''), x.staff_no) AS ident_no`, plus
`buyer_ident` / `seller_ident` in the two-party views (receipt, disputes, admin
transaction table and its CSV export) — so a staff row can never render blank, and
profile/admin add the type wording (`Staf UTHM`, `No. Pegawai`, `Fakulti / Pusat`).
The session carries `ident_no` / `user_type` / `staff_no`, backfilled once for
pre-existing sessions by a shim in `includes/auth_check.php` rather than nine
fallbacks across nine templates.

Applied by `config/schema_update_v14.sql`, which also made `matric_no` nullable and
backfilled the one real staff row in the database — the PTU admin, which had been
storing `PTU-001` in `matric_no`, i.e. a matric number that never existed.

## 4. Architecture

```
auth/          register, login, activate, forgot/reset password
dashboard/     the three role consoles (SPA tabs, AJAX refresh in place)
payment/       pay, callback, return, gateway simulator, expiry job
config/        db, env, payment (shared writer), ai_trust, migrations (.sql)
includes/      header/footer, csrf, trust_score + ai_trust helpers, mail helper
product.php    public detail page (AI trust breakdown)
chat_api.php   messaging; trust displayed in the chat header
receipt.php    payment receipt
scratch/       regression guards and one-off DB tooling
tests/uat/     the three role suites + run_all.php
docs/uat/      UAT plan, results, defect log
```

Five design rules worth stating to the supervisor, because they are what kept the
system consistent while features were added:

1. **The DB is the only scorer.** `includes/trust_score.php` never reconstructs
   component mathematics; it reads `trust_profiles` + `trust_score_components`.
2. **One writer for terminal payment state.** Both the simulator and the real
   ToyyibPay callback route through `apply_payment_gateway_result()`
   (`config/payment.php`) — idempotent, locks the row `FOR UPDATE`, and never
   downgrades a completed payment.
3. **Server-rendered and AJAX renderers must agree.** Tables are keyed by
   `data-order-row="<txn>"` so one update refreshes both the dashboard quick-view
   and the tab list. (This rule exists because violating it caused a real defect —
   see §10.) The account-type work is the third time it bit: the admin verification
   list and the seller order list each exist as a PHP render *and* a JS render, and
   both had to be patched.
4. **Money decisions default safe.** An unknown/blank refund amount resolves to
   the *full* order value, never to zero.
5. **A hidden control is not enforcement.** The register and profile forms hide or
   disable whatever does not belong to an account type, but the server re-checks all
   of it: a student posting `faculty=PTU` to `profile.php?action=update_profile` is
   refused by the same type-aware allow-list that refuses a student who claims a
   staff mailbox at registration.

## 5. Order and payment lifecycle

States: `Menunggu → Disahkan → Selesai`, with `Dibatalkan` as the cancel terminal.
Only the buyer may mark `Selesai`; a buyer may self-cancel only while `Menunggu`
(a `Disahkan` order is routed to a dispute instead); a seller cannot confirm an
unpaid online order; stock is restored **exactly once** on every cancel path,
including expiry.

Two payment rails, one state machine:

- **Online** — `payment_type = online`, buyer picks the real channel (FPX /
  e-wallet) at the gateway; `payments.payment_status = paid`. In local demo mode
  `payment/gateway_sim.php` stands in for ToyyibPay; the environment and ownership
  gating on that simulator keeps it unreachable in production and to other users.
- **Cash** — `payment_type = cash`, `payment_status = unpaid_cash` until the seller
  records receipt via "Sah Terima Tunai" (`seller.php?action=confirm_cash_payment`,
  amount validated to within RM0.01, persisted in `cash_payments`), which flips it
  to `paid_cash`. Until then "Sahkan" is disabled and the server returns
  `422 action_required: confirm_cash_payment`. A cash order with the money in the
  seller's hand but no receipt recorded is stranded by design — that is the point.

Unpaid orders auto-expire 30 minutes after creation (`PAYMENT_TIMEOUT_MINUTES`,
`payment/expire_unpaid.php`), which restocks; an abandoned *failed* payment
reclaims stock too. Refunds are gated: a buyer-favourable ruling that requires a return
lands in `pending_return` and cannot be paid back until the admin verifies the
returned goods (`scratch/test_e2e_refund_gate.php`, 13/13).

## 6. Trust score — `TM-TRUST-2.0`

Seller weights (`recalculate_trust_score`):

| Component | Max | Metric |
|---|--:|---|
| Buyer reviews | 35 | Bayesian average `(20 + Σstars) / (5 + reviewers)`, mapped `((br − 1) / 4) × 100` |
| Order reliability | 30 | `(completed + 8) / (trials + 10) × 100`, trials = completed + seller-cancelled |
| Dispute safety | 20 | defect rate `(adverse + 0.5) / (trials + 10)`, adverse = disputes resolved *in the buyer's favour*; metric `100 × (1 − min(1, rate / 0.25))` |
| Responsiveness | 10 | decisions answered (confirm or cancel) within 12 h: `(timely + 4) / (decisions + 5) × 100` |
| Identity (PTU) | 5 | flat `5` if verified, else `0` |

The priors are the point: `(20 + Σ)/(5 + n)` means a single 5-star review yields
4.0/5, not 5.0/5; `+8/+10` and `+4/+5` do the same for fulfilment and response.
A brand-new seller sits near the middle by default and has to earn the extremes.

Buyers are scored by a separate procedure (`recalculate_buyer_trust_score`) with
its **own components and weights**, because the risk a seller faces from a buyer
is different in kind:

| Component | Max | Metric |
|---|--:|---|
| Order completion | 65 | `(completed + 8) / (trials + 10) × 100`, trials = completed + buyer-cancelled |
| Dispute safety | 25 | adverse = disputes resolved *in the seller's favour*, same defect-rate curve |
| Account standing | 10 | flat `10` while the account is active |

A buyer leaves no star rating and does not answer orders, so those components
disappear; what is measured instead is whether orders placed are orders completed.
Both roles share the confidence multiplier, the 3-unit per-counterparty cap and the
`Baharu` floor, and both land in the same bands: `Baharu` (no evidence), `Awal`
(confidence <40), `Membina` (<50), `Sederhana` (<70), `Dipercayai` (<85),
`Sangat Dipercayai` (≥85).

**Confidence is a separate multiplier, not another weight:**

```
E           = Σ per-counterparty min(events_with_that_counterparty, 3)
confidence  = min(100, 100 × ln(1 + E) / ln(11))
score       = raw_score × confidence / 100        (E = 0 → 0, badge "Baharu")
```

Qualifying events for a **seller** are `Selesai` orders plus orders *the seller*
cancelled: a buyer walking away neither helps nor hurts the seller, and a seller
cancelling counts against the seller. The **buyer** rule is the mirror image
(`Selesai` plus orders the buyer cancelled), so each side is judged only on what it
controlled. The 3-unit cap per counterparty is the anti-collusion lever: one willing
partner cannot carry a profile. The curve is steep where it matters and saturates:
E=1 → 28.9 %, 2 → 45.8 %, 3 → 57.8 %, 5 → 74.7 %, 10 → 100 %. Confidence labels:
`Tiada` 0, `Rendah` <40, `Sederhana` <80, `Tinggi` ≥80.

Worked contrast taken from live `trust_profiles` rows during the consultation
session (illustrative of the mechanic; re-derives on each recompute):

| User | Raw | Evidence | Displayed | Reading |
|---|--:|--:|--:|---|
| Siti Hajar | 76.04 | 2 | **34.8** | good record, thin evidence — halved |
| Mirza | 69.25 | 3 | **40.0** | *lower* raw, *higher* displayed |
| Nurul Ain | 79.25 | 0 | **Baharu** | nothing earned yet |

A seller with a worse underlying record outranks a better-but-less-proven one, and
that is intentional: the score is a prediction about a stranger, and a prediction
needs a sample.

**Explainability:** each component is persisted as its own
`trust_score_components` row (label, max points, metric, points, evidence count,
details), so the UI and the admin can show *why* a score is what it is, and the
formula version is stored on the profile.

## 7. AI trust layer — `TM-AI-1.0` (beyond-proposal enhancement)

A deterministic heuristic overlay, **not** the core deliverable, and it cannot
overrule the Bayesian baseline:

| Signal | Max penalty |
|---|--:|
| Circular trading / collusion loops | 30 |
| Low review credibility | 20 |
| Scam language in chat | 15 |
| Duplicated review text across buyers | 10 |

Total penalty is hard-capped at 50 (`AI_TRUST_MAX_PENALTY`) and dampened toward
zero below 3 units of evidence (`AI_TRUST_MIN_EVIDENCE`) — small-sample safety.
An optional LLM pass is **off unless an API key is set**, so the system deploys
identically on XAMPP and shared hosting with no external service. When
`fraud_risk ≥ AI_TRUST_HOLD_RISK` **and** `ai_hold = 1`, checkout from that seller
is held for admin review; the hold is released by an explicit admin decision that
is stamped with who and when. The layer fails open: if it cannot compute, the
deterministic score still serves the request.

## 8. Disputes and settlement

A buyer opens a dispute on a `Disahkan`/`Selesai` order (severity `Mendesak` /
`Sederhana` / `Rendah`). The admin resolves it as *Memihak Pembeli*, *Memihak
Penjual* or *Neutral*, and a buyer-favourable closure records an explicit,
persisted settlement — refund amount (clamped to the order total), whether goods
must be returned, and the fulfilment decision (`Kekal Selesai` or `Dibatalkan`).
It is **idempotent** via `settlement_status` (a replay is rejected `422`) and
**never auto-restocks**, because the goods may already be delivered. Resolved
disputes feed the dispute-safety component, so the reputation consequence is
automatic rather than manually applied.

## 9. Security and data-safety conventions

Prepared statements throughout, CSRF token on every mutating request (meta tag +
per-form field, rejected `403` without it), object-level ownership checks (a seller
cannot edit another seller's product, or order their own listing), soft-delete on
products, session role binding, `.env`-driven config with safe defaults and env
templates excluded from version control, no secrets in code, and a payment callback
that authenticates by reference and locks before writing.

## 10. Verification evidence

| Layer | Result |
|---|---|
| UAT role suites (`tests/uat/run_all.php`, run 2026-10-13) | **64 assertions, 0 failures** — buyer 19, seller 23, admin 22 |
| Defect log (`docs/uat/DEFECTS.md`) | DEFECT-001 (returns page 500 + orphaned) and DEFECT-002 (refund completed before return verified) both **resolved**, each with a regression guard |
| Automated guard suite (`scratch/demo_preflight.php`) | **9 guards / 136 assertions, all green** (receipt 11, receipt-unavailable 9, catalogue 12, trust-language 14, AI-hold copy 11, AI gate 6, refund gate 13, cash 29, dispute modal 31) — exit 0 = GO |
| Browser walkthroughs | cash-receipt flow 13 steps and dispute-modal flow on both render paths, real clicks, **zero console errors, zero non-2xx requests** |

Two defects are worth presenting as *method* rather than hiding, because both were
invisible to the tests that existed at the time:

- An endpoint (`confirm_cash_payment`) that was fully implemented and fully tested,
  with **no UI control to reach it** — a seller faced a `422` with nowhere to go.
  Endpoint-level tests cannot see a missing affordance, so the guard now asserts on
  rendered HTML.
- The dispute modal opened from the dashboard quick-view read `Pesanan #undefined`
  and prefilled the refund with `0.00`, because that one caller passed one argument
  where the function took three. Cosmetic wording, but the server clamps refunds to
  `min(order_total, submitted)` — accepting the prefill would have booked a RM0.00
  refund against a real order. Verified fix with a negative control: the bug was
  re-introduced deliberately, the guard failed 2 checks, then the fix was restored.

Read-only verification is a standing rule: guards craft their own rows and remove
them, and browser checks never press a submit control.

## 11. Known limits and open items

- Single-file PHP pages with inline JS: fast to build and demo, but no MVC/routing
  layer — the natural next step is extracting the console endpoints into a router.
- Email activation and the password-reset mail use the local mail helper; in
  production they need real SMTP credentials in `.env`.
- AI heuristics are keyword/pattern-based (`AI_SCAM_PATTERNS`); they are auditable
  but not a trained model, which is why the layer is capped and advisory.
- The deck's demo-prep checklist on slide 9 and parts of `docs/uat/*` still carry
  pre-fix wording and need a pass for the current trust vocabulary.
- Two seeded product photos share a backdrop and should be regenerated on different
  backgrounds before the presentation.
- Demo fixtures installed by `scratch/seed_demo_scenes.php` must be rolled back with
  its `--restore` flag after a session.

## 12. Running it

```powershell
# stack: start Apache + MySQL from the XAMPP Control Panel
& C:\xampp\php\php.exe Trustmart\scratch\demo_preflight.php    # GO / NO-GO + all guards
& C:\xampp\php\php.exe Trustmart\scratch\verify_cash_ui.php      # cash-receipt UI render
& C:\xampp\php\php.exe Trustmart\scratch\verify_dispute_modal_ui.php
& C:\xampp\php\php.exe Trustmart\tests\uat\run_all.php http://localhost:8080
```

App: <http://localhost/Trustmart/> · every demo account uses the password `password123`
(set by `scratch/seed_demo_scenes.php`, `db_reset_demo.php` and `db_reset_passwords.php`).
Migration history is the numbered `config/schema_update_v*.sql` set, applied by
`config/migrate.php`.
