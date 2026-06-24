<?php
require_once __DIR__ . '/db.php';
$pdo = get_db();
try {
    $pdo->exec("ALTER TABLE children ADD COLUMN parent2_id VARCHAR(36) DEFAULT NULL");
    echo "Added parent2_id\n";
} catch (Exception $e) {
    echo "parent2_id already exists or error: " . $e->getMessage() . "\n";
}
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS password_resets (email VARCHAR(255) NOT NULL, token VARCHAR(64) NOT NULL, expires_at DATETIME NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    echo "Created password_resets\n";
} catch (Exception $e) {
    echo "password_resets error: " . $e->getMessage() . "\n";
}
