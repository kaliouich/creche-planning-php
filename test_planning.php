<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
$pdo = get_db();
$stmt = $pdo->query("SELECT id FROM planning_weeks LIMIT 1");
$weekId = $stmt->fetchColumn();

$slotStmt = $pdo->prepare('SELECT id FROM slots WHERE planning_week_id = ?');
$slotStmt->execute([$weekId]);
$slots = $slotStmt->fetchAll();
$slotIds = array_column($slots, 'id');
$placeholders = implode(',', array_fill(0, count($slotIds), '?'));

$availStmt = $pdo->prepare("SELECT a.*, c.id as c_id, c.first_name as c_first_name, c.last_name as c_last_name FROM availabilities a JOIN children c ON a.child_id = c.id WHERE a.slot_id IN ($placeholders)");
$availStmt->execute($slotIds);
$allAvails = $availStmt->fetchAll(PDO::FETCH_ASSOC);

echo "Avails: " . count($allAvails) . "\n";
print_r($allAvails);
