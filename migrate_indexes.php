<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

try {
    $pdo = get_db();
    
    $queries = [
        "CREATE INDEX idx_availabilities_slot ON availabilities(slot_id)",
        "CREATE INDEX idx_presences_slot ON child_presences(slot_id)",
        "CREATE INDEX idx_assignments_slot_child ON assignments(slot_id, child_id)"
    ];

    foreach ($queries as $sql) {
        try {
            $pdo->exec($sql);
            echo "SUCCESS: $sql\n";
        } catch (PDOException $e) {
            // Ignore if index already exists
            if (strpos($e->getMessage(), 'Duplicate key name') !== false) {
                echo "SKIPPED: Index already exists ($sql)\n";
            } else {
                echo "ERROR: " . $e->getMessage() . "\n";
            }
        }
    }
    
    echo "Migration completed.\n";
} catch (Exception $e) {
    die("Database connection failed: " . $e->getMessage() . "\n");
}
