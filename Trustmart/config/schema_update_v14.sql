-- ============================================================
-- schema_update_v14.sql
-- Staff (non-student) accounts — supervisor consultation suggestion
--
-- Problem: registration already accepts a staff mailbox (@uthm.edu.my) as
-- well as a student one (@student.uthm.edu.my), but the form then demands a
-- matric number and the whole UI says "Pasaran Jual Beli Pelajar". A UTHM
-- staff member therefore had to register as if they were a student, storing a
-- staff number in a column named matric_no. This adds an explicit account type
-- and a proper identifier field.
--
-- Scope, deliberately: this is an ACCOUNT TYPE, not a fourth role. Staff buy
-- and sell exactly like students; pembeli / penjual / pentadbir are untouched.
-- Staff status is proven by the @uthm.edu.my domain at registration, so no PTU
-- verification step is added and is_verified keeps its existing meaning
-- (seller verification only).
--
-- Fully idempotent: safe to run multiple times.
-- ============================================================

-- 1. Account type. Existing rows all carry a matric number, so every account
--    in the database today is a student; the default expresses that.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS user_type ENUM('pelajar','staf') NOT NULL DEFAULT 'pelajar'
    COMMENT 'Who the registrant claims to be; enforced against the email domain at signup'
    AFTER role;

-- 2. Staff identifier, kept SEPARATE from matric_no so the existing column
--    keeps meaning a matric number. Multiple NULLs are allowed by the unique
--    index, so students (who have no staff number) do not collide.
ALTER TABLE users
    ADD COLUMN IF NOT EXISTS staff_no VARCHAR(30) DEFAULT NULL
    COMMENT 'No. pegawai for a staff account; NULL for students'
    AFTER matric_no;

-- 3. A staff account has no matric number, so the column must become nullable.
--    Every current row has a value, so no data is at risk in this conversion.
ALTER TABLE users
    MODIFY COLUMN matric_no VARCHAR(20) DEFAULT NULL;

-- 4. Indexes (guarded: MariaDB has no ADD UNIQUE INDEX IF NOT EXISTS).
SET @idx := (SELECT IF(COUNT(*) = 0,
    'CREATE UNIQUE INDEX uq_users_staff_no ON users (staff_no)',
    'DO 0')
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
   AND INDEX_NAME = 'uq_users_staff_no');
PREPARE stmt FROM @idx; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx2 := (SELECT IF(COUNT(*) = 0,
    'CREATE INDEX idx_users_user_type ON users (user_type)',
    'DO 0')
  FROM information_schema.STATISTICS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
   AND INDEX_NAME = 'idx_users_user_type');
PREPARE stmt FROM @idx2; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5. Backfill: rows holding a staff identifier on a staff mailbox are labelled
--    honestly and their number moved to the column that describes it. This
--    deliberately INCLUDES the pentadbir account: PTU is a centre, not a
--    faculty, and its admin login already stores 'PTU-001' in matric_no —
--    calling that a matric number would be the same lie this migration removes.
--    Bounded on purpose: staff domains only, and only where staff_no is empty.
UPDATE users
   SET user_type = 'staf',
       staff_no  = matric_no,
       matric_no = NULL
 WHERE email NOT LIKE '%@student.uthm.edu.my'
   AND email LIKE '%@uthm.edu.my'
   AND user_type = 'pelajar'
   AND staff_no IS NULL
   AND matric_no IS NOT NULL
   AND matric_no <> '';

-- NOTE: no CHECK (matric_no IS NOT NULL OR staff_no IS NOT NULL) constraint is
-- added. The rule is enforced in auth/register.php. A table-level constraint
-- would also reject the INSERTs that the scratch/ test harnesses use to craft
-- throwaway accounts, and a guard failing on the demo machine is worse than a
-- constraint nobody can reach through the application.

-- 6. Sanity check after applying:
--    SELECT user_type, COUNT(*) FROM users GROUP BY user_type;
--    SELECT user_id, email, matric_no, staff_no FROM users WHERE user_type='staf';
