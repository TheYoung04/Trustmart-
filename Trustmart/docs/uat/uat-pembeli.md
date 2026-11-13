# UAT — PEMBELI (Buyer)

Role summary: browses catalogue, places cash/online orders, tracks "My Orders",
cancels (auto-refund), confirms receipt, leaves reviews, chats with sellers.
Must NOT reach seller or admin surfaces/data.

Automated spec: `tests/uat/pembeli/uat_pembeli.php` → **19 PASS / 0 FAIL**.

| Test ID | Module | Scenario | Preconditions | Steps | Test Data | Expected Result | Priority | Status |
|---|---|---|---|---|---|---|---|---|
| UAT-PEMBELI-001 | Auth | Login with valid credentials | active pembeli | POST login | aishah@…/password123 | session role=pembeli | High | AUTO / PASS |
| UAT-PEMBELI-002 | Auth | Login rejected (wrong password) | — | POST login | valid email / WRONG | success=false, no session | High | AUTO / PASS |
| UAT-PEMBELI-010 | Dashboard | Buyer dashboard reachable | logged in | GET buyer.php | — | 200 | High | AUTO / PASS |
| UAT-PEMBELI-020 | Access control | Cannot open admin dashboard (direct URL) | logged in as pembeli | GET admin.php | — | 403 "Akses Ditolak" | Critical | AUTO / PASS |
| UAT-PEMBELI-021 | Access control | Cannot open seller dashboard | logged in as pembeli | GET seller.php | — | 403 | Critical | AUTO / PASS |
| UAT-PEMBELI-030..035 | Access control | Admin read APIs blocked | logged in as pembeli | GET admin.php?action=… | get_users/sellers/transactions/refunds/analytics/ai_risks | 403 JSON each | Critical | AUTO / PASS |
| UAT-PEMBELI-036/037 | Access control | Admin POST APIs blocked | logged in as pembeli | POST verify_seller / resolve_dispute | — | 403 | Critical | AUTO / PASS |
| UAT-PEMBELI-038 | Access control | Seller add_product blocked | logged in as pembeli | POST seller.php?add_product | — | 403 | High | AUTO / PASS |
| UAT-PEMBELI-040 | Access control | Anonymous API → 401 + redirect | no session | GET admin.php?get_users (json) | — | 401 + redirect | High | AUTO / PASS |
| UAT-PEMBELI-041 | Session | Anonymous page redirects to login | no session | GET buyer.php | — | redirected to login | High | AUTO / PASS |
| UAT-PEMBELI-050 | Security | POST without CSRF rejected | logged in | POST cancel_order (no token) | — | 403 | Critical | AUTO / PASS |
| UAT-PEMBELI-051 | Orders | cancel_order on foreign/absent txn rejected | logged in | POST cancel_order | transaction_id=999999 | success=false | High | AUTO / PASS |
| UAT-PEMBELI-060 | Catalogue | Product detail renders | logged in | GET product.php?id=N | active product | 200 | Medium | AUTO / PASS |
| UAT-PEMBELI-100 | Orders | Place ONLINE order → pay → gateway → return | active product, online method | full HTTP flow | product id, E-Wallet | txn created, paid, redirect OK | Critical | MANUAL* |
| UAT-PEMBELI-101 | Orders | Place CASH order (unpaid_cash) | — | make_order cash | — | txn unpaid_cash | High | AUTO (regression: test_cash_payment_regression) |
| UAT-PEMBELI-102 | Orders | Confirm received | own completed-paid order | confirm_received | — | status progresses, review unlocked | High | MANUAL |
| UAT-PEMBELI-103 | Reviews | Submit review after completion | eligible order | submit_review | rating 1-5 | review saved, seller trust recomputed | Medium | MANUAL |
| UAT-PEMBELI-104 | Chat | Send message to seller | logged in | chat_api send_message | text | message stored | Medium | MANUAL |
| UAT-PEMBELI-105 | Notifications | Order/activation emails | — | trigger flows | — | mail sent (content) | Low | MANUAL (content not automatable) |
| UAT-PEMBELI-106 | UI | Empty states / error messages readability | no orders | view My Orders | — | clear empty state | Low | MANUAL |

`*` UAT-PEMBELI-100 payment path is exercised by existing live E2E scripts
(`scratch/test_live_checkout.php`, `test_e2e_*`); kept MANUAL here to avoid
mutating shared payment/callback state in the role suite.
