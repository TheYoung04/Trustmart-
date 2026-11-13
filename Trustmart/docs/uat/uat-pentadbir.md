# UAT — PENTADBIR (Admin / PTU)

Role summary: oversees sellers, users, transactions, disputes and refunds;
verifies sellers, resolves disputes, retries/completes refunds, toggles user
status, reads analytics/AI-risk. Must NOT act inside the buyer or seller
operational dashboards (scoped out), but can use the shared profile page.

Automated spec: `tests/uat/pentadbir/uat_pentadbir.php` → **22 PASS / 0 FAIL**.
Subject: `admin@uthm.edu.my`.

| Test ID | Module | Scenario | Preconditions | Steps | Test Data | Expected Result | Priority | Status |
|---|---|---|---|---|---|---|---|---|
| UAT-PENTADBIR-001 | Auth | Admin login | active pentadbir | POST login | admin@…/password123 | session role=pentadbir | High | AUTO / PASS |
| UAT-PENTADBIR-010 | Dashboard | Admin dashboard reachable | logged in | GET admin.php | — | 200 | High | AUTO / PASS |
| UAT-PENTADBIR-020..027 | Read APIs | Every admin read endpoint returns success JSON | logged in | GET admin.php?action=… | get_sellers/disputes/users/transactions/refunds/stats/analytics/ai_risks | 200 success each | High | AUTO / PASS |
| UAT-PENTADBIR-030 | Refunds | get_refunds?status=pending_return accepted (not silently ignored) | logged in | GET get_refunds | status=pending_return | success + refunds[] | High | AUTO / PASS |
| UAT-PENTADBIR-031 | Refunds | Summary exposes pending_return_count bucket | logged in | GET get_refunds | — | summary has pending_return_count | High | AUTO / PASS |
| UAT-PENTADBIR-032 | Refunds | Summary has core refund counters | logged in | GET get_refunds | — | total/failed/completed counts present | Medium | AUTO / PASS |
| UAT-PENTADBIR-040 | Actions | verify_seller rejects invalid user_id | logged in | POST verify_seller | user_id=0 | success=false | High | AUTO / PASS |
| UAT-PENTADBIR-041 | Actions | resolve_dispute rejects invalid status | logged in | POST resolve_dispute | dispute_id=0 status=Bogus | 422 | High | AUTO / PASS |
| UAT-PENTADBIR-042 | Actions | retry_refund rejects payment_id=0 | logged in | POST retry_refund | payment_id=0 | 422 | High | AUTO / PASS |
| UAT-PENTADBIR-043 | Actions | mark_refund_completed rejects payment_id=0 | logged in | POST mark_refund_completed | payment_id=0 | 422 | High | AUTO / PASS |
| UAT-PENTADBIR-050 | Security | POST without CSRF rejected | logged in | POST toggle_user_status (no token) | user_id=2 | 403 | Critical | AUTO / PASS |
| UAT-PENTADBIR-051 | Access control | Anonymous admin API → 401 | no session | GET get_stats (json) | — | 401 success=false | High | AUTO / PASS |
| UAT-PENTADBIR-060 | Access control | Admin scoped OUT of buyer dashboard | logged in | GET buyer.php | — | 403 | Medium | AUTO / PASS |
| UAT-PENTADBIR-061 | Access control | Admin scoped OUT of seller dashboard | logged in | GET seller.php | — | 403 | Medium | AUTO / PASS |
| UAT-PENTADBIR-062 | Access control | Admin CAN open shared profile page | logged in | GET profile.php | — | 200 | Low | AUTO / PASS |
| UAT-PENTADBIR-100 | Sellers | Approve/reject a pending seller end-to-end | a Menunggu seller | POST verify_seller | approve | seller→Disahkan, identity trust +5 | High | MANUAL |
| UAT-PENTADBIR-101 | Disputes | Resolve dispute favouring buyer → refund queued | open dispute | POST resolve_dispute | Memihak Pembeli | settlement queued, seller trust ↓ | Critical | MANUAL (verified: scratch/test_dispute_trust_effect.php) |
| UAT-PENTADBIR-102 | Refunds | Refund tab renders rows + filters (Semua/Diminta/…/Gagal) | has refunds | open tab, click filters | — | table + badges render, no console err | High | MANUAL (verified live; regression: scratch/test_refund_tab.php) |
| UAT-PENTADBIR-103 | Refunds | Retry a failed refund from the tab | failed refund | POST retry_refund | real payment_id | status advances | High | MANUAL |
| UAT-PENTADBIR-104 | Users | Toggle user suspend/active | target user | POST toggle_user_status | — | status flips, login affected | Medium | MANUAL |
| UAT-PENTADBIR-105 | Transactions | Search / paginate transactions | has txns | GET get_transactions | query/page | results + pagination | Medium | MANUAL |
| UAT-PENTADBIR-106 | Returns | Return-shipment management page loads data | pending returns | GET admin_returns.php?action=get_pending_returns | — | returns[] | High | PASS (fixed this cycle; regression: scratch/test_admin_returns_fix.php) |
| UAT-PENTADBIR-107 | AI risk | AI hold toggle on a risky transaction | AI-flagged txn | POST ai_set_hold | — | hold applied | Low | MANUAL |

Note: UAT-PENTADBIR-102..103 cover the admin **Pulangan Wang (refund) tab**, whose
frontend was missing and has been (re)implemented in `dashboard/admin.php` this
cycle; the automated suite locks the backend contract (030..032, 042..043).
