# TrustMart — UAT Results Presentation (Business Stakeholders)

Audience: non-technical. Source of truth: `UAT_SUMMARY.md`, `DEFECTS.md`, `UAT_PLAN.md`.
Run date: **2026-10-13**. Any figure not in those files is marked **TO CONFIRM**.

Companion data: `UAT_RESULTS.csv` (paste directly into PowerPoint / Google Slides charts).

---

## Slide 1 — Title / Cover

- TrustMart — User Acceptance Testing (UAT) Results
- Prepared for business stakeholders · Run date 2026-10-13
- Coverage: Pembeli (Buyer) · Penjual (Seller) · Pentadbir (Admin)
- Overall outcome: 64 of 64 automated checks passed
- Recommendation: **CONDITIONAL GO** — clear the medium-priority refund-gate item before launch

**Visual:** clean cover with TrustMart logo, a single traffic-light showing "amber".
**Speaker notes (30s):** "We finished the acceptance test round for TrustMart across all three user roles. I'll walk through what we tested, the results, one open item, and my go/no-go recommendation. Short version: quality is strong and the returns blocker we flagged earlier has been fixed and re-tested this session."

---

## Slide 2 — Executive Summary & Go/No-Go

- **64 / 64** automated checks passed across Buyer, Seller and Admin (100%)
- Access control, login safety, and data-ownership rules all behaved correctly
- **One open issue** in the refund/return area (Medium) — a written safety rule is not enforced by the app
- **Two issues resolved** this cycle: the Admin Refund tab now works, and the Admin Returns page now loads and is reachable from the menu
- Recommendation: **CONDITIONAL GO** — decide on the refund-gate rule and complete the manual checklist before public launch

**Visual:** traffic-light (green core, one amber caveat) + a "64/64 PASS" hero number.
**Speaker notes (45s):** "The system's security foundations passed cleanly — buyers can't reach seller tools, sellers can't reach admin tools, nobody can tamper with another seller's listings. The high-priority blocker I flagged earlier — admins couldn't process customer returns — is now fixed and re-tested. There is one remaining Medium item: a written safety rule that stops a refund until a returned item is verified is defined but not enforced. My recommendation stays as a conditional go: decide on that rule, run the manual checks, and we approve."

---

## Slide 3 — Scope (What was tested)

- **Roles:** Pembeli, Penjual, Pentadbir (three; no others exist in the product)
- **Surfaces covered:** login/logout, dashboard access, product add/edit/delete, order actions, refund list & summary, dispute & user-verification actions, chat entry, profile
- **Test types:** positive happy-paths, security negatives (wrong role, anonymous, forged requests), data-integrity checks
- **Environment:** live TrustMart build (not a mock) with real data snapshots restored after each test
- **Out of scope for this automated round:** email content, browser visual polish, third-party payment gateway behaviour (covered by separate end-to-end scripts — results **TO CONFIRM** for the current release)

**Visual:** three-column matrix "Role × Area" with check icons.
**Speaker notes (45s):** "We tested each role against everything a real user would touch, and specifically tried to break the security rules — for example, logging in as a buyer and trying to open the admin dashboard. Some areas like email content and the third-party payment provider were checked by separate scripts and are flagged for a manual review before sign-off."

---

## Slide 4 — Approach (How we tested)

- Written as **automated test scripts** that hit the live site the same way a browser does
- Each script signs in as a real role, performs actions, and verifies both the site's response and the database state
- **Self-cleaning:** any records created for testing are removed after the run (no data pollution)
- **No code was changed to make tests pass** — a strict rule so results reflect the product as-is
- Supplementary live checks (browser + targeted scripts) verify the fixes shipped this cycle

**Visual:** simple flow diagram: "Login as role → Act → Verify response → Verify DB → Cleanup".
**Speaker notes (45s):** "Rather than clicking through screens manually, we wrote repeatable scripts that behave exactly like a user with a keyboard and browser. They sign in, do the real actions, then verify that the site's answers and the underlying data are correct. They clean up after themselves. And critically, we did not touch the product code to make tests pass — so what you'll see is the honest state of the app."

---

## Slide 5 — Overall Results

- **64 total automated checks · 64 passed · 0 failed**
- **100% pass rate** on the role suites
- Additional targeted checks this cycle: refund tab 18/18, dispute trust effect 7/7, cash-regression guard passes with one known-defect flag
- Total defects opened this cycle: **2** (1 High, 1 Medium)
- Total defects closed this cycle: **1** (Refund tab frontend)

**Visual:** large green donut chart showing 64 pass / 0 fail; a small side-panel listing "2 open · 1 closed".
**Speaker notes (30s):** "Sixty-four out of sixty-four. Every security and behaviour check we ran across the three roles passed. Alongside those, three smaller specialised tests also passed. We did find two separate issues in the refund/return workflow — those aren't in this pass count because they're outside the automated suite, and we cover them next."

---

## Slide 6 — Results per Role

| Role | Suite checks | Passed | Failed |
|---|---:|---:|---:|
| Pembeli (Buyer) | 19 | 19 | 0 |
| Penjual (Seller) | 23 | 23 | 0 |
| Pentadbir (Admin) | 22 | 22 | 0 |
| **Total** | **64** | **64** | **0** |

- Every role signed in successfully and reached its own dashboard
- Every role was **blocked** from the other roles' dashboards and APIs
- All three roles rejected forged/unauthenticated requests (CSRF protection)
- Full data lifecycle verified for sellers (create → edit → remove a listing)
- Admin's refund list correctly reflects all five refund statuses including "waiting for return"

**Visual:** horizontal stacked bar (pass=green) per role, all bars full.
**Speaker notes (40s):** "Buyer, seller and admin each have their own test pack. All three passed. For a business lens: this means a buyer can't slip into the seller tools, a seller can't peek into the admin tools, and the admin can't accidentally act inside a user dashboard. Also, when a seller lists a product, edits it, and takes it down, the system records every step correctly."

---

## Slide 7 — Results per Module (Business areas)

| Module / area | Checks (this run) | Result |
|---|---:|---|
| Authentication (login, session, logout) | 4 | All PASS |
| Role-based access control (pages + APIs) | 25 | All PASS |
| Anti-forgery (CSRF) protection | 3 | All PASS |
| Object-level ownership (my data only) | 4 | All PASS |
| Input validation on key actions | 5 | All PASS |
| Product create / edit / delete lifecycle | 5 | All PASS |
| Admin dashboard data & refund tab contract | 11 | All PASS |
| Cross-role scoped access (e.g. admin blocked from user dashboards) | 4 | All PASS |
| Other (catalogue view, order-cancel guard, profile) | 3 | All PASS |

- Modules are derived from the numbered tests in each role's plan
- Every module finished **green**; the one remaining open item sits in the **Returns/Refunds** workflow and is covered on the next slide

**Visual:** grouped bar chart of checks per module, colour-coded green.
**Speaker notes (40s):** "Grouping the same 64 checks by business theme: authentication, access control, tamper-proofing, ownership, validation, product management, admin reporting, and cross-role boundaries. All pass. The one remaining open item sits in the returns/refunds workflow — a specialised area the automated role suite doesn't fully exercise, which is why we track it separately."

---

## Slide 8 — Access Control Findings (the core of TrustMart's safety)

- A **buyer** cannot open the seller dashboard or the admin dashboard — the site refuses with a clear "Access Denied"
- A **seller** cannot open the admin dashboard or reach admin tools like user verification
- An **admin** is correctly kept out of the buyer and seller operational dashboards (their job is oversight, not transacting)
- **Anonymous** (logged-out) visitors are redirected to the login screen; API calls without a session are rejected
- Requests that skip the anti-forgery token (a common web-attack pattern) are rejected on **all three roles**
- Sellers **cannot** edit or delete another seller's listings, and **cannot** place orders on their own product (self-exclusion)

**Visual:** 3×3 permission matrix (buyer/seller/admin rows × buyer/seller/admin columns) with green ticks and red blocks; call-outs for CSRF and ownership.
**Speaker notes (60s):** "This is the most important slide. TrustMart is a marketplace — it only works if users trust that the platform keeps their data separate. We verified all nine combinations of 'who can open what'. Every cross-role attempt was correctly blocked. We also simulated a common attack: forging a browser request without the safety token — every role rejected it. And we specifically checked that one seller cannot touch another seller's listings, and a seller cannot buy their own product. All held."

---

## Slide 9 — Top Defects (in business terms)

**DEFECT-002 — Refund "wait-for-return" safety rule is not enforced** (Severity: Medium)
- What it means for the business: the platform has a written rule that says *"for refunds that require the item back, verify the return first."* The system does not actually check that rule today.
- Risk: a refund could be marked paid before the goods come back — a seller-loss / double-loss exposure and a dispute trigger.
- Fix effort: small — connect an existing rule-check into the two refund buttons, or formally retire the rule if the business has changed its mind.
- **Decision required from product owner:** keep the rule (and enforce it), or drop it.

**Fixed this cycle — Admin Returns page was empty and unreachable** (was High)
- Before: an admin opening the Returns page saw an empty list because of a small database-query mistake; the page also had no menu link.
- Business impact restored: admins can now see pending returns, accept a delivery, verify a return to release the refund, or waive the requirement with a written reason. Verified end-to-end.
- Regression guard added so this cannot silently break again.

**Fixed this cycle — Admin Refund Tab was completely dead; it now works**
- Before: no menu entry, no data loading — the tab was a broken shell.
- After: buyers' refunds display correctly, filters work, retry/complete buttons reach the right back-end. Verified live in the browser.

**Visual:** one Amber card "Medium · Return rule not enforced (needs a product decision)" plus two Green "Fixed" cards for Returns page and Refund tab.
**Speaker notes (60s):** "One open issue. The platform has a written safety rule that says 'for refunds needing an item returned, don't pay until the item is verified'. That rule is defined in the database but the app never calls it. So today, an admin could accidentally issue a refund before the item comes back — a seller-loss risk. We need a product decision: enforce the rule, or formally retire it. Separately, two items I flagged earlier are now resolved: the Admin Refund tab that was previously non-functional is working, and the Admin Returns page — which was empty and unreachable — now loads correctly with a proper menu link and has been verified end-to-end."

---

## Slide 10 — Risks & Coverage Gaps

- **Payment gateway path** — order → pay → provider callback → refund — exercised by separate end-to-end scripts, not this role suite. **Current pass status: TO CONFIRM.**
- **Emails & notifications** (activation, order updates, refund notices) — need human review for tone and content. **TO CONFIRM.**
- **Interface polish & empty states** — needs a browser walkthrough with a designer; no automated check.
- **Unverified-seller gate** (a seller can't post until approved) — could not be tested automatically because every demo seller in the current data is already verified. **Manual re-check needed with a fresh pending account.**
- **Trust-score behaviour** — verified for dispute outcomes; the effect of new reviews and fulfilment patterns is not covered by the automated suite.
- **Load, mobile-device and accessibility testing** — outside the scope of this cycle. **TO CONFIRM.**

**Visual:** risk heat-map (likelihood × business impact) with the six items plotted.
**Speaker notes (45s):** "Automated tests aren't a substitute for full coverage — they're the parts that repeat every release. Areas like the payment-provider round-trip, the tone of customer emails, and mobile/accessibility behaviour were not in this run. We should not sign off until those gaps have either an explicit 'pass' from manual review or an agreed reason to defer."

---

## Slide 11 — Next Steps & Recommendation

- **Product decision needed:**
  - DEFECT-002: enforce the wait-for-return rule, or formally retire it
- **Before final sign-off:**
  - Manual pass on payment, emails, and UI polish (per §Risks)
  - Verify the unverified-seller gate with a fresh "Menunggu" account
  - Re-run the existing payment end-to-end scripts against this build (**TO CONFIRM current results**)
- **Done this cycle:**
  - Admin Refund tab frontend (previously dead) — implemented and verified
  - Admin Returns page (previously broken and unreachable) — SQL fixed, proper UI added, menu entry wired in, regression guard installed
- **Recommended cadence:**
  - Add the 64 automated checks to every release (they take minutes and self-clean)
- **Business decision requested:** approve a **CONDITIONAL GO** — launch once the refund-gate decision and manual QA are complete.

**Visual:** timeline: "This week" (product decision) → "Next week" (manual QA) → "Go-Live gate".
**Speaker notes (45s):** "The technical blockers are cleared. What remains is one product decision — enforce or retire the wait-for-return rule — and a manual QA sweep covering payments, emails and the visual polish. Once those two are done, we're ready to sign off. The returns flow that was our biggest worry is fixed, tested and locked in with a guard."

---

## Slide 12 — Sign-off

| Role | Automated suite | Manual items | Ready to sign? |
|---|---|---|---|
| Pembeli (Buyer) | 19/19 PASS | order-to-review flow, chat, notification copy, empty states | **Not yet** — awaiting manual QA |
| Penjual (Seller) | 23/23 PASS | cash confirm, fulfilment update, image upload, unverified gate | **Not yet** — awaiting manual QA + fresh account check |
| Pentadbir (Admin) | 22/22 PASS | dispute resolve, seller verify, refund retry/complete UI, returns page UI | **Not yet** — returns page now functional; awaiting manual QA on remaining admin flows |

- Product owner sign-off: **TO CONFIRM**
- QA lead sign-off: **TO CONFIRM**
- Business sponsor sign-off: **TO CONFIRM**
- Target relaunch of this review: after the DEFECT-002 decision + §11 manual items

**Visual:** three signature cards, all amber "awaiting manual QA" (no red blockers remaining).
**Speaker notes (30s):** "Nothing to sign today, but there is no red-blocker card any more. Once the refund-gate decision is made and the manual checklist is complete, we'll bring back a package ready for signature. The automated foundations are already strong — the remaining work is small and specific."

---

## Appendix — Where to find the numbers

- Full roll-up: `docs/uat/UAT_SUMMARY.md`
- Per-role test tables: `docs/uat/uat-pembeli.md`, `uat-penjual.md`, `uat-pentadbir.md`
- Defect detail with evidence: `docs/uat/DEFECTS.md`
- Permission matrix & methodology: `docs/uat/UAT_PLAN.md`
- Chart-ready data: `docs/uat/UAT_RESULTS.csv`
