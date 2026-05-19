-- ============================================================
-- Migration: Add Email OTP columns to users + rename to Patient Information System
-- Run once against an existing database:
--   mysql -u root -p pediatric_clinic < database/migration_email_otp.sql
-- ============================================================

USE `pediatric_clinic`;

-- 1) Email OTP columns on users
ALTER TABLE `users`
    ADD COLUMN IF NOT EXISTS `email_otp_code` VARCHAR(10) DEFAULT NULL AFTER `email_verification_token`,
    ADD COLUMN IF NOT EXISTS `email_otp_expires` TIMESTAMP NULL DEFAULT NULL AFTER `email_otp_code`,
    ADD COLUMN IF NOT EXISTS `email_otp_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `email_otp_expires`;

-- 2) Ensure patient_files.description exists (legacy installs used "notes")
ALTER TABLE `patient_files`
    ADD COLUMN IF NOT EXISTS `description` TEXT DEFAULT NULL;

-- Copy any legacy "notes" data into description, then drop legacy notes column if present
UPDATE `patient_files` SET `description` = `notes`
    WHERE `description` IS NULL AND `notes` IS NOT NULL;

-- 3) "Get In Touch" clinic settings defaults (used by landing page footer)
INSERT INTO `clinic_settings` (`setting_key`, `setting_value`, `setting_type`, `description`)
VALUES
    ('contact_address', 'Manila, Philippines', 'STRING', 'Footer address shown on the landing page'),
    ('contact_phone',   '+63 (2) 1234 5678',   'STRING', 'Footer phone shown on the landing page'),
    ('contact_email',   'thepeonyflower@alagapp.site', 'STRING', 'Footer email shown on the landing page'),
    ('contact_hours',   'Mon – Sat: 8:00 AM – 6:00 PM', 'STRING', 'Footer hours shown on the landing page'),
    ('smtp_host',       'smtp.hostinger.com',  'STRING', 'SMTP host used for OTP / transactional email'),
    ('smtp_port',       '465',                 'INTEGER','SMTP port (465 SSL, 587 TLS)'),
    ('smtp_username',   'thepeonyflower@alagapp.site', 'STRING', 'SMTP username (full mailbox)'),
    ('smtp_from_name',  'AlagApp Clinic',      'STRING', 'From-name shown on outbound email')
ON DUPLICATE KEY UPDATE `setting_key` = VALUES(`setting_key`);
