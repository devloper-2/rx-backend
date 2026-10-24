-- 005_followup_actions.sql
-- Remembers what a doctor snoozed or dismissed on the "Needs attention" list.
-- kind: followup | refill | missed | lab_pending | imaging_pending ; reference_id: the prescription / appointment / order id.
-- until NULL = dismissed for good, otherwise snoozed until that UTC time.

CREATE TABLE IF NOT EXISTS `followup_actions` (
  `id`           bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `doctor_id`    bigint(20) unsigned NOT NULL,
  `kind`         enum('followup','refill','missed','lab_pending','imaging_pending') NOT NULL,
  `reference_id` bigint(20) unsigned NOT NULL,
  `until`        datetime DEFAULT NULL,
  `created_at`   datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_followup_action` (`doctor_id`,`kind`,`reference_id`),
  CONSTRAINT `fk_fua_doctor` FOREIGN KEY (`doctor_id`) REFERENCES `doctors` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
