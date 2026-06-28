<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

class PlanningRepository {
    private PDO $pdo;

    public function __construct() {
        $this->pdo = get_db();
    }

    public function getWeekById(string $weekId): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM planning_weeks WHERE id = ?');
        $stmt->execute([$weekId]);
        $week = $stmt->fetch(PDO::FETCH_ASSOC);
        return $week ?: null;
    }

    public function getSlotsForWeek(string $weekId): array {
        $stmt = $this->pdo->prepare('SELECT * FROM slots WHERE planning_week_id = ?');
        $stmt->execute([$weekId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAvailabilitiesForSlots(array $slotIds, ?string $childIdFilter = null): array {
        if (empty($slotIds)) return [];
        $placeholders = implode(',', array_fill(0, count($slotIds), '?'));
        
        if ($childIdFilter) {
            $stmt = $this->pdo->prepare("SELECT * FROM availabilities WHERE slot_id IN ($placeholders) AND child_id = ?");
            $stmt->execute(array_merge($slotIds, [$childIdFilter]));
        } else {
            $stmt = $this->pdo->prepare("
                SELECT a.*, c.id as c_id, c.first_name as c_first_name, c.last_name as c_last_name, c.age_group as c_age_group 
                FROM availabilities a 
                JOIN children c ON a.child_id = c.id 
                WHERE a.slot_id IN ($placeholders)
            ");
            $stmt->execute($slotIds);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPresencesForSlots(array $slotIds): array {
        if (empty($slotIds)) return [];
        $placeholders = implode(',', array_fill(0, count($slotIds), '?'));
        $stmt = $this->pdo->prepare("
            SELECT cp.*, c.id as c_id, c.first_name as c_first_name, c.last_name as c_last_name, c.age_group as c_age_group 
            FROM child_presences cp 
            JOIN children c ON cp.child_id = c.id 
            WHERE cp.slot_id IN ($placeholders)
        ");
        $stmt->execute($slotIds);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAssignmentsForSlots(array $slotIds): array {
        if (empty($slotIds)) return [];
        $placeholders = implode(',', array_fill(0, count($slotIds), '?'));
        $stmt = $this->pdo->prepare("
            SELECT a.*, c.id as c_id, c.first_name as c_first_name, c.last_name as c_last_name, c.age_group as c_age_group, c.parent_id as c_parent_id, c.parent1_first_name, c.parent2_first_name 
            FROM assignments a 
            JOIN children c ON a.child_id = c.id 
            WHERE a.slot_id IN ($placeholders)
        ");
        $stmt->execute($slotIds);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
