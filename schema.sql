-- ============================================================
-- Crèche Planning — Schéma MySQL
-- Version: 1.0.0
-- Date: 2026-06-24
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- ─── Users ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `users` (
  `id` VARCHAR(36) NOT NULL,
  `email` VARCHAR(255) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `first_name` VARCHAR(100) NOT NULL DEFAULT 'Nouveau',
  `last_name` VARCHAR(100) NOT NULL DEFAULT 'Utilisateur',
  `role` ENUM('ADMIN', 'PROFESSIONAL', 'PARENT') NOT NULL DEFAULT 'PARENT',
  `second_email` VARCHAR(255) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_users_email` (`email`),
  KEY `idx_users_role` (`role`),
  KEY `idx_users_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Children ───────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `children` (
  `id` VARCHAR(36) NOT NULL,
  `first_name` VARCHAR(100) NOT NULL,
  `last_name` VARCHAR(100) NOT NULL,
  `parent_id` VARCHAR(36) NOT NULL,
  `parent2_id` VARCHAR(36) DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `age_group` ENUM('PETIT', 'GRAND') NOT NULL DEFAULT 'GRAND',
  `parent1_first_name` VARCHAR(100) DEFAULT 'Famille',
  `parent2_first_name` VARCHAR(100) DEFAULT NULL,
  `parent1_email` VARCHAR(255) DEFAULT NULL,
  `parent2_email` VARCHAR(255) DEFAULT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_children_parent_id` (`parent_id`),
  KEY `idx_children_parent2_id` (`parent2_id`),
  KEY `idx_children_is_active` (`is_active`),
  KEY `idx_children_age_group` (`age_group`),
  CONSTRAINT `fk_children_parent` FOREIGN KEY (`parent_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_children_parent2` FOREIGN KEY (`parent2_id`) REFERENCES `users` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Child Default Presences ────────────────────────────────
CREATE TABLE IF NOT EXISTS `child_default_presences` (
  `id` VARCHAR(36) NOT NULL,
  `child_id` VARCHAR(36) NOT NULL,
  `day_of_week` ENUM('MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY') NOT NULL,
  `half_day` ENUM('MORNING', 'AFTERNOON') NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_cdp_child_id` (`child_id`),
  UNIQUE KEY `idx_cdp_unique` (`child_id`, `day_of_week`, `half_day`),
  CONSTRAINT `fk_cdp_child` FOREIGN KEY (`child_id`) REFERENCES `children` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Child Absences ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `child_absences` (
  `id` VARCHAR(36) NOT NULL,
  `child_id` VARCHAR(36) NOT NULL,
  `start_date` DATE NOT NULL,
  `start_half_day` ENUM('ALL', 'MORNING', 'AFTERNOON') NOT NULL DEFAULT 'ALL',
  `end_date` DATE DEFAULT NULL,
  `end_half_day` ENUM('ALL', 'MORNING', 'AFTERNOON') NOT NULL DEFAULT 'ALL',
  `is_conge` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_abs_child` (`child_id`),
  KEY `idx_abs_dates` (`start_date`, `end_date`),
  CONSTRAINT `fk_abs_child` FOREIGN KEY (`child_id`) REFERENCES `children` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Planning Weeks ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `planning_weeks` (
  `id` VARCHAR(36) NOT NULL,
  `week_number` INT NOT NULL,
  `year` INT NOT NULL,
  `status` ENUM('PREPARATION', 'OPEN_TO_PARENTS', 'PUBLISHED') NOT NULL DEFAULT 'PREPARATION',
  `needs_recalculation` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_pw_week_year` (`week_number`, `year`),
  KEY `idx_pw_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Slots ──────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `slots` (
  `id` VARCHAR(36) NOT NULL,
  `planning_week_id` VARCHAR(36) NOT NULL,
  `day_of_week` ENUM('MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY') NOT NULL,
  `half_day` ENUM('MORNING', 'AFTERNOON') NOT NULL,
  `slot_type` ENUM('OPEN', 'DOUBLE_PERM', 'CLOSED', 'NO_PERM') NOT NULL DEFAULT 'OPEN',
  `required_parents` INT NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  KEY `idx_slots_week_id` (`planning_week_id`),
  UNIQUE KEY `idx_slots_unique` (`planning_week_id`, `day_of_week`, `half_day`),
  CONSTRAINT `fk_slots_week` FOREIGN KEY (`planning_week_id`) REFERENCES `planning_weeks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Availabilities ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `availabilities` (
  `id` VARCHAR(36) NOT NULL,
  `child_id` VARCHAR(36) NOT NULL,
  `slot_id` VARCHAR(36) NOT NULL,
  `is_available` TINYINT(1) NOT NULL DEFAULT 0,
  `submitted_at` DATETIME DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_avail_unique` (`child_id`, `slot_id`),
  KEY `idx_avail_slot_id` (`slot_id`),
  CONSTRAINT `fk_avail_child` FOREIGN KEY (`child_id`) REFERENCES `children` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_avail_slot` FOREIGN KEY (`slot_id`) REFERENCES `slots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Child Presences ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `child_presences` (
  `id` VARCHAR(36) NOT NULL,
  `child_id` VARCHAR(36) NOT NULL,
  `slot_id` VARCHAR(36) NOT NULL,
  `is_present` TINYINT(1) NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_cp_unique` (`child_id`, `slot_id`),
  KEY `idx_cp_slot_id` (`slot_id`),
  CONSTRAINT `fk_cp_child` FOREIGN KEY (`child_id`) REFERENCES `children` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cp_slot` FOREIGN KEY (`slot_id`) REFERENCES `slots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Password Resets ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `password_resets` (
  `email` VARCHAR(255) NOT NULL,
  `token` VARCHAR(64) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_password_resets_email` (`email`),
  KEY `idx_password_resets_token` (`token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Assignments ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `assignments` (
  `id` VARCHAR(36) NOT NULL,
  `child_id` VARCHAR(36) NOT NULL,
  `slot_id` VARCHAR(36) NOT NULL,
  `is_manual` TINYINT(1) NOT NULL DEFAULT 0,
  `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_assign_unique` (`child_id`, `slot_id`),
  KEY `idx_assign_slot_id` (`slot_id`),
  KEY `idx_assign_is_manual` (`is_manual`),
  CONSTRAINT `fk_assign_child` FOREIGN KEY (`child_id`) REFERENCES `children` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_assign_slot` FOREIGN KEY (`slot_id`) REFERENCES `slots` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── Score Histories ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `score_histories` (
  `id` VARCHAR(36) NOT NULL,
  `child_id` VARCHAR(36) NOT NULL,
  `week_number` INT NOT NULL,
  `year` INT NOT NULL,
  `score_before` DECIMAL(10,4) NOT NULL DEFAULT 0,
  `permanences_done` INT NOT NULL DEFAULT 0,
  `permanences_due` DECIMAL(10,4) NOT NULL DEFAULT 0,
  `score_after` DECIMAL(10,4) NOT NULL DEFAULT 0,
  `snapshot_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_sh_child_week` (`child_id`, `week_number`, `year`),
  KEY `idx_sh_snapshot` (`child_id`, `snapshot_at`),
  CONSTRAINT `fk_sh_child` FOREIGN KEY (`child_id`) REFERENCES `children` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
