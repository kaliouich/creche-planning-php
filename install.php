<?php
/**
 * Script d'installation — Création des tables MySQL + données initiales.
 * 
 * USAGE : Accédez à cette page UNE SEULE FOIS via votre navigateur :
 *   https://votredomaine.fr/api/install.php
 * 
 * ⚠️ SUPPRIMEZ CE FICHIER APRÈS L'INSTALLATION EN PRODUCTION !
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

header('Content-Type: text/html; charset=utf-8');
echo "<h1>🏗️ Installation Crèche Planning</h1><pre>";

try {
    $pdo = get_db();

    // ─── Création des tables ─────────────────────────────────────

    echo "📦 Création des tables...\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id VARCHAR(36) PRIMARY KEY,
            email VARCHAR(255) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            role ENUM('ADMIN', 'PROFESSIONAL', 'PARENT') NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_users_role (role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ✅ Table 'users' créée\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS children (
            id VARCHAR(36) PRIMARY KEY,
            first_name VARCHAR(100) NOT NULL,
            last_name VARCHAR(100) NOT NULL,
            parent_id VARCHAR(36) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            age_group ENUM('PETIT', 'GRAND') NOT NULL DEFAULT 'GRAND',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_children_parent (parent_id),
            FOREIGN KEY (parent_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ✅ Table 'children' créée\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS child_default_presences (
            id VARCHAR(36) PRIMARY KEY,
            child_id VARCHAR(36) NOT NULL,
            day_of_week ENUM('MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY') NOT NULL,
            half_day ENUM('MORNING', 'AFTERNOON') NOT NULL,
            UNIQUE KEY uk_child_day_half (child_id, day_of_week, half_day),
            FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ✅ Table 'child_default_presences' créée\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS planning_weeks (
            id VARCHAR(36) PRIMARY KEY,
            week_number INT NOT NULL,
            year INT NOT NULL,
            status ENUM('PREPARATION', 'OPEN_TO_PARENTS', 'CALCULATION', 'PUBLISHED') NOT NULL DEFAULT 'PREPARATION',
            needs_recalculation TINYINT(1) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_week_year (week_number, year),
            INDEX idx_weeks_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ✅ Table 'planning_weeks' créée\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS slots (
            id VARCHAR(36) PRIMARY KEY,
            planning_week_id VARCHAR(36) NOT NULL,
            day_of_week ENUM('MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY') NOT NULL,
            half_day ENUM('MORNING', 'AFTERNOON') NOT NULL,
            slot_type ENUM('OPEN', 'DOUBLE_PERM', 'CLOSED') NOT NULL DEFAULT 'OPEN',
            required_parents INT NOT NULL DEFAULT 1,
            UNIQUE KEY uk_slot_week_day_half (planning_week_id, day_of_week, half_day),
            INDEX idx_slots_week (planning_week_id),
            FOREIGN KEY (planning_week_id) REFERENCES planning_weeks(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ✅ Table 'slots' créée\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS child_presences (
            id VARCHAR(36) PRIMARY KEY,
            child_id VARCHAR(36) NOT NULL,
            slot_id VARCHAR(36) NOT NULL,
            is_present TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY uk_child_slot_presence (child_id, slot_id),
            INDEX idx_presences_slot (slot_id),
            FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE,
            FOREIGN KEY (slot_id) REFERENCES slots(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ✅ Table 'child_presences' créée\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS availabilities (
            id VARCHAR(36) PRIMARY KEY,
            child_id VARCHAR(36) NOT NULL,
            slot_id VARCHAR(36) NOT NULL,
            is_available TINYINT(1) NOT NULL,
            submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_child_slot_avail (child_id, slot_id),
            INDEX idx_avail_slot (slot_id),
            INDEX idx_avail_child (child_id),
            FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE,
            FOREIGN KEY (slot_id) REFERENCES slots(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ✅ Table 'availabilities' créée\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS assignments (
            id VARCHAR(36) PRIMARY KEY,
            child_id VARCHAR(36) NOT NULL,
            slot_id VARCHAR(36) NOT NULL,
            is_manual TINYINT(1) NOT NULL DEFAULT 0,
            assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_child_slot_assign (child_id, slot_id),
            INDEX idx_assign_slot (slot_id),
            INDEX idx_assign_child (child_id),
            FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE,
            FOREIGN KEY (slot_id) REFERENCES slots(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ✅ Table 'assignments' créée\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS professional_schedules (
            id VARCHAR(36) PRIMARY KEY,
            professional_id VARCHAR(36) NOT NULL,
            planning_week_id VARCHAR(36) NOT NULL,
            day_of_week ENUM('MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY') NOT NULL,
            half_day ENUM('MORNING', 'AFTERNOON') NOT NULL,
            is_working TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY uk_pro_week_day_half (professional_id, planning_week_id, day_of_week, half_day),
            INDEX idx_pro_sched_week (planning_week_id),
            FOREIGN KEY (professional_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (planning_week_id) REFERENCES planning_weeks(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ✅ Table 'professional_schedules' créée\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS score_histories (
            id VARCHAR(36) PRIMARY KEY,
            child_id VARCHAR(36) NOT NULL,
            week_number INT NOT NULL,
            year INT NOT NULL,
            score_before DOUBLE NOT NULL,
            permanences_done INT NOT NULL,
            permanences_due DOUBLE NOT NULL,
            score_after DOUBLE NOT NULL,
            snapshot_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_score_child_week_year (child_id, week_number, year),
            INDEX idx_score_child (child_id),
            INDEX idx_score_year_week (year, week_number),
            FOREIGN KEY (child_id) REFERENCES children(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "  ✅ Table 'score_histories' créée\n";

    // ─── Seed : Données initiales ────────────────────────────────

    echo "\n🌱 Création des comptes initiaux...\n";

    $now = date('Y-m-d H:i:s');

    // Admin
    $adminId = generate_uuid();
    $adminHash = password_hash('password123', PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("INSERT IGNORE INTO users (id, email, password_hash, first_name, last_name, role, created_at, updated_at) VALUES (?, 'admin@creche.fr', ?, 'Admin', 'Coordinateur', 'ADMIN', ?, ?)");
    $stmt->execute([$adminId, $adminHash, $now, $now]);
    echo "  ✅ Admin créé : admin@creche.fr / password123\n";

    // Parent test
    $parentId = generate_uuid();
    $parentHash = password_hash('password123', PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("INSERT IGNORE INTO users (id, email, password_hash, first_name, last_name, role, created_at, updated_at) VALUES (?, 'parent@creche.fr', ?, 'Parent', '', 'PARENT', ?, ?)");
    $stmt->execute([$parentId, $parentHash, $now, $now]);
    echo "  ✅ Parent test créé : parent@creche.fr / password123\n";

    echo "\n✅ Installation terminée avec succès !\n";
    echo "\n⚠️  SUPPRIMEZ CE FICHIER (install.php) EN PRODUCTION !\n";

} catch (Exception $e) {
    echo "\n❌ ERREUR : " . htmlspecialchars($e->getMessage()) . "\n";
}

echo "</pre>";
