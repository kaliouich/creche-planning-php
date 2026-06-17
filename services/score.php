<?php
/**
 * Service de calcul des scores de permanence.
 * Port exact de score.service.ts.
 */

/**
 * Calcule la dette théorique de chaque enfant actif pour une semaine.
 * Formule : totalPlacesRequises / nombreEnfantsActifs
 */
function calculate_theoretical_dues(string $weekId): array {
    $pdo = get_db();

    // Récupérer les slots de la semaine
    $stmt = $pdo->prepare('SELECT id, required_parents FROM slots WHERE planning_week_id = ?');
    $stmt->execute([$weekId]);
    $weekSlots = $stmt->fetchAll();

    if (empty($weekSlots)) return [];

    // Récupérer les enfants présents (via child_presences)
    $slotIds = array_column($weekSlots, 'id');
    $ph = implode(',', array_fill(0, count($slotIds), '?'));

    $presStmt = $pdo->prepare("SELECT DISTINCT child_id FROM child_presences WHERE slot_id IN ($ph) AND is_present = 1");
    $presStmt->execute($slotIds);
    $activeChildIds = $presStmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($activeChildIds)) return [];

    // Calculer la charge totale
    $totalRequired = array_reduce($weekSlots, fn($acc, $s) => $acc + (int)$s['required_parents'], 0);

    // Calculer la part équitable
    $fairShare = $totalRequired / count($activeChildIds);

    $dues = [];
    foreach ($activeChildIds as $childId) {
        $dues[$childId] = $fairShare;
    }

    return $dues;
}

/**
 * Calcule le score courant d'un enfant.
 * Score = dernierScorePublié + permanencesFaitesCetteSemaine - detteCetteSemaine
 */
function get_current_score(string $childId, ?string $currentWeekId = null): float {
    $pdo = get_db();

    // Récupérer le dernier score publié
    $stmt = $pdo->prepare('SELECT score_after FROM score_histories WHERE child_id = ? ORDER BY snapshot_at DESC LIMIT 1');
    $stmt->execute([$childId]);
    $last = $stmt->fetch();
    $baseScore = $last ? (float) $last['score_after'] : 0.0;

    if (!$currentWeekId) return $baseScore;

    // Ajuster avec les données de la semaine en cours
    $dues = calculate_theoretical_dues($currentWeekId);
    $dueThisWeek = $dues[$childId] ?? 0.0;

    // Compter les assignations
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM assignments a JOIN slots s ON a.slot_id = s.id WHERE a.child_id = ? AND s.planning_week_id = ?');
    $stmt->execute([$childId, $currentWeekId]);
    $assignmentsThisWeek = (int) $stmt->fetchColumn();

    return $baseScore + $assignmentsThisWeek - $dueThisWeek;
}

/**
 * Fige les scores lors de la publication d'une semaine.
 */
function snapshot_scores_for_week(string $weekId, int $weekNumber, int $year): void {
    $pdo = get_db();

    $dues = calculate_theoretical_dues($weekId);

    // Récupérer tous les enfants
    $childStmt = $pdo->query('SELECT id FROM children');
    $allChildren = $childStmt->fetchAll(PDO::FETCH_COLUMN);

    $insertStmt = $pdo->prepare('INSERT INTO score_histories (id, child_id, week_number, year, score_before, permanences_done, permanences_due, score_after, snapshot_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');

    $now = date('Y-m-d H:i:s');

    foreach ($allChildren as $childId) {
        $baseScore = get_current_score($childId); // Score avant cette semaine

        // Compter les assignations cette semaine
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM assignments a JOIN slots s ON a.slot_id = s.id WHERE a.child_id = ? AND s.planning_week_id = ?');
        $stmt->execute([$childId, $weekId]);
        $assignmentsThisWeek = (int) $stmt->fetchColumn();

        $dueThisWeek = $dues[$childId] ?? 0.0;
        $newScore = $baseScore + $assignmentsThisWeek - $dueThisWeek;

        $insertStmt->execute([
            generate_uuid(),
            $childId,
            $weekNumber,
            $year,
            $baseScore,
            $assignmentsThisWeek,
            $dueThisWeek,
            $newScore,
            $now,
        ]);
    }
}
