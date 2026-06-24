<?php

class AvailabilityController {
    public function handle(string $route, string $method): void {
        if (preg_match('#^week/([a-f0-9\-]+)$#', $route, $m) && $method === 'PUT') {
            $this->submit($m[1]);
        } else {
            json_response(['error' => 'Route non trouvée'], 404);
        }
    }

    private function submit(string $weekId): void {
        $user = require_auth();
        verify_csrf();
        require_role($user, 'PARENT');

        if (!validate_uuid($weekId)) {
            json_response(['error' => 'ID invalide'], 400);
            return;
        }

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

        $stmt = $pdo->prepare('SELECT id, parent_id FROM children WHERE id = ?');
        $stmt->execute([$childId]);
        $child = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$child) {
            json_response(['error' => 'Enfant introuvable'], 404);
            return;
        }

        // Sécurité : Un PARENT ne peut modifier que ses propres enfants
        if ($user['role'] === 'PARENT' && $child['parent_id'] !== $user['userId']) {
            json_response(['error' => 'Accès interdit : cet enfant ne vous appartient pas'], 403);
            return;
        }

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
            $delStmt = $pdo->prepare("DELETE FROM availabilities WHERE child_id = ? AND slot_id IN ($placeholders)");
            $delStmt->execute(array_merge([$childId], $slotIds));

            $insStmt = $pdo->prepare('INSERT INTO availabilities (id, child_id, slot_id, is_available, submitted_at) VALUES (?, ?, ?, ?, ?)');
            $now = date('Y-m-d H:i:s');
            foreach ($availabilities as $a) {
                $insStmt->execute([generate_uuid(), $childId, $a['slotId'], $a['isAvailable'] ? 1 : 0, $now]);
            }

            $delPresStmt = $pdo->prepare("DELETE FROM child_presences WHERE child_id = ? AND slot_id IN ($placeholders)");
            $delPresStmt->execute(array_merge([$childId], $slotIds));

            $insPresStmt = $pdo->prepare('INSERT INTO child_presences (id, child_id, slot_id, is_present) VALUES (?, ?, ?, ?)');
            foreach ($availabilities as $a) {
                $isPresent = empty($a['isAbsent']) ? 1 : 0;
                $insPresStmt->execute([generate_uuid(), $childId, $a['slotId'], $isPresent]);
            }

            $pdo->prepare('UPDATE planning_weeks SET needs_recalculation = 1 WHERE id = ?')->execute([$weekId]);

            $pdo->commit();

            json_response(['message' => 'Disponibilités enregistrées avec succès']);
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
