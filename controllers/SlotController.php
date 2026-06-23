<?php

class SlotController {
    public function handle(string $route, string $method): void {
        if (preg_match('#^([a-f0-9\-]+)$#', $route, $m) && $method === 'PATCH') {
            $this->update($m[1]);
        } else {
            json_response(['error' => 'Route non trouvée'], 404);
        }
    }

    private function update(string $slotId): void {
        $user = require_auth();
        verify_csrf();
        require_role($user, ['ADMIN', 'PROFESSIONAL']);

        if (!validate_uuid($slotId)) {
            json_response(['error' => 'ID invalide'], 400);
            return;
        }

        $body = get_json_body();
        $slotType = $body['slotType'] ?? '';

        if (!in_array($slotType, ['OPEN', 'DOUBLE_PERM', 'CLOSED'])) {
            json_response(['error' => 'Type de créneau invalide'], 400);
            return;
        }

        $pdo = get_db_connection();

        $stmt = $pdo->prepare('
            SELECT s.*, pw.status as week_status 
            FROM slots s 
            JOIN planning_weeks pw ON s.planning_week_id = pw.id 
            WHERE s.id = ?
        ');
        $stmt->execute([$slotId]);
        $slot = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$slot) {
            json_response(['error' => 'Créneau introuvable'], 404);
            return;
        }

        if (in_array($slot['week_status'], ['CALCULATION', 'PUBLISHED'])) {
            json_response(['error' => 'Impossible de modifier un créneau d\'une semaine déjà verrouillée'], 403);
            return;
        }

        $requiredParents = 1;
        if ($slotType === 'DOUBLE_PERM') $requiredParents = 2;
        if ($slotType === 'CLOSED') $requiredParents = 0;

        $stmt = $pdo->prepare('UPDATE slots SET slot_type = ?, required_parents = ? WHERE id = ?');
        $stmt->execute([$slotType, $requiredParents, $slotId]);

        json_response([
            'id'              => $slotId,
            'planningWeekId'  => $slot['planning_week_id'],
            'dayOfWeek'       => $slot['day_of_week'],
            'halfDay'         => $slot['half_day'],
            'slotType'        => $slotType,
            'requiredParents' => $requiredParents,
        ]);
    }
}
