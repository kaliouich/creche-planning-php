<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

$pdo = get_db();
$stmt = $pdo->query("SELECT parent_id, parent2_id FROM children LIMIT 1");
$row = $stmt->fetch();

$p1 = $row['parent_id'] ?: 'none';
$p2 = $row['parent2_id'] ?: 'none';

$stmtC = $pdo->prepare("SELECT first_name FROM children WHERE parent_id = ? OR parent2_id = ? OR parent_id = ? OR parent2_id = ?");
$stmtC->execute([$p1, $p1, $p2, $p2]);
$childrenNames = array_unique($stmtC->fetchAll(PDO::FETCH_COLUMN));
$childrenStr = !empty($childrenNames) ? implode(' & ', array_map('ucfirst', $childrenNames)) : 'Enfant';

$stmtP = $pdo->prepare("SELECT first_name FROM users WHERE id IN (?, ?)");
$stmtP->execute([$p1, $p2]);
$parentsNames = array_unique($stmtP->fetchAll(PDO::FETCH_COLUMN));
$parentsStr = !empty($parentsNames) ? implode(' & ', array_map('ucfirst', $parentsNames)) : 'Parent';

echo "Result: {$childrenStr} ({$parentsStr})\n";
