<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once __DIR__ . '/helpers.php';

$pdo = get_db();

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS exchange_offers (
            id CHAR(36) PRIMARY KEY,
            assignment_id CHAR(36) NOT NULL,
            status ENUM('PENDING', 'COMPLETED', 'CANCELLED') NOT NULL DEFAULT 'PENDING',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS exchange_proposals (
            id CHAR(36) PRIMARY KEY,
            exchange_offer_id CHAR(36) NOT NULL,
            proposed_by_child_id CHAR(36) NOT NULL,
            offered_assignment_id CHAR(36) NULL,
            status ENUM('PENDING', 'ACCEPTED', 'REJECTED') NOT NULL DEFAULT 'PENDING',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (exchange_offer_id) REFERENCES exchange_offers(id) ON DELETE CASCADE,
            FOREIGN KEY (proposed_by_child_id) REFERENCES children(id) ON DELETE CASCADE,
            FOREIGN KEY (offered_assignment_id) REFERENCES assignments(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    
    // Add is_offered_for_exchange if not exists
    $stmt = $pdo->query("SHOW COLUMNS FROM assignments LIKE 'is_offered_for_exchange'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE assignments ADD COLUMN is_offered_for_exchange TINYINT(1) DEFAULT 0;");
    }

    echo "Migration OK!";
} catch (Exception $e) {
    echo "Erreur : " . $e->getMessage();
}
