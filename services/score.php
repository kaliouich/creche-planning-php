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

    // Récupérer tous les enfants actifs (le calcul est basé sur le contrat, pas sur les absences ponctuelles)
    $childStmt = $pdo->query('SELECT id FROM children WHERE is_active = 1');
    $activeChildIds = $childStmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($activeChildIds)) return [];

    // Calculer la charge totale
    $totalRequired = array_reduce($weekSlots, fn($acc, $s) => $acc + (int)$s['required_parents'], 0);

    // Récupérer le "poids" de chaque enfant actif basé sur son contrat (nombre de demi-journées dans child_default_presences)
    $cPh = implode(',', array_fill(0, count($activeChildIds), '?'));
    $weightStmt = $pdo->prepare("
        SELECT child_id, COUNT(*) as half_days 
        FROM child_default_presences 
        WHERE child_id IN ($cPh) 
        GROUP BY child_id
    ");
    $weightStmt->execute($activeChildIds);
    $weightsRaw = $weightStmt->fetchAll();
    
    $weights = [];
    $totalWeight = 0;
    foreach ($weightsRaw as $w) {
        $weight = (int) $w['half_days'];
        // Poids minimal de 1 demi-journée pour éviter les calculs faussés
        if ($weight === 0) $weight = 1; 
        $weights[$w['child_id']] = $weight;
    }
    
    // Compléter les enfants sans contrat par défaut avec un poids minimal de 1
    foreach ($activeChildIds as $cid) {
        if (!isset($weights[$cid])) {
            $weights[$cid] = 1;
        }
        $totalWeight += $weights[$cid];
    }

    $dues = [];
    if ($totalWeight > 0) {
        foreach ($activeChildIds as $childId) {
            // Part proportionnelle au nombre de demi-journées d'accueil
            $dues[$childId] = $totalRequired * ($weights[$childId] / $totalWeight);
        }
    } else {
        // Sécurité si totalWeight est 0 (impossible grâce au fallback mais par sécurité)
        foreach ($activeChildIds as $childId) {
            $dues[$childId] = $totalRequired / count($activeChildIds);
        }
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

    $insertStmt = $pdo->prepare('
        INSERT INTO score_histories (id, child_id, week_number, year, score_before, permanences_done, permanences_due, score_after, snapshot_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
        score_before = VALUES(score_before),
        permanences_done = VALUES(permanences_done),
        permanences_due = VALUES(permanences_due),
        score_after = VALUES(score_after),
        snapshot_at = VALUES(snapshot_at)
    ');

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

/**
 * Recalcule tout l'historique des scores d'un enfant après un ajustement manuel.
 * Les enregistrements sont triés chronologiquement par année puis numéro de semaine.
 */
function recalculate_child_score_history(string $childId): void {
    $pdo = get_db();
    
    $stmt = $pdo->prepare('SELECT * FROM score_histories WHERE child_id = ? ORDER BY year ASC, week_number ASC');
    $stmt->execute([$childId]);
    $histories = $stmt->fetchAll();

    $runningScore = 0.0;
    
    $updateStmt = $pdo->prepare('UPDATE score_histories SET score_before = ?, score_after = ? WHERE id = ?');

    foreach ($histories as $h) {
        $scoreBefore = $runningScore;
        $scoreAfter = $scoreBefore + (int)$h['permanences_done'] - (float)$h['permanences_due'];
        
        $updateStmt->execute([$scoreBefore, $scoreAfter, $h['id']]);
        
        $runningScore = $scoreAfter;
    }
}
