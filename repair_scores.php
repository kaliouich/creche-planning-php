<?php
/**
 * Recalcul complet des scores de permanence.
 * POST uniquement, réservé aux administrateurs.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/services/score.php';
require_once __DIR__ . '/auth.php';

// Security: POST-only, authenticated admin, CSRF-verified
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Méthode non autorisée. Utilisez POST.'], 405);
    exit;
}

$user = require_auth();
verify_csrf();
require_role($user, 'ADMIN');

$pdo = get_db();

// 1. Find all published weeks
$stmt = $pdo->query("SELECT id, week_number, year FROM planning_weeks WHERE status = 'PUBLISHED' ORDER BY year ASC, week_number ASC");
$weeks = $stmt->fetchAll();

foreach ($weeks as $week) {
    // Calculate correct dues
    $dues = calculate_theoretical_dues($week['id']);
    
    // Update score_histories for this week
    foreach ($dues as $childId => $due) {
        $upd = $pdo->prepare("UPDATE score_histories SET permanences_due = ? WHERE child_id = ? AND week_number = ? AND year = ?");
        $upd->execute([$due, $childId, $week['week_number'], $week['year']]);
    }
}

// 1.5 Nettoyer les historiques orphelins
$pdo->exec("
    DELETE sh FROM score_histories sh
    JOIN planning_weeks pw ON sh.week_number = pw.week_number AND sh.year = pw.year
    WHERE pw.status != 'PUBLISHED'
");

// 2. Recalculate all histories
$childStmt = $pdo->query('SELECT id FROM children');
$children = $childStmt->fetchAll(PDO::FETCH_COLUMN);

foreach ($children as $childId) {
    recalculate_child_score_history($childId);
}

json_response(["success" => true, "message" => "DB repair complete!"]);
