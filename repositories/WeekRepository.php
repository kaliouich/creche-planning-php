<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

class WeekRepository {
    private PDO $pdo;

    public function __construct() {
        $this->pdo = get_db();
    }

    public function findAll(bool $openOnly = false): array {
        $where = '';
        if ($openOnly) {
            $where = "WHERE w.status IN ('OPEN_TO_PARENTS', 'PUBLISHED')";
        }

        $stmt = $this->pdo->query("
            SELECT w.*, 
                   EXISTS(SELECT 1 FROM assignments a JOIN slots s ON a.slot_id = s.id WHERE s.planning_week_id = w.id) as has_assignments
            FROM planning_weeks w 
            $where
            ORDER BY w.year DESC, w.week_number DESC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findByWeekAndYear(int $weekNumber, int $year): ?array {
        $stmt = $this->pdo->prepare('SELECT id FROM planning_weeks WHERE week_number = ? AND year = ?');
        $stmt->execute([$weekNumber, $year]);
        $week = $stmt->fetch(PDO::FETCH_ASSOC);
        return $week ?: null;
    }

    public function findById(string $weekId): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM planning_weeks WHERE id = ?');
        $stmt->execute([$weekId]);
        $week = $stmt->fetch(PDO::FETCH_ASSOC);
        return $week ?: null;
    }

    public function createWeek(array $data): string {
        $weekId = generate_uuid();
        $now = date('Y-m-d H:i:s');

        $stmt = $this->pdo->prepare('INSERT INTO planning_weeks (id, week_number, year, status, needs_recalculation, created_at, updated_at) VALUES (?, ?, ?, ?, 0, ?, ?)');
        $stmt->execute([$weekId, $data['weekNumber'], $data['year'], 'PREPARATION', $now, $now]);
        
        return $weekId;
    }

    public function createSlotsForWeek(string $weekId): array {
        $days = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY'];
        $halfDays = ['MORNING', 'AFTERNOON'];
        $slotStmt = $this->pdo->prepare('INSERT INTO slots (id, planning_week_id, day_of_week, half_day, slot_type, required_parents) VALUES (?, ?, ?, ?, ?, ?)');

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
        return $slots;
    }

    public function updateStatus(string $weekId, string $status): void {
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare('UPDATE planning_weeks SET status = ?, updated_at = ? WHERE id = ?');
        $stmt->execute([$status, $now, $weekId]);
    }

    public function deleteAssignmentsForSlot(string $slotId): void {
        $this->pdo->prepare('DELETE FROM assignments WHERE slot_id = ?')->execute([$slotId]);
    }

    public function slotExistsInWeek(string $slotId, string $weekId): bool {
        $stmt = $this->pdo->prepare('SELECT id FROM slots WHERE id = ? AND planning_week_id = ?');
        $stmt->execute([$slotId, $weekId]);
        return (bool)$stmt->fetch();
    }

    public function createManualAssignment(string $childId, string $slotId): void {
        $insertStmt = $this->pdo->prepare('INSERT INTO assignments (id, child_id, slot_id, is_manual) VALUES (?, ?, ?, 1)');
        $insertStmt->execute([generate_uuid(), $childId, $slotId]);
    }

    public function deleteWeek(string $weekId): void {
        $stmt = $this->pdo->prepare('DELETE FROM planning_weeks WHERE id = ?');
        $stmt->execute([$weekId]);
    }

    public function deleteScoreHistories(int $weekNumber, int $year): void {
        $stmt = $this->pdo->prepare('DELETE FROM score_histories WHERE week_number = ? AND year = ?');
        $stmt->execute([$weekNumber, $year]);
    }

    public function getAllChildrenIds(): array {
        $childStmt = $this->pdo->query('SELECT id FROM children');
        return $childStmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function getActiveParents(): array {
        $stmt = $this->pdo->query('SELECT id, first_name, email, second_email FROM users WHERE role = "PARENT" AND is_active = 1');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getSlotsForWeek(string $weekId): array {
        $stmt = $this->pdo->prepare('SELECT * FROM slots WHERE planning_week_id = ? ORDER BY 
                               CASE day_of_week WHEN "MONDAY" THEN 1 WHEN "TUESDAY" THEN 2 WHEN "WEDNESDAY" THEN 3 WHEN "THURSDAY" THEN 4 WHEN "FRIDAY" THEN 5 ELSE 6 END,
                               CASE half_day WHEN "MORNING" THEN 1 ELSE 2 END');
        $stmt->execute([$weekId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getActiveChildrenWithDefaults(): array {
        $stmt = $this->pdo->query('SELECT c.id, c.first_name, c.age_group, d.day_of_week, d.half_day 
                             FROM children c 
                             LEFT JOIN child_default_presences d ON c.id = d.child_id 
                             WHERE c.is_active = 1');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getPresencesForWeek(string $weekId): array {
        $stmt = $this->pdo->prepare('SELECT cp.slot_id, cp.child_id, cp.is_present FROM child_presences cp JOIN slots s ON cp.slot_id = s.id WHERE s.planning_week_id = ?');
        $stmt->execute([$weekId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAssignmentsForWeek(string $weekId): array {
        $stmt = $this->pdo->prepare('SELECT a.slot_id, c.first_name, c.parent1_first_name, c.parent2_first_name, a.is_manual FROM assignments a JOIN children c ON a.child_id = c.id JOIN slots s ON a.slot_id = s.id WHERE s.planning_week_id = ?');
        $stmt->execute([$weekId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAvailabilitiesForWeek(string $weekId): array {
        $stmt = $this->pdo->prepare('SELECT a.slot_id, c.first_name FROM availabilities a JOIN children c ON a.child_id = c.id JOIN slots s ON a.slot_id = s.id WHERE s.planning_week_id = ? AND a.is_available = 1');
        $stmt->execute([$weekId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function checkIsForcedAssignment(string $parentId, string $weekId): bool {
        $stmtForced = $this->pdo->prepare('
            SELECT 1 
            FROM assignments a
            JOIN children c ON a.child_id = c.id
            JOIN slots s ON a.slot_id = s.id
            LEFT JOIN availabilities av ON av.slot_id = s.id AND av.child_id = c.id
            WHERE c.parent_id = ? AND s.planning_week_id = ?
              AND (av.is_available = 0 OR av.is_available IS NULL)
        ');
        $stmtForced->execute([$parentId, $weekId]);
        return (bool)$stmtForced->fetch();
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
