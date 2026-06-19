<?php
/**
 * Routes de planning.
 * GET  /planning/:id          — Détails d'une semaine
 * POST /planning/generate/:id — Lancer l'algorithme
 */

function handle_planning(string $route, string $method): void {
    if (preg_match('#^generate/([a-f0-9\-]+)$#', $route, $m) && $method === 'POST') {
        planning_generate($m[1]);
    } elseif (preg_match('#^([a-f0-9\-]+)$#', $route, $m) && $method === 'GET') {
        planning_get($m[1]);
    } else {
        json_response(['error' => 'Route non trouvée'], 404);
    }
}

function planning_get(string $weekId): void {
    $user = require_auth();

    if (!validate_uuid($weekId)) {
        json_response(['error' => 'ID invalide'], 400);
        return;
    }

    $pdo = get_db();

    // Récupérer la semaine
    $stmt = $pdo->prepare('SELECT * FROM planning_weeks WHERE id = ?');
    $stmt->execute([$weekId]);
    $week = $stmt->fetch();

    if (!$week) {
        json_response(['error' => 'Semaine introuvable'], 404);
        return;
    }

    // Récupérer les slots
    $stmt = $pdo->prepare('SELECT * FROM slots WHERE planning_week_id = ?');
    $stmt->execute([$weekId]);
    $slots = $stmt->fetchAll();

    $childIdFilter = $_GET['childId'] ?? null;

    // Récupérer toutes les données liées aux slots
    $slotIds = array_column($slots, 'id');
    
    if (empty($slotIds)) {
        json_response([
            'id'         => $week['id'],
            'weekNumber' => (int) $week['week_number'],
            'year'       => (int) $week['year'],
            'status'     => $week['status'],
            'slots'      => [],
        ]);
        return;
    }

    $placeholders = implode(',', array_fill(0, count($slotIds), '?'));

    // Disponibilités
    if ($childIdFilter && validate_uuid($childIdFilter)) {
        $availStmt = $pdo->prepare("SELECT * FROM availabilities WHERE slot_id IN ($placeholders) AND child_id = ?");
        $availStmt->execute(array_merge($slotIds, [$childIdFilter]));
    } else {
        $availStmt = $pdo->prepare("SELECT a.*, c.id as c_id, c.first_name as c_first_name, c.last_name as c_last_name, c.age_group as c_age_group FROM availabilities a JOIN children c ON a.child_id = c.id WHERE a.slot_id IN ($placeholders)");
        $availStmt->execute($slotIds);
    }
    $allAvails = $availStmt->fetchAll();

    // Présences enfants
    $presStmt = $pdo->prepare("SELECT cp.*, c.id as c_id, c.first_name as c_first_name, c.last_name as c_last_name, c.age_group as c_age_group FROM child_presences cp JOIN children c ON cp.child_id = c.id WHERE cp.slot_id IN ($placeholders)");
    $presStmt->execute($slotIds);
    $allPresences = $presStmt->fetchAll();

    // Assignations
    $assignStmt = $pdo->prepare("SELECT a.*, c.id as c_id, c.first_name as c_first_name, c.last_name as c_last_name, c.age_group as c_age_group, c.parent_id as c_parent_id FROM assignments a JOIN children c ON a.child_id = c.id WHERE a.slot_id IN ($placeholders)");
    $assignStmt->execute($slotIds);
    $allAssignments = $assignStmt->fetchAll();

    // Organiser par slot
    $availBySlot = [];
    foreach ($allAvails as $a) {
        $entry = [
            'id'          => $a['id'],
            'isAvailable' => (bool) $a['is_available'],
            'childId'     => $a['child_id'],
        ];
        if (isset($a['c_id'])) {
            $entry['child'] = [
                'id'        => $a['c_id'],
                'firstName' => $a['c_first_name'],
                'lastName'  => $a['c_last_name'],
                'ageGroup'  => $a['c_age_group'],
            ];
        }
        $availBySlot[$a['slot_id']][] = $entry;
    }

    $presBySlot = [];
    foreach ($allPresences as $p) {
        $presBySlot[$p['slot_id']][] = [
            'id'        => $p['id'],
            'isPresent' => (bool) $p['is_present'],
            'childId'   => $p['child_id'],
            'child'     => [
                'id'        => $p['c_id'],
                'firstName' => $p['c_first_name'],
                'lastName'  => $p['c_last_name'],
                'ageGroup'  => $p['c_age_group'],
            ],
        ];
    }

    $assignBySlot = [];
    foreach ($allAssignments as $a) {
        $assignBySlot[$a['slot_id']][] = [
            'id'       => $a['id'],
            'isManual' => (bool) $a['is_manual'],
            'childId'  => $a['child_id'],
            'child'    => [
                'id'        => $a['c_id'],
                'firstName' => $a['c_first_name'],
                'lastName'  => $a['c_last_name'],
                'parentId'  => $a['c_parent_id'],
                'ageGroup'  => $a['c_age_group'],
            ],
            // Le frontend attend "parent" au lieu de "child" pour les assignments
            'parent'   => [
                'id'        => $a['c_id'],
                'firstName' => $a['c_first_name'],
                'lastName'  => $a['c_last_name'],
            ],
        ];
    }

    // Construire la réponse
    $formattedSlots = [];
    foreach ($slots as $s) {
        $formattedSlots[] = [
            'id'              => $s['id'],
            'planningWeekId'  => $s['planning_week_id'],
            'dayOfWeek'       => $s['day_of_week'],
            'halfDay'         => $s['half_day'],
            'slotType'        => $s['slot_type'],
            'requiredParents' => (int) $s['required_parents'],
            'availabilities'  => $availBySlot[$s['id']] ?? [],
            'childPresences'  => $presBySlot[$s['id']] ?? [],
            'assignments'     => $assignBySlot[$s['id']] ?? [],
        ];
    }

    json_response([
        'id'                  => $week['id'],
        'weekNumber'          => (int) $week['week_number'],
        'year'                => (int) $week['year'],
        'status'              => $week['status'],
        'needsRecalculation'  => (bool) $week['needs_recalculation'],
        'createdAt'           => $week['created_at'],
        'updatedAt'           => $week['updated_at'],
        'slots'               => $formattedSlots,
    ]);
}

function planning_generate(string $weekId): void {
    $user = require_auth();
    verify_csrf();
    require_role($user, 'ADMIN');

    if (!validate_uuid($weekId)) {
        json_response(['error' => 'ID invalide'], 400);
        return;
    }

    require_week_status($weekId, ['OPEN_TO_PARENTS']);

    require_once __DIR__ . '/../services/allocation.php';
    require_once __DIR__ . '/../services/score.php';

    $pdo = get_db();

    // 1. Récupérer les créneaux ouverts avec disponibilités
    $stmt = $pdo->prepare("
        SELECT s.id, s.day_of_week, s.half_day, s.required_parents
        FROM slots s
        WHERE s.planning_week_id = ? AND s.slot_type != 'CLOSED'
    ");
    $stmt->execute([$weekId]);
    $rawSlots = $stmt->fetchAll();

    // Récupérer les disponibilités (isAvailable = true)
    $slotIds = array_column($rawSlots, 'id');
    $algoSlots = [];

    if (!empty($slotIds)) {
        $ph = implode(',', array_fill(0, count($slotIds), '?'));
        $availStmt = $pdo->prepare("SELECT slot_id, child_id FROM availabilities WHERE slot_id IN ($ph) AND is_available = 1");
        $availStmt->execute($slotIds);
        $allAvails = $availStmt->fetchAll();

        $availBySlot = [];
        foreach ($allAvails as $a) {
            $availBySlot[$a['slot_id']][] = $a['child_id'];
        }

        foreach ($rawSlots as $s) {
            $algoSlots[] = [
                'slotId'            => $s['id'],
                'dayOfWeek'         => $s['day_of_week'],
                'halfDay'           => $s['half_day'],
                'requiredParents'   => (int) $s['required_parents'],
                'availableParentIds' => $availBySlot[$s['id']] ?? [],
            ];
        }
    }

    // 2. Calculer les scores pour chaque enfant
    $uniqueChildIds = [];
    foreach ($algoSlots as $s) {
        foreach ($s['availableParentIds'] as $cid) {
            $uniqueChildIds[$cid] = true;
        }
    }

    $parentScores = [];
    foreach (array_keys($uniqueChildIds) as $childId) {
        $score = get_current_score($childId, $weekId);
        $cStmt = $pdo->prepare('SELECT first_name, last_name FROM children WHERE id = ?');
        $cStmt->execute([$childId]);
        $child = $cStmt->fetch();
        $parentScores[] = [
            'parentId'  => $childId,
            'firstName' => $child['first_name'] ?? '',
            'lastName'  => $child['last_name'] ?? '',
            'score'     => $score,
        ];
    }

    // 3. Exécuter l'algorithme
    $result = allocate($algoSlots, $parentScores);

    // 4. Supprimer les anciennes affectations non manuelles
    if (!empty($slotIds)) {
        $ph = implode(',', array_fill(0, count($slotIds), '?'));
        $pdo->prepare("DELETE FROM assignments WHERE slot_id IN ($ph) AND is_manual = 0")->execute($slotIds);
    }

    // 5. Insérer les nouvelles
    $insStmt = $pdo->prepare('INSERT INTO assignments (id, child_id, slot_id, is_manual, assigned_at) VALUES (?, ?, ?, 0, ?)');
    $now = date('Y-m-d H:i:s');
    foreach ($result['assignments'] as $a) {
        $insStmt->execute([generate_uuid(), $a['parentId'], $a['slotId'], $now]);
    }

    // 6. Retirer le flag needsRecalculation
    $pdo->prepare('UPDATE planning_weeks SET needs_recalculation = 0 WHERE id = ?')->execute([$weekId]);

    json_response([
        'message'       => 'Planning généré avec succès',
        'stats'         => $result['stats'],
        'unfilledSlots' => $result['unfilledSlots'],
    ]);
}
