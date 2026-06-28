<?php
require_once __DIR__ . '/../db.php';

class ScoreAdjustmentRepository {
    private PDO $pdo;

    public function __construct() {
        $this->pdo = get_db();
    }

    public function getPublishedWeeks(): array {
        $stmt = $this->pdo->query("SELECT id, week_number, year FROM planning_weeks WHERE status = 'PUBLISHED' ORDER BY year ASC, week_number ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getAllChildrenWithParents(): array {
        $stmt = $this->pdo->query("
            SELECT c.id, c.first_name, c.last_name, 
                   u.id as parent_id, u.first_name as parent_first_name, u.last_name as parent_last_name
            FROM children c
            JOIN users u ON c.parent_id = u.id
            WHERE c.is_active = 1
            ORDER BY c.last_name ASC, c.first_name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getScoreHistoriesForChildren(array $childIds): array {
        if (empty($childIds)) return [];
        $ph = implode(',', array_fill(0, count($childIds), '?'));
        $stmt = $this->pdo->prepare("SELECT child_id, week_number, year, permanences_done, score_before, permanences_due, score_after FROM score_histories WHERE child_id IN ($ph)");
        $stmt->execute($childIds);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getLatestScoresForChildren(array $childIds): array {
        if (empty($childIds)) return [];
        $ph = implode(',', array_fill(0, count($childIds), '?'));
        $stmt = $this->pdo->prepare("
            SELECT sh.child_id, sh.score_after 
            FROM score_histories sh
            INNER JOIN (
                SELECT child_id, MAX(snapshot_at) as max_snapshot 
                FROM score_histories 
                WHERE child_id IN ($ph)
                GROUP BY child_id
            ) latest ON sh.child_id = latest.child_id AND sh.snapshot_at = latest.max_snapshot
        ");
        $stmt->execute($childIds);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getScoreHistory(string $childId, int $weekNumber, int $year): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM score_histories WHERE child_id = ? AND week_number = ? AND year = ?");
        $stmt->execute([$childId, $weekNumber, $year]);
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        return $res ?: null;
    }

    public function updateScoreHistory(string $id, float $scoreBefore, float $scoreAfter): void {
        $stmt = $this->pdo->prepare("UPDATE score_histories SET score_before = ?, score_after = ? WHERE id = ?");
        $stmt->execute([$scoreBefore, $scoreAfter, $id]);
    }

    public function getFutureScoreHistories(string $childId, int $year, int $weekNumber): array {
        $stmt = $this->pdo->prepare("
            SELECT * FROM score_histories 
            WHERE child_id = ? AND (year > ? OR (year = ? AND week_number > ?))
            ORDER BY year ASC, week_number ASC
        ");
        $stmt->execute([$childId, $year, $year, $weekNumber]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
