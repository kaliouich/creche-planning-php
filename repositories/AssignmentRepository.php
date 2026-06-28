<?php

class AssignmentRepository {
    private PDO $pdo;

    public function __construct() {
        $this->pdo = get_db();
    }

    public function getPublishedAssignmentsForChild(string $childId): array {
        $stmt = $this->pdo->prepare("
            SELECT pw.id as weekId, pw.week_number as weekNumber, pw.year, 
                   s.id as slotId, s.day_of_week as dayOfWeek, s.half_day as halfDay, 
                   a.id as assignmentId, 
                   EXISTS(SELECT 1 FROM exchange_offers eo WHERE eo.assignment_id = a.id AND eo.status = 'PENDING') as isOfferedForExchange
            FROM assignments a
            JOIN slots s ON a.slot_id = s.id
            JOIN planning_weeks pw ON s.planning_week_id = pw.id
            WHERE a.child_id = ? AND pw.status = 'PUBLISHED'
            ORDER BY pw.year ASC, pw.week_number ASC,
                     CASE s.day_of_week 
                         WHEN 'MONDAY' THEN 1
                         WHEN 'TUESDAY' THEN 2
                         WHEN 'WEDNESDAY' THEN 3
                         WHEN 'THURSDAY' THEN 4
                         WHEN 'FRIDAY' THEN 5
                         ELSE 6
                     END ASC,
                     CASE s.half_day
                         WHEN 'MORNING' THEN 1
                         WHEN 'AFTERNOON' THEN 2
                         ELSE 3
                     END ASC
        ");
        $stmt->execute([$childId]);
        
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Map boolean from EXISTS properly (returns 0 or 1 usually)
        foreach ($results as &$row) {
            $row['isOfferedForExchange'] = (bool)$row['isOfferedForExchange'];
        }
        
        return $results;
    }
}
