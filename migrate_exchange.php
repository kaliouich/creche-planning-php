<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$pdo = get_db();
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS exchange_offers (
            id VARCHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci PRIMARY KEY,
            assignment_id VARCHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
            status VARCHAR(20) DEFAULT 'PENDING',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (assignment_id) REFERENCES assignments(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "Created exchange_offers\n";
} catch (Exception $e) { echo $e->getMessage() . "\n"; }

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS exchange_proposals (
            id VARCHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci PRIMARY KEY,
            exchange_offer_id VARCHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
            proposed_by_child_id VARCHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
            offered_assignment_id VARCHAR(36) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
            status VARCHAR(20) DEFAULT 'PENDING',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (exchange_offer_id) REFERENCES exchange_offers(id) ON DELETE CASCADE,
            FOREIGN KEY (proposed_by_child_id) REFERENCES children(id) ON DELETE CASCADE,
            FOREIGN KEY (offered_assignment_id) REFERENCES assignments(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ");
    echo "Created exchange_proposals\n";
} catch (Exception $e) { echo $e->getMessage() . "\n"; }
