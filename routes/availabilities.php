<?php
/**
 * Routes de gestion des disponibilités.
 * PUT /availabilities/week/:id — Soumettre les disponibilités
 */

function handle_availabilities(string $route, string $method): void {
    if (preg_match('#^week/([a-f0-9\-]+)$#', $route, $m) && $method === 'PUT') {
        availabilities_submit($m[1]);
    } else {
        json_response(['error' => 'Route non trouvée'], 404);
    }
}

function availabilities_submit(string $weekId): void {
    $user = require_auth();
    verify_csrf();
    require_role($user, 'PARENT');

    if (!validate_uuid($weekId)) {
        json_response(['error' => 'ID invalide'], 400);
        return;
    }

    // Vérifier le statut de la semaine (tant que ce n'est pas publié, on peut modifier)
    require_week_status($weekId, ['PREPARATION', 'OPEN_TO_PARENTS', 'CALCULATION']);

    $body = get_json_body();
    $childId = $body['childId'] ?? '';
    $availabilities = $body['availabilities'] ?? [];

    if (empty($childId) || !validate_uuid($childId)) {
        json_response(['error' => 'childId est requis'], 400);
        return;
    }

    if (empty($availabilities)) {
        json_response(['error' => 'Au moins une disponibilité requise'], 400);
        return;
    }

    $pdo = get_db();

    // Vérifier que l'enfant existe
    $stmt = $pdo->prepare('SELECT id FROM children WHERE id = ?');
    $stmt->execute([$childId]);
    if (!$stmt->fetch()) {
        json_response(['error' => 'Enfant introuvable'], 404);
        return;
    }

    // Vérifier que tous les slots appartiennent à cette semaine et ne sont pas fermés
    $slotIds = array_map(function ($a) { return $a['slotId']; }, $availabilities);
    $placeholders = implode(',', array_fill(0, count($slotIds), '?'));
    $params = array_merge($slotIds, [$weekId]);
    
    $stmt = $pdo->prepare("SELECT id FROM slots WHERE id IN ($placeholders) AND planning_week_id = ? AND slot_type != 'CLOSED'");
    $stmt->execute($params);
    $validSlots = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (count($validSlots) !== count($slotIds)) {
        json_response(['error' => 'Certains créneaux sont invalides ou fermés'], 400);
        return;
    }

    $pdo->beginTransaction();
    try {
        // 1. Supprimer les anciennes disponibilités pour ces créneaux
        $delStmt = $pdo->prepare("DELETE FROM availabilities WHERE child_id = ? AND slot_id IN ($placeholders)");
        $delStmt->execute(array_merge([$childId], $slotIds));

        // 2. Insérer les nouvelles disponibilités
        $insStmt = $pdo->prepare('INSERT INTO availabilities (id, child_id, slot_id, is_available, submitted_at) VALUES (?, ?, ?, ?, ?)');
        $now = date('Y-m-d H:i:s');
        foreach ($availabilities as $a) {
            $insStmt->execute([generate_uuid(), $childId, $a['slotId'], $a['isAvailable'] ? 1 : 0, $now]);
        }

        // 3. Gérer les présences enfants
        $delPresStmt = $pdo->prepare("DELETE FROM child_presences WHERE child_id = ? AND slot_id IN ($placeholders)");
        $delPresStmt->execute(array_merge([$childId], $slotIds));

        $insPresStmt = $pdo->prepare('INSERT INTO child_presences (id, child_id, slot_id, is_present) VALUES (?, ?, ?, ?)');
        foreach ($availabilities as $a) {
            $isPresent = empty($a['isAbsent']) ? 1 : 0;
            $insPresStmt->execute([generate_uuid(), $childId, $a['slotId'], $isPresent]);
        }

        // 4. Marquer la semaine comme nécessitant un recalcul
        $pdo->prepare('UPDATE planning_weeks SET needs_recalculation = 1 WHERE id = ?')->execute([$weekId]);

        $pdo->commit();

        json_response(['message' => 'Disponibilités enregistrées avec succès']);
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
