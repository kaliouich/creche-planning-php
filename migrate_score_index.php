<?php
require_once __DIR__ . '/db.php';
$pdo = get_db();

try {
    $pdo->exec("CREATE INDEX idx_score_histories_child_snapshot ON score_histories (child_id, snapshot_at DESC);");
    echo "Index créé avec succès.\n";
} catch (\PDOException $e) {
    echo "Info: " . $e->getMessage() . "\n";
}
