# UAT — PENJUAL (Seller)

Role summary: manages own product listings (add/edit/delete), views own orders,
confirms cash payments, updates fulfilment status, may also act as a buyer.
Must NOT reach any admin surface/data. Cannot edit/delete another seller's
product or order their own listing.

Automated spec: `tests/uat/penjual/uat_penjual.php` → **23 PASS / 0 FAIL**.
Subject: `haziq@student.uthm.edu.my` (user 2, verified, owns product 1).
Foreign target: product 12 (owned by seller `mirza`, user 16).

| Test ID | Module | Scenario | Preconditions | Steps | Test Data | Expected Result | Priority | Status |
|---|---|---|---|---|---|---|---|---|
| UAT-PENJUAL-001 | Auth | Seller login | active penjual | POST login | haziq@…/password123 | session role=penjual | High | AUTO / PASS |
| UAT-PENJUAL-010 | Dashboard | Seller dashboard reachable | logged in | GET seller.php | — | 200 | High | AUTO / PASS |
| UAT-PENJUAL-011 | Access control | Seller MAY open buyer dashboard (dual-role by design) | logged in | GET buyer.php | — | 200 | Medium | AUTO / PASS |
| UAT-PENJUAL-020 | Access control | Cannot open admin dashboard (direct URL) | logged in | GET admin.php | — | 403 "Akses Ditolak" | Critical | AUTO / PASS |
| UAT-PENJUAL-030..033 | Access control | Admin read APIs blocked | logged in | GET admin.php?action=… | get_users/refunds/ai_risks/analytics | 403 JSON each | Critical | AUTO / PASS |
| UAT-PENJUAL-034 | Access control | Admin POST API blocked (verify_seller) | logged in | POST verify_seller | user_id=4 approve | 403 | Critical | AUTO / PASS |
| UAT-PENJUAL-040..042 | Catalogue/orders | Seller read endpoints ok | logged in | GET seller.php?action=… | get_products/orders/stats | 200 success each | High | AUTO / PASS |
| UAT-PENJUAL-050 | Products | add_product creates a listing | verified seller | POST add_product | name/category/price/stock | success + product_id | High | AUTO / PASS |
| UAT-PENJUAL-051 | Products | edit_product updates own listing | created above | POST edit_product | product_id (own) | success | High | AUTO / PASS |
| UAT-PENJUAL-052 | Products | Edit persisted to DB | created above | SELECT after edit | name='UAT Edited Nama' | DB row updated | High | AUTO / PASS |
| UAT-PENJUAL-053 | Products | delete_product removes own listing | created above | POST delete_product | product_id (own) | success | High | AUTO / PASS |
| UAT-PENJUAL-054 | Products | Delete is a soft-delete (status=Dipadam) | created above | SELECT status | — | status='Dipadam' | Medium | AUTO / PASS |
| UAT-PENJUAL-060 | Validation | add_product rejects invalid input | logged in | POST add_product | empty name / bad cat / price 0 / stock -5 | 422 + errors[] | High | AUTO / PASS |
| UAT-PENJUAL-061 | Ownership | edit_product on FOREIGN product rejected | logged in | POST edit_product | product_id=12 (mirza) | success=false | Critical | AUTO / PASS |
| UAT-PENJUAL-062 | Ownership | delete_product on FOREIGN product rejected | logged in | POST delete_product | product_id=12 (mirza) | success=false | Critical | AUTO / PASS |
| UAT-PENJUAL-063 | Ownership | Foreign product NOT modified by attack | — | SELECT product 12 | — | still present (count=1) | Critical | AUTO / PASS |
| UAT-PENJUAL-070 | Orders | Seller cannot order own product (self-exclusion) | owns product 1 | POST make_order | product_id=1 | success=false | High | AUTO / PASS |
| UAT-PENJUAL-080 | Security | POST without CSRF rejected | logged in | POST add_product (no token) | — | 403 | Critical | AUTO / PASS |
| UAT-PENJUAL-100 | Orders | Confirm cash payment (own order) | pending cash order | POST confirm_cash_payment | — | txn unpaid_cash→paid_cash | High | MANUAL |
| UAT-PENJUAL-101 | Orders | Update fulfilment status (own order) | own order | POST update_order_status | shipped/done | status progresses | High | MANUAL |
| UAT-PENJUAL-102 | Products | Image upload on add/edit | verified seller | POST multipart | file | image stored, thumb renders | Medium | MANUAL |
| UAT-PENJUAL-103 | Verification | Unverified seller blocked from add_product | seller != Disahkan | POST add_product | — | rejected until Disahkan | High | MANUAL* |
| UAT-PENJUAL-104 | Chat | Reply to buyer message | logged in | chat_api send_message | text | message stored | Medium | MANUAL |
| UAT-PENJUAL-105 | Trust | Seller trust score shown/derived on dashboard | has history | view seller dashboard | — | score/components render | Medium | MANUAL |

`*` UAT-PENJUAL-103 needs a `Menunggu`/unverified seller; the automated subject
(haziq) is already `Disahkan`, so the gate is checked manually or via
`scratch` fixtures.
