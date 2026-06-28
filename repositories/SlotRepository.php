<?php

class SlotRepository {
    private PDO $pdo;

    public function __construct() {
        $this->pdo = get_db();
    }

    public function findByIdWithWeekStatus(string $slotId): ?array {
        $stmt = $this->pdo->prepare('
            SELECT s.*, pw.status as week_status 
            FROM slots s 
            JOIN planning_weeks pw ON s.planning_week_id = pw.id 
            WHERE s.id = ?
        ');
        $stmt->execute([$slotId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function updateSlotType(string $slotId, string $slotType, int $requiredParents): void {
        $stmt = $this->pdo->prepare('UPDATE slots SET slot_type = ?, required_parents = ? WHERE id = ?');
        $stmt->execute([$slotType, $requiredParents, $slotId]);
    }
}
