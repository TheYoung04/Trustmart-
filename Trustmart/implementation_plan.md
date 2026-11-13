# UTHM TrustMart — SV Advice Implementation Plan

This plan details the design and changes to address the feedback and requirements provided by your supervisor (SV):
1. **Tambah trust dekat buyer** & **tambah trust score jugak untuk pembeli**: Create a trust score for buyers based on transaction completion rates and disputes.
2. **Perlukan activation link sebelum boleh buat sesuatu acc**: Implement a simulated email activation workflow for new accounts.
3. **Betulkan terima atau tolak di ptu admin**: Fix the seller verification flow in PTU Admin so rejected sellers are filtered out of the pending list and can be re-approved/managed properly.
4. **Kalau belum sah , tak perlu jual**: Ensure products of unverified sellers do not appear to buyers, and restrict unverified sellers from adding/activating listings.
5. **Perlu belajar and tunjuk algorithm score**: Create interactive explanation modals on dashboards breaking down the exact formulas and user statistics.
6. **Perlu start dari 0 untuk seller , perlu tambah ayat trust dekat dashboard**: Set brand new sellers' trust scores to 0.00, and show buyer trust scores and explanation text on the dashboards.

---

## User Review Required

Please review the proposed trust score calculation logic and verification flow behavior.

> [!IMPORTANT]
> **Simulated Email Activation:**
> To make it easy to demonstrate on `localhost` without setting up a real mail server, the registration success message will display a clickable activation link. When clicked, it opens a verification endpoint (`activate.php`) which marks the account active.
>
> **Seller Verification States:**
> A new column `verification_status` (`'Menunggu'`, `'Disahkan'`, `'Ditolak'`) will be added to the `users` table. This allows PTU admin to formally reject a seller and remove them from the pending verification list, while preventing them from listing products until approved.

---

## Open Questions

No outstanding blocking questions. The requirements map cleanly to database tables, triggers, and dashboard code blocks.

---

## Proposed Changes

### Database & Stored Procedures
We will run a SQL update script to alter the schema, update existing users, define the new trust score procedures, and register automatic triggers.

#### [NEW] [schema_update.sql](file:///c:/Users/USER/Documents/Faiq%20Things/Trustmart/config/schema_update.sql)
- Add columns to `users`:
  - `is_active` (`TINYINT(1) DEFAULT 0`)
  - `activation_token` (`VARCHAR(64) DEFAULT NULL`)
  - `verification_status` (`ENUM('Menunggu', 'Disahkan', 'Ditolak') DEFAULT 'Menunggu'`)
- Update existing users:
  - Set `is_active = 1` for existing users.
  - Set `verification_status = 'Disahkan'` where `is_verified = 1` and `verification_status = 'Menunggu'` where `is_verified = 0`.
- Update Stored Procedure `recalculate_trust_score(p_seller_id)`:
  - If a seller has 0 orders and 0 reviews, set `trust_score = 0.00`.
- Create Stored Procedure `recalculate_buyer_trust_score(p_buyer_id)`:
  - Completion Rate (max 60 points) = `(Completed / Total Canceled or Completed) * 100` (defaults to 100% if 0 orders).
  - Dispute Penalty (max 40 points) = `40 - (Disputes Resolved against Buyer * 10)` (max penalty -40).
- Register Triggers:
  - `after_transaction_insert`: updates seller and buyer trust scores when a new transaction is made.
  - `after_transaction_update`: updates seller and buyer trust scores when transaction status changes.
  - `after_dispute_update`: updates seller and buyer trust scores when a dispute is resolved.

---

### Authentication & Account Activation

#### [MODIFY] [register.php](file:///c:/Users/USER/Documents/Faiq%20Things/Trustmart/auth/register.php)
- Generate a random `activation_token` using `bin2hex(random_bytes(32))` on registration.
- Insert the new user with `is_active = 0` and the generated token.
- Return the activation URL in the JSON response as `activation_link`.

#### [MODIFY] [index.php](file:///c:/Users/USER/Documents/Faiq%20Things/Trustmart/public/index.php)
- Handle `activation_link` in the registration AJAX success handler.
- Display a clickable simulated activation link in the success toast/alert so the user can easily activate the account.

#### [NEW] [activate.php](file:///c:/Users/USER/Documents/Faiq%20Things/Trustmart/auth/activate.php)
- A public PHP page that reads the `token` parameter.
- Finds the corresponding user, updates `is_active = 1`, and clears the `activation_token`.
- Displays a premium, themed activation success screen with a button to log in.

#### [MODIFY] [login.php](file:///c:/Users/USER/Documents/Faiq%20Things/Trustmart/auth/login.php)
- Fetch `is_active` and `verification_status` in the user lookup query.
- If the user is found but `is_active = 0`, reject the login with the error: *"Akaun belum aktif. Sila klik pautan pengaktifan yang dihantar ke emel anda."*
- Store `verification_status` in `$_SESSION['user']`.

#### [MODIFY] [auth_check.php](file:///c:/Users/USER/Documents/Faiq%20Things/Trustmart/includes/auth_check.php)
- Update `refresh_session_user()` to query and populate `verification_status` in the session.

---

### PTU Admin Verification Panel

#### [MODIFY] [admin.php](file:///c:/Users/USER/Documents/Faiq%20Things/Trustmart/dashboard/admin.php)
- In case `verify_seller`:
  - If action is `'approve'`, set `verification_status = 'Disahkan'` and `is_verified = 1`.
  - If action is `'reject'`, set `verification_status = 'Ditolak'` and `is_verified = 0`.
- In case `get_sellers`:
  - Update the `'pending'` filter query to only fetch sellers with `verification_status = 'Menunggu'`.
- In frontend Javascript `loadSellers()`:
  - Dynamically render status badges (`badge-green` for Disahkan, `badge-gold` for Menunggu, `badge-red` for Ditolak).
  - Dynamically render action buttons:
    - If `Menunggu`: show **✓ Sahkan** (Approve) and **✕ Tolak** (Reject) buttons.
    - If `Disahkan`: show **Tarik Balik** (Reject/Withdraw) button.
    - If `Ditolak`: show **Sahkan Semula** (Approve) button.

---

### Unverified Seller Sales Restriction

#### [MODIFY] [buyer.php](file:///c:/Users/USER/Documents/Faiq%20Things/Trustmart/dashboard/buyer.php)
- In case `get_products` (explore section search) and the initial `$featured` products query, add `u.is_verified = 1` to filter out listings from unverified/rejected sellers.

#### [MODIFY] [product.php](file:///c:/Users/USER/Documents/Faiq%20Things/Trustmart/product.php)
- When showing the single product page and when placing an order, join with the `users` table and check if `u.is_verified = 1`. If not, redirect or return a JSON error: *"Penjual produk ini belum disahkan oleh PTU."*

#### [MODIFY] [seller.php](file:///c:/Users/USER/Documents/Faiq%20Things/Trustmart/dashboard/seller.php)
- If the logged-in seller has `verification_status` as `'Menunggu'` or `'Ditolak'`:
  - Display a prominent alert banner at the top of the dashboard.
  - In AJAX cases `add_product` and `edit_product`, check verification status. Block the operation and return a JSON error if they are not verified.
  - Hide the "+ Tambah Produk" button in the header if they are not verified.

---

### Trust Score Visualization & Explanation Modals

#### [MODIFY] [seller.php](file:///c:/Users/USER/Documents/Faiq%20Things/Trustmart/dashboard/seller.php) (Seller Dashboard)
- Add a click event or an "ⓘ" button next to the trust score display.
- Add an HTML modal explaining the seller's trust score algorithm in Malay.
- Pass the seller's current stats (average rating, completion rate, response speed, verification status, disputes) to the modal and dynamically calculate and explain how they build up to their current trust score.

#### [MODIFY] [buyer.php](file:///c:/Users/USER/Documents/Faiq%20Things/Trustmart/dashboard/buyer.php) (Buyer Dashboard)
- Query the buyer's trust score fresh from the database on page load.
- Render the buyer's trust score in the topbar (e.g. `Skor Kepercayaan: 100/100 ⓘ`).
- Add a click event to open a buyer trust score explanation modal.
- Display the buyer's stats (completed orders, canceled orders, disputes resolved against them) and how the score is calculated.

---

## Verification Plan

### Database & Procedures
- Run the `schema_update.sql` script.
- Verify procedures and triggers exist using `SHOW CREATE PROCEDURE` and `SHOW TRIGGERS`.

### Manual Verification Flows
1. **Registration & Activation:**
   - Register a new account (e.g., `test_new@student.uthm.edu.my`).
   - Try logging in immediately. Verify it blocks login and shows *"Akaun belum aktif"*.
   - Click the simulated activation link returned in the registration success message.
   - Verify `activate.php` activates the account and shows the success screen.
   - Log in again. Verify it succeeds.
2. **Unverified Seller Listing Block:**
   - Log in as a newly registered seller (which defaults to `verification_status = 'Menunggu'`).
   - Verify the unverified warning banner shows at the top.
   - Verify the "+ Tambah Produk" button is hidden.
   - Try to add a product via a mockup form submit. Verify the backend blocks it with a validation error.
3. **PTU Admin Verification & Re-approval:**
   - Log in as `admin@uthm.edu.my`. Go to **Pengesahan Penjual** tab.
   - Locate the new seller. Click **✕** (Tolak).
   - Verify they disappear from the pending list.
   - Filter by "Semua" or search for them. Verify their status is "Ditolak".
   - Click **✓ Sahkan** (or Sahkan Semula) to approve them. Verify their status changes to "Disahkan".
   - Log back into the seller account. Verify the banner is gone and they can now list products.
4. **Buyer Trust Score & Visibility:**
   - Log in as a buyer. Verify you can only see products of verified sellers (like `haziq` or the newly approved seller).
   - Verify the buyer trust score displays in the topbar.
   - Click the `ⓘ` next to the buyer and seller trust scores. Verify the mathematical breakdown modal renders correctly.
5. **Score Updates:**
   - Place an order, verify, complete, or cancel it, and verify that the trust scores of both buyer and seller update dynamically via the database triggers.
