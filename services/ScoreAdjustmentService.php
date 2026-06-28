<?php
require_once __DIR__ . '/../repositories/ScoreAdjustmentRepository.php';

class ScoreAdjustmentService {
    private ScoreAdjustmentRepository $repo;

    public function __construct() {
        $this->repo = new ScoreAdjustmentRepository();
    }

    public function getScoreMatrix(): array {
        $weeks = $this->repo->getPublishedWeeks();
        $childrenRows = $this->repo->getAllChildrenWithParents();
        
        $childIds = array_column($childrenRows, 'id');
        $historiesByChild = [];
        $currentScoresByChild = [];

        if (!empty($childIds)) {
            $allHistories = $this->repo->getScoreHistoriesForChildren($childIds);
            foreach ($allHistories as $h) {
                $historiesByChild[$h['child_id']][] = $h;
            }

            $currentScores = $this->repo->getLatestScoresForChildren($childIds);
            foreach ($currentScores as $s) {
                $currentScoresByChild[$s['child_id']] = (float)$s['score_after'];
            }
        }

        $children = [];
        foreach ($childrenRows as $row) {
            $childId = $row['id'];
            $historiesRaw = $historiesByChild[$childId] ?? [];
            
            $histories = [];
            foreach ($historiesRaw as $h) {
                $key = $h['year'] . '-' . $h['week_number'];
                $histories[$key] = [
                    'permanencesDone' => (float)$h['permanences_done'],
                    'permanencesDue' => (float)$h['permanences_due'],
                    'scoreBefore' => (float)$h['score_before'],
                    'scoreAfter' => (float)$h['score_after']
                ];
            }

            $currentScore = $currentScoresByChild[$childId] ?? 0.0;

            $children[] = [
                'id' => $childId,
                'firstName' => $row['first_name'],
                'lastName' => $row['last_name'],
                'parentFirstName' => $row['parent_first_name'],
                'parentLastName' => $row['parent_last_name'],
                'score' => $currentScore,
                'histories' => $histories
            ];
        }

        return [
            'weeks' => array_map(fn($w) => [
                'id' => $w['id'],
                'weekNumber' => (int)$w['week_number'],
                'year' => (int)$w['year']
            ], $weeks),
            'children' => $children
        ];
    }

    public function patchScoreAdjustment(string $childId, int $weekNumber, int $year, float $delta): void {
        $targetHistory = $this->repo->getScoreHistory($childId, $weekNumber, $year);
        if (!$targetHistory) {
            throw new Exception("Historique introuvable pour cet enfant et cette semaine.");
        }

        // Apply delta
        $newScoreBefore = (float)$targetHistory['score_before'] + $delta;
        $newScoreAfter = (float)$targetHistory['score_after'] + $delta;
        $this->repo->updateScoreHistory($targetHistory['id'], $newScoreBefore, $newScoreAfter);

        // Propagate delta to future weeks
        $futureHistories = $this->repo->getFutureScoreHistories($childId, $year, $weekNumber);
        foreach ($futureHistories as $fh) {
            $fhScoreBefore = (float)$fh['score_before'] + $delta;
            $fhScoreAfter = (float)$fh['score_after'] + $delta;
            $this->repo->updateScoreHistory($fh['id'], $fhScoreBefore, $fhScoreAfter);
        }
    }
}
