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

    // Récupérer l'année et le numéro de la semaine
    $stmt = $pdo->prepare('SELECT id, week_number, year FROM planning_weeks WHERE id = ?');
    $stmt->execute([$weekId]);
    $weekData = $stmt->fetch();
    if (!$weekData) return [];

    $dto = new DateTime();
    $dto->setISODate($weekData['year'], $weekData['week_number']);
    
    $weekDates = [];
    $daysOfWeek = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY'];
    foreach ($daysOfWeek as $dayName) {
        $weekDates[$dayName] = $dto->format('Y-m-d');
        $dto->modify('+1 day');
    }
    
    $mondayStr = $weekDates['MONDAY'];
    $fridayStr = $weekDates['FRIDAY'];

    // Récupérer tous les enfants potentiellement actifs
    $childStmt = $pdo->query('SELECT id FROM children');
    $allChildren = $childStmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($allChildren)) return [];

    // Récupérer les présences par défaut
    $presStmt = $pdo->query('SELECT child_id, day_of_week, half_day FROM child_default_presences');
    $presences = $presStmt->fetchAll(PDO::FETCH_ASSOC);
    $presByChild = [];
    foreach ($presences as $p) {
        $presByChild[$p['child_id']][] = $p;
    }

    // Récupérer les CONGES qui chevauchent la semaine
    $congeStmt = $pdo->prepare('
        SELECT child_id, start_date, start_half_day, end_date, end_half_day 
        FROM child_absences 
        WHERE is_conge = 1
          AND start_date <= ? 
          AND (end_date IS NULL OR end_date >= ?)
    ');
    $congeStmt->execute([$fridayStr, $mondayStr]);
    $conges = $congeStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $congesByChild = [];
    foreach ($conges as $c) {
        $congesByChild[$c['child_id']][] = $c;
    }

    $effectiveWeights = [];
    
    foreach ($allChildren as $childId) {
        $childPresences = $presByChild[$childId] ?? [];
        if (empty($childPresences)) {
            // Pas de contrat, on vérifie grossièrement si couvert à 100%
            $isFullyCovered = false;
            foreach ($congesByChild[$childId] ?? [] as $c) {
                if ($c['start_date'] <= $mondayStr && ($c['end_date'] === null || $c['end_date'] >= $fridayStr)) {
                     if (($c['start_date'] < $mondayStr || $c['start_half_day'] === 'ALL' || $c['start_half_day'] === 'MORNING') &&
                         ($c['end_date'] === null || $c['end_date'] > $fridayStr || $c['end_half_day'] === 'ALL' || $c['end_half_day'] === 'AFTERNOON')) {
                         $isFullyCovered = true;
                         break;
                     }
                }
            }
            $effectiveWeights[$childId] = $isFullyCovered ? 0 : 1;
            continue;
        }

        $weight = 0;
        foreach ($childPresences as $p) {
            $dayName = $p['day_of_week'];
            $dateStr = $weekDates[$dayName];
            $halfDay = $p['half_day'];
            
            $isCovered = false;
            foreach ($congesByChild[$childId] ?? [] as $c) {
                // Gestion du cas où le départ et le retour sont sur la même journée
                if ($c['end_date'] !== null && $c['start_date'] === $c['end_date'] && $dateStr === $c['start_date']) {
                    if ($c['start_half_day'] === 'ALL' || $c['end_half_day'] === 'ALL') {
                        $isCovered = true; break;
                    } elseif ($c['start_half_day'] === 'MORNING' && $c['end_half_day'] === 'MORNING' && $halfDay === 'MORNING') {
                        $isCovered = true; break;
                    } elseif ($c['start_half_day'] === 'AFTERNOON' && $c['end_half_day'] === 'AFTERNOON' && $halfDay === 'AFTERNOON') {
                        $isCovered = true; break;
                    } elseif ($c['start_half_day'] === 'MORNING' && $c['end_half_day'] === 'AFTERNOON') {
                        $isCovered = true; break;
                    }
                } else {
                    // Couverture milieu de congé
                    if ($dateStr > $c['start_date'] && ($c['end_date'] === null || $dateStr < $c['end_date'])) {
                        $isCovered = true; break;
                    }
                    // Couverture jour de départ
                    if ($dateStr === $c['start_date']) {
                        if ($c['start_half_day'] === 'ALL') {
                            $isCovered = true; break;
                        } elseif ($c['start_half_day'] === 'MORNING') {
                            // Départ le matin = absent toute la journée
                            $isCovered = true; break;
                        } elseif ($c['start_half_day'] === 'AFTERNOON' && $halfDay === 'AFTERNOON') {
                            // Départ l'après-midi = absent que l'après-midi
                            $isCovered = true; break;
                        }
                    }
                    // Couverture jour de fin
                    if ($c['end_date'] !== null && $dateStr === $c['end_date']) {
                        if ($c['end_half_day'] === 'ALL') {
                            $isCovered = true; break;
                        } elseif ($c['end_half_day'] === 'AFTERNOON') {
                            // Fin l'après-midi = absent toute la journée (retour le lendemain)
                            $isCovered = true; break;
                        } elseif ($c['end_half_day'] === 'MORNING' && $halfDay === 'MORNING') {
                            // Fin le matin = absent que le matin
                            $isCovered = true; break;
                        }
                    }
                }
            }
            if (!$isCovered) {
                $weight++;
            }
        }
        $effectiveWeights[$childId] = $weight;
    }

    $activeChildIds = [];
    $totalWeight = 0;
    foreach ($effectiveWeights as $cid => $w) {
        if ($w > 0) {
            $activeChildIds[] = $cid;
            $totalWeight += $w;
        }
    }

    if (empty($activeChildIds)) return [];

    // Calculer la charge totale
    $totalRequired = array_reduce($weekSlots, fn($acc, $s) => $acc + (int)$s['required_parents'], 0);

    $dues = [];
    if ($totalWeight > 0) {
        foreach ($activeChildIds as $childId) {
            $dues[$childId] = $totalRequired * ($effectiveWeights[$childId] / $totalWeight);
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

    // Récupérer tous les enfants avec les infos de leurs parents
    $childStmt = $pdo->query('SELECT id, parent1_first_name, parent1_email, parent2_email FROM children');
    $allChildren = $childStmt->fetchAll(PDO::FETCH_ASSOC);

    $updateStmt = $pdo->prepare('
        UPDATE score_histories 
        SET score_before = ?, permanences_done = ?, permanences_due = ?, score_after = ?, snapshot_at = ?
        WHERE child_id = ? AND week_number = ? AND year = ?
    ');

    $insertStmt = $pdo->prepare('
        INSERT INTO score_histories (id, child_id, week_number, year, score_before, permanences_done, permanences_due, score_after, snapshot_at) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');

    $now = date('Y-m-d H:i:s');

    // URL du frontend pour les emails
    require_once __DIR__ . '/../config.php';
    $appUrl = explode(',', CORS_ORIGINS)[0] . '/planning';

    foreach ($allChildren as $childData) {
        $childId = $childData['id'];
        $baseScore = get_current_score($childId); // Score avant cette semaine

        // Compter les assignations cette semaine
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM assignments a JOIN slots s ON a.slot_id = s.id WHERE a.child_id = ? AND s.planning_week_id = ?');
        $stmt->execute([$childId, $weekId]);
        $assignmentsThisWeek = (int) $stmt->fetchColumn();

        $dueThisWeek = $dues[$childId] ?? 0.0;
        $newScore = $baseScore + $assignmentsThisWeek - $dueThisWeek;

        $updateStmt->execute([
            $baseScore,
            $assignmentsThisWeek,
            $dueThisWeek,
            $newScore,
            $now,
            $childId,
            $weekNumber,
            $year
        ]);

        if ($updateStmt->rowCount() === 0) {
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

        // Vérifier s'il y a un changement de statut (franchissement du seuil 0)
        // Relâche -> Perm (Base >= 0 et New < 0)
        if ($baseScore >= 0 && $newScore < 0) {
            $subject = "Alerte Permanence : Votre score nécessite votre attention ⚠️";
            $message = render_perm_email($childData['parent1_first_name'], $newScore, $appUrl);
            if (!empty($childData['parent1_email'])) send_email($childData['parent1_email'], $subject, $message);
            if (!empty($childData['parent2_email'])) send_email($childData['parent2_email'], $subject, $message);
        }
        // Perm -> Relâche (Base < 0 et New >= 0)
        elseif ($baseScore < 0 && $newScore >= 0) {
            $subject = "Bonne nouvelle ! Vous passez en Relâche 🎉";
            $message = render_relache_email($childData['parent1_first_name'], $newScore, $appUrl);
            if (!empty($childData['parent1_email'])) send_email($childData['parent1_email'], $subject, $message);
            if (!empty($childData['parent2_email'])) send_email($childData['parent2_email'], $subject, $message);
        }
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
        $scoreAfter = $scoreBefore + (float)$h['permanences_done'] - (float)$h['permanences_due'];
        
        $updateStmt->execute([$scoreBefore, $scoreAfter, $h['id']]);
        
        $runningScore = $scoreAfter;
    }
}

/**
 * Vérifie si un enfant est absent pour une semaine donnée.
 */
function is_child_absent_for_week(string $childId, int $year, int $weekNumber): bool {
    $pdo = get_db();
    $dto = new DateTime();
    $dto->setISODate($year, $weekNumber);
    $monday = $dto->format('Y-m-d');
    $dto->modify('+4 days');
    $friday = $dto->format('Y-m-d');

    $stmt = $pdo->prepare('
        SELECT 1 FROM child_absences 
        WHERE child_id = ? 
          AND is_conge = 1
          AND start_date <= ? 
          AND (end_date IS NULL OR end_date >= ?)
    ');
    $stmt->execute([$childId, $monday, $friday]);
    return (bool) $stmt->fetch();
}

/**
 * Recalcule la dette théorique de TOUS les enfants pour toutes les semaines passées
 * suite à la modification des dates d'absence d'un enfant (la charge se répartit).
 */
function sync_child_absences_retroactive(): void {
    $pdo = get_db();
    
    // Récupérer les semaines publiées (courante et futures uniquement) pour geler le passé
    $monday = date('Y-m-d', strtotime('monday this week'));
    $stmt = $pdo->prepare('SELECT id, week_number, year FROM planning_weeks WHERE status = "PUBLISHED" AND start_date >= ?');
    $stmt->execute([$monday]);
    $publishedWeeks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Récupérer tous les enfants
    $childStmt = $pdo->query('SELECT id FROM children');
    $allChildren = $childStmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($publishedWeeks as $week) {
        // Recalcule la dette théorique pour cette semaine exacte (en tenant compte des absences à cette date)
        $dues = calculate_theoretical_dues($week['id']);
        
        $update = $pdo->prepare('UPDATE score_histories SET permanences_due = ? WHERE child_id = ? AND week_number = ? AND year = ?');
        
        foreach ($allChildren as $cid) {
            $dueThisWeek = $dues[$cid] ?? 0.0;
            $update->execute([$dueThisWeek, $cid, $week['week_number'], $week['year']]);
        }
    }

    // Recalculer l'historique complet pour cascader la mise à jour des dettes pour TOUS les enfants
    foreach ($allChildren as $cid) {
        recalculate_child_score_history($cid);
    }
}
