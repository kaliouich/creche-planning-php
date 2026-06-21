<?php
/**
 * Routes de gestion des semaines de planning.
 * GET    /weeks          — Liste des semaines
 * POST   /weeks          — Créer une semaine
 * PATCH  /weeks/:id/status — Transition de statut
 * DELETE /weeks/:id      — Supprimer une semaine
 */

function handle_weeks(string $route, string $method): void {
    if ($route === '' && $method === 'GET') {
        weeks_list();
    } elseif ($route === '' && $method === 'POST') {
        weeks_create();
    } elseif (preg_match('#^([a-f0-9\-]+)/status$#', $route, $m) && $method === 'PATCH') {
        weeks_update_status($m[1]);
    } elseif (preg_match('#^([a-f0-9\-]+)$#', $route, $m) && $method === 'DELETE') {
        weeks_delete($m[1]);
    } else {
        json_response(['error' => 'Route non trouvée'], 404);
    }
}

function weeks_list(): void {
    $user = require_auth();
    $pdo = get_db();

    $stmt = $pdo->query('
        SELECT w.*, 
               EXISTS(SELECT 1 FROM assignments a JOIN slots s ON a.slot_id = s.id WHERE s.planning_week_id = w.id) as has_assignments
        FROM planning_weeks w 
        ORDER BY w.year DESC, w.week_number DESC
    ');
    $weeks = $stmt->fetchAll();

    // Convertir les booléens
    foreach ($weeks as &$w) {
        $w['needsRecalculation'] = (bool) $w['needs_recalculation'];
        $w['hasAssignments'] = (bool) $w['has_assignments'];
        $w['weekNumber'] = (int) $w['week_number'];
        unset($w['needs_recalculation'], $w['week_number'], $w['has_assignments']);
    }

    json_response($weeks);
}

function weeks_create(): void {
    $user = require_auth();
    verify_csrf();
    require_role($user, 'ADMIN');

    $body = get_json_body();
    $weekNumber = (int) ($body['weekNumber'] ?? 0);
    $year = (int) ($body['year'] ?? 0);

    if ($weekNumber < 1 || $weekNumber > 53 || $year < 2024 || $year > 2100) {
        json_response(['error' => 'Données invalides'], 400);
        return;
    }

    $pdo = get_db();

    // Vérifier si la semaine existe déjà
    $stmt = $pdo->prepare('SELECT id FROM planning_weeks WHERE week_number = ? AND year = ?');
    $stmt->execute([$weekNumber, $year]);
    if ($stmt->fetch()) {
        json_response(['error' => 'Cette semaine existe déjà'], 409);
        return;
    }

    $pdo->beginTransaction();
    try {
        $weekId = generate_uuid();
        $now = date('Y-m-d H:i:s');

        $stmt = $pdo->prepare('INSERT INTO planning_weeks (id, week_number, year, status, needs_recalculation, created_at, updated_at) VALUES (?, ?, ?, ?, 0, ?, ?)');
        $stmt->execute([$weekId, $weekNumber, $year, 'PREPARATION', $now, $now]);

        // Créer les 10 créneaux (5 jours × 2 demi-journées)
        $days = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY'];
        $halfDays = ['MORNING', 'AFTERNOON'];
        $slotStmt = $pdo->prepare('INSERT INTO slots (id, planning_week_id, day_of_week, half_day, slot_type, required_parents) VALUES (?, ?, ?, ?, ?, ?)');

        $slots = [];
        foreach ($days as $day) {
            foreach ($halfDays as $hd) {
                $slotId = generate_uuid();
                $slotStmt->execute([$slotId, $weekId, $day, $hd, 'OPEN', 1]);
                $slots[] = [
                    'id' => $slotId,
                    'planningWeekId' => $weekId,
                    'dayOfWeek' => $day,
                    'halfDay' => $hd,
                    'slotType' => 'OPEN',
                    'requiredParents' => 1,
                ];
            }
        }

        $pdo->commit();

        json_response([
            'id' => $weekId,
            'weekNumber' => $weekNumber,
            'year' => $year,
            'status' => 'PREPARATION',
            'needsRecalculation' => false,
            'createdAt' => $now,
            'updatedAt' => $now,
            'slots' => $slots,
        ], 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function weeks_update_status(string $weekId): void {
    $user = require_auth();
    verify_csrf();
    require_role($user, 'ADMIN');

    if (!validate_uuid($weekId)) {
        json_response(['error' => 'ID invalide'], 400);
        return;
    }

    $body = get_json_body();
    $newStatus = $body['status'] ?? '';

    $validStatuses = ['PREPARATION', 'OPEN_TO_PARENTS', 'PUBLISHED'];
    if (!in_array($newStatus, $validStatuses)) {
        json_response(['error' => 'Statut invalide'], 400);
        return;
    }

    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT * FROM planning_weeks WHERE id = ?');
    $stmt->execute([$weekId]);
    $week = $stmt->fetch();

    if (!$week) {
        json_response(['error' => 'Semaine introuvable'], 404);
        return;
    }

    // Vérifier la validité de la transition
    $transitions = [
        'PREPARATION'     => ['OPEN_TO_PARENTS'],
        'OPEN_TO_PARENTS' => ['PUBLISHED', 'PREPARATION'],
        'PUBLISHED'       => [],
    ];

    $allowed = $transitions[$week['status']] ?? [];
    if (!in_array($newStatus, $allowed)) {
        json_response([
            'error' => 'Transition de statut invalide',
            'current' => $week['status'],
            'requested' => $newStatus,
            'allowed' => $allowed,
        ], 400);
        return;
    }

    // Si on publie, figer les scores
    if ($newStatus === 'PUBLISHED') {
        require_once __DIR__ . '/../services/score.php';
        snapshot_scores_for_week($weekId, (int) $week['week_number'], (int) $week['year']);
    }

    $now = date('Y-m-d H:i:s');
    $stmt = $pdo->prepare('UPDATE planning_weeks SET status = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([$newStatus, $now, $weekId]);

    // Envoyer l'email aux parents si ouverture ou publication
    if ($newStatus === 'OPEN_TO_PARENTS' || $newStatus === 'PUBLISHED') {
        notify_parents_for_week($pdo, $newStatus, (int) $week['week_number'], $weekId);
    }

    json_response([
        'id' => $weekId,
        'weekNumber' => (int) $week['week_number'],
        'year' => (int) $week['year'],
        'status' => $newStatus,
        'needsRecalculation' => (bool) $week['needs_recalculation'],
        'createdAt' => $week['created_at'],
        'updatedAt' => $now,
    ]);
}

function weeks_delete(string $weekId): void {
    $user = require_auth();
    verify_csrf();
    require_role($user, 'ADMIN');

    if (!validate_uuid($weekId)) {
        json_response(['error' => 'ID invalide'], 400);
        return;
    }

    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT id FROM planning_weeks WHERE id = ?');
    $stmt->execute([$weekId]);
    if (!$stmt->fetch()) {
        json_response(['error' => 'Semaine introuvable'], 404);
        return;
    }

    // CASCADE dans le schéma MySQL gère la suppression des slots, availabilities, assignments
    $stmt = $pdo->prepare('DELETE FROM planning_weeks WHERE id = ?');
    $stmt->execute([$weekId]);

    json_response(['message' => 'Semaine supprimée avec succès']);
}

function notify_parents_for_week(PDO $pdo, string $status, int $weekNumber, string $weekId): void {
    // Récupérer tous les emails des parents
    $stmt = $pdo->query('SELECT email, second_email FROM users WHERE role = "PARENT" AND is_active = 1');
    $parents = $stmt->fetchAll();

    $toEmails = [];
    foreach ($parents as $p) {
        if (!empty($p['email']) && filter_var($p['email'], FILTER_VALIDATE_EMAIL)) {
            $toEmails[] = $p['email'];
        }
        if (!empty($p['second_email']) && filter_var($p['second_email'], FILTER_VALIDATE_EMAIL)) {
            $toEmails[] = $p['second_email'];
        }
    }
    
    if (empty($toEmails)) return;

    $subject = '';
    $message = '';
    $appUrl = 'https://www.lesfruitsdelapassion.fr/planning';

    if ($status === 'OPEN_TO_PARENTS') {
        $subject = "Ouverture des disponibilités - Semaine $weekNumber";
        $message = "Bonjour,<br><br>La semaine <strong>$weekNumber</strong> est désormais ouverte pour la saisie de vos disponibilités.<br><br>Merci de vous rendre sur l'application pour indiquer vos choix : <a href=\"$appUrl\">$appUrl</a><br><br>Au moindre besoin, contactez-nous sur l'adresse email du planning.<br><br>L'équipe Les Fruits de la Passion.";
    } elseif ($status === 'PUBLISHED') {
        $subject = "Planning de la semaine $weekNumber publié";
        $message = "Bonjour,<br><br>Le planning de la semaine <strong>$weekNumber</strong> vient d'être publié.<br><br>";
        
        // Générer le tableau HTML du planning
        $stmtPlan = $pdo->prepare('SELECT s.day_of_week, s.half_day, a.id as assign_id, c.last_name
                                   FROM slots s
                                   LEFT JOIN assignments a ON a.slot_id = s.id
                                   LEFT JOIN children c ON a.child_id = c.id
                                   WHERE s.planning_week_id = ?
                                   ORDER BY 
                                     CASE s.day_of_week
                                       WHEN "MONDAY" THEN 1 WHEN "TUESDAY" THEN 2 WHEN "WEDNESDAY" THEN 3
                                       WHEN "THURSDAY" THEN 4 WHEN "FRIDAY" THEN 5 ELSE 6 END,
                                     CASE s.half_day WHEN "MORNING" THEN 1 ELSE 2 END');
        $stmtPlan->execute([$weekId]);
        $rows = $stmtPlan->fetchAll();

        $planning = [];
        $days = ['MONDAY' => 'Lundi', 'TUESDAY' => 'Mardi', 'WEDNESDAY' => 'Mercredi', 'THURSDAY' => 'Jeudi', 'FRIDAY' => 'Vendredi'];
        foreach ($rows as $r) {
            $day = $r['day_of_week'];
            $half = $r['half_day'];
            if (!isset($planning[$day])) {
                $planning[$day] = ['MORNING' => [], 'AFTERNOON' => []];
            }
            if ($r['assign_id']) {
                $planning[$day][$half][] = "Fam. " . htmlspecialchars($r['last_name']);
            }
        }

        $tableHtml = '<table border="1" cellpadding="10" cellspacing="0" style="border-collapse: collapse; width: 100%; max-width: 600px; font-family: Arial, sans-serif; text-align: center; margin: 20px 0;">';
        $tableHtml .= '<tr style="background-color: #f43f5e; color: white;"><th>Jour</th><th>Matin</th><th>Après-midi</th></tr>';
        
        foreach ($days as $eng => $fr) {
            if (!isset($planning[$eng])) continue;
            $tableHtml .= '<tr>';
            $tableHtml .= '<td style="background-color: #fef08a; font-weight: bold; width: 33%;">' . $fr . '</td>';
            
            $matin = implode('<br>', array_unique($planning[$eng]['MORNING']));
            $tableHtml .= '<td style="width: 33%;">' . ($matin ?: '<span style="color: #999; font-style: italic;">Équipe</span>') . '</td>';
            
            $aprem = implode('<br>', array_unique($planning[$eng]['AFTERNOON']));
            $tableHtml .= '<td style="width: 33%;">' . ($aprem ?: '<span style="color: #999; font-style: italic;">Équipe</span>') . '</td>';
            
            $tableHtml .= '</tr>';
        }
        $tableHtml .= '</table>';

        $message .= $tableHtml;
        $message .= "Vous pouvez vous connecter pour plus de détails : <a href=\"$appUrl\">$appUrl</a><br><br>";
        $message .= "Au moindre besoin, contactez-nous sur l'adresse email du planning.<br><br>L'équipe Les Fruits de la Passion.";
    } else {
        return;
    }

    $headers = "From: noreply@lesfruitsdelapassion.fr\r\n";
    $headers .= "Reply-To: direction@lesfruitsdelapassion.fr\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

    // Envoi des emails individuellement
    foreach ($toEmails as $email) {
        @mail($email, "=?UTF-8?B?" . base64_encode($subject) . "?=", $message, $headers);
    }
}
