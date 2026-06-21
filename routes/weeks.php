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
        $tableHtml = build_planning_html_email($pdo, $weekId);

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

function build_planning_html_email(PDO $pdo, string $weekId): string {
    // 1. Get slots
    $stmt = $pdo->prepare('SELECT * FROM slots WHERE planning_week_id = ? ORDER BY 
                           CASE day_of_week WHEN "MONDAY" THEN 1 WHEN "TUESDAY" THEN 2 WHEN "WEDNESDAY" THEN 3 WHEN "THURSDAY" THEN 4 WHEN "FRIDAY" THEN 5 ELSE 6 END,
                           CASE half_day WHEN "MORNING" THEN 1 ELSE 2 END');
    $stmt->execute([$weekId]);
    $slots = $stmt->fetchAll();

    // 2. Get active children & defaults
    $stmt = $pdo->query('SELECT c.id, c.first_name, c.age_group, d.day_of_week, d.half_day 
                         FROM children c 
                         LEFT JOIN default_presences d ON c.id = d.child_id 
                         WHERE c.is_active = 1');
    $childrenRows = $stmt->fetchAll();
    
    $children = [];
    foreach ($childrenRows as $r) {
        $cid = $r['id'];
        if (!isset($children[$cid])) {
            $children[$cid] = ['first_name' => $r['first_name'], 'age_group' => $r['age_group'], 'defaults' => []];
        }
        if ($r['day_of_week']) {
            $children[$cid]['defaults'][$r['day_of_week'] . '_' . $r['half_day']] = true;
        }
    }

    // 3. Get child_presences (overrides)
    $stmt = $pdo->prepare('SELECT cp.slot_id, cp.child_id, cp.is_present FROM child_presences cp JOIN slots s ON cp.slot_id = s.id WHERE s.planning_week_id = ?');
    $stmt->execute([$weekId]);
    $presences = $stmt->fetchAll();
    $presBySlotAndChild = [];
    foreach ($presences as $p) {
        $presBySlotAndChild[$p['slot_id']][$p['child_id']] = (bool) $p['is_present'];
    }

    // 4. Get assignments
    $stmt = $pdo->prepare('SELECT a.slot_id, c.last_name, a.is_manual FROM assignments a JOIN children c ON a.child_id = c.id JOIN slots s ON a.slot_id = s.id WHERE s.planning_week_id = ?');
    $stmt->execute([$weekId]);
    $assignments = $stmt->fetchAll();
    $assignBySlot = [];
    foreach ($assignments as $a) {
        $assignBySlot[$a['slot_id']][] = "Fam. " . htmlspecialchars($a['last_name']);
    }

    // 5. Get availabilities
    $stmt = $pdo->prepare('SELECT a.slot_id, c.first_name FROM availabilities a JOIN children c ON a.child_id = c.id JOIN slots s ON a.slot_id = s.id WHERE s.planning_week_id = ? AND a.is_available = 1');
    $stmt->execute([$weekId]);
    $availabilities = $stmt->fetchAll();
    $availBySlot = [];
    foreach ($availabilities as $a) {
        $availBySlot[$a['slot_id']][] = htmlspecialchars($a['first_name']);
    }

    // Build the grid
    $days = ['MONDAY' => 'Lundi', 'TUESDAY' => 'Mardi', 'WEDNESDAY' => 'Mercredi', 'THURSDAY' => 'Jeudi', 'FRIDAY' => 'Vendredi'];
    $halfDays = ['MORNING' => 'Matin', 'AFTERNOON' => 'Après-midi'];
    
    $html = '<table border="1" cellpadding="10" cellspacing="0" style="border-collapse: collapse; width: 100%; max-width: 900px; font-family: Arial, sans-serif; font-size: 13px;">';
    $html .= '<tr style="background-color: #f43f5e; color: white;"><th>Jour</th><th>Matin</th><th>Après-midi</th></tr>';

    foreach ($days as $day => $frDay) {
        $html .= '<tr>';
        $html .= '<td style="background-color: #fef08a; font-weight: bold; width: 15%; text-align: center;">' . $frDay . '</td>';
        
        foreach ($halfDays as $half => $frHalf) {
            $slot = null;
            foreach ($slots as $s) {
                if ($s['day_of_week'] === $day && $s['half_day'] === $half) {
                    $slot = $s;
                    break;
                }
            }
            
            $html .= '<td style="width: 42.5%; vertical-align: top;">';
            if (!$slot) {
                $html .= '-';
            } elseif ($slot['slot_type'] === 'CLOSED') {
                $html .= '<div style="color: #666; font-style: italic; text-align: center; padding: 10px;">Fermé</div>';
            } else {
                // Permanences
                $html .= '<div style="margin-bottom: 8px; text-align: center; background-color: #f3f4f6; padding: 5px; border-radius: 4px;">';
                $html .= '<strong>Permanence :</strong><br>';
                $assigns = $assignBySlot[$slot['id']] ?? [];
                if (empty($assigns)) {
                    $html .= '<span style="color: #999; font-style: italic;">Équipe / Non rempli</span>';
                } else {
                    $html .= '<span style="color: #d97706; font-weight: bold; font-size: 14px;">' . implode(' &amp; ', $assigns) . '</span>';
                }
                $html .= '</div>';
                
                // Absents et Présents
                $grandsPres = []; $petitsPres = [];
                $grandsAbs = []; $petitsAbs = [];
                
                foreach ($children as $cid => $c) {
                    $isEnrolled = isset($c['defaults'][$day . '_' . $half]);
                    $override = isset($presBySlotAndChild[$slot['id']][$cid]) ? $presBySlotAndChild[$slot['id']][$cid] : null;
                    $isPresent = ($override !== null) ? $override : $isEnrolled;
                    
                    if ($isPresent) {
                        if ($c['age_group'] === 'GRAND') $grandsPres[] = $c['first_name'];
                        else $petitsPres[] = $c['first_name'];
                    } else {
                        if ($c['age_group'] === 'GRAND') $grandsAbs[] = $c['first_name'];
                        else $petitsAbs[] = $c['first_name'];
                    }
                }
                
                $html .= '<div style="font-size: 11px; text-align: left;">';
                $html .= '<div style="margin-bottom: 4px;"><strong style="color: #0284c7;">Grands : ' . count($grandsPres) . ' présents / ' . count($grandsAbs) . ' absents</strong><br>';
                $html .= '<span style="color: #666;">Pr: ' . (empty($grandsPres) ? '-' : implode(', ', $grandsPres)) . ' | Abs: ' . (empty($grandsAbs) ? '-' : implode(', ', $grandsAbs)) . '</span></div>';
                
                $html .= '<div><strong style="color: #059669;">Petits : ' . count($petitsPres) . ' présents / ' . count($petitsAbs) . ' absents</strong><br>';
                $html .= '<span style="color: #666;">Pr: ' . (empty($petitsPres) ? '-' : implode(', ', $petitsPres)) . ' | Abs: ' . (empty($petitsAbs) ? '-' : implode(', ', $petitsAbs)) . '</span></div>';
                
                $avails = $availBySlot[$slot['id']] ?? [];
                if (!empty($avails)) {
                    $html .= '<div style="margin-top: 4px; color: #16a34a;"><strong>Parents Dispos: </strong>' . implode(', ', $avails) . '</div>';
                }
                
                $html .= '</div>';
            }
            $html .= '</td>';
        }
        $html .= '</tr>';
    }
    
    $html .= '</table>';
    return $html;
}
