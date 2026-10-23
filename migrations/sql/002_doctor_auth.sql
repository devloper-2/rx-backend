-- 002_doctor_auth.sql
-- Adds what the doctor OTP flow needs on top of php_rest_api.sql.
-- Safe to run more than once (uses IF NOT EXISTS / guarded changes).

ALTER TABLE `verification`
  ADD COLUMN IF NOT EXISTS `purpose`    ENUM('register','reset') NOT NULL DEFAULT 'register' AFTER `type`,
  ADD COLUMN IF NOT EXISTS `attempts`   TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `otp_expires`,
  ADD COLUMN IF NOT EXISTS `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP;

-- The dump defines `verification` without keys; lookups are by doctor + purpose.
ALTER TABLE `verification` ADD INDEX IF NOT EXISTS `idx_verification_doctor_purpose` (`doctor_id`, `purpose`);
