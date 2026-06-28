<?php

class AvailabilityRepository {
    private PDO $pdo;

    public function __construct() {
        $this->pdo = get_db();
    }

    public function getValidSlots(array $slotIds, string $weekId): array {
        if (empty($slotIds)) return [];
        $placeholders = implode(',', array_fill(0, count($slotIds), '?'));
        $params = array_merge($slotIds, [$weekId]);
        
        $stmt = $this->pdo->prepare("SELECT id FROM slots WHERE id IN ($placeholders) AND planning_week_id = ? AND slot_type != 'CLOSED'");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function deleteAvailabilities(string $childId, array $slotIds): void {
        if (empty($slotIds)) return;
        $placeholders = implode(',', array_fill(0, count($slotIds), '?'));
        $delStmt = $this->pdo->prepare("DELETE FROM availabilities WHERE child_id = ? AND slot_id IN ($placeholders)");
        $delStmt->execute(array_merge([$childId], $slotIds));
    }

    public function insertAvailabilities(string $childId, array $availabilities): void {
        if (empty($availabilities)) return;
        $insStmt = $this->pdo->prepare('INSERT INTO availabilities (id, child_id, slot_id, is_available, submitted_at) VALUES (?, ?, ?, ?, ?)');
        $now = date('Y-m-d H:i:s');
        foreach ($availabilities as $a) {
            $insStmt->execute([generate_uuid(), $childId, $a['slotId'], $a['isAvailable'] ? 1 : 0, $now]);
        }
    }

    public function deletePresences(string $childId, array $slotIds): void {
        if (empty($slotIds)) return;
        $placeholders = implode(',', array_fill(0, count($slotIds), '?'));
        $delPresStmt = $this->pdo->prepare("DELETE FROM child_presences WHERE child_id = ? AND slot_id IN ($placeholders)");
        $delPresStmt->execute(array_merge([$childId], $slotIds));
    }

    public function insertPresences(string $childId, array $availabilities): void {
        if (empty($availabilities)) return;
        $insPresStmt = $this->pdo->prepare('INSERT INTO child_presences (id, child_id, slot_id, is_present) VALUES (?, ?, ?, ?)');
        foreach ($availabilities as $a) {
            $isPresent = empty($a['isAbsent']) ? 1 : 0;
            $insPresStmt->execute([generate_uuid(), $childId, $a['slotId'], $isPresent]);
        }
    }

    public function beginTransaction(): void {
        $this->pdo->beginTransaction();
    }

    public function commit(): void {
        $this->pdo->commit();
    }

    public function rollBack(): void {
        $this->pdo->rollBack();
    }
}
