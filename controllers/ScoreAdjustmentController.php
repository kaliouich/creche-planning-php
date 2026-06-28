<?php

class ScoreAdjustmentController {
    public function getScoreMatrix(): void {
        $user = require_auth();
        require_role($user, 'ADMIN');

        $pdo = get_db();

        $stmt = $pdo->query("SELECT id, week_number, year FROM planning_weeks WHERE status = 'PUBLISHED' ORDER BY year ASC, week_number ASC");
        $weeks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt = $pdo->query("
            SELECT c.id, c.first_name, c.last_name, 
                   u.id as parent_id, u.first_name as parent_first_name, u.last_name as parent_last_name
            FROM children c
            JOIN users u ON c.parent_id = u.id
            WHERE c.is_active = 1
            ORDER BY c.last_name ASC, c.first_name ASC
        ");
        $childrenRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $childIds = array_column($childrenRows, 'id');
        $historiesByChild = [];
        $currentScoresByChild = [];

        if (!empty($childIds)) {
            $ph = implode(',', array_fill(0, count($childIds), '?'));
            
            // Batch fetch score histories
            $hStmt = $pdo->prepare("SELECT child_id, week_number, year, permanences_done, score_before, permanences_due, score_after FROM score_histories WHERE child_id IN ($ph)");
            $hStmt->execute($childIds);
            $allHistories = $hStmt->fetchAll(PDO::FETCH_ASSOC);
            
            foreach ($allHistories as $h) {
                $historiesByChild[$h['child_id']][] = $h;
            }

            // Batch fetch current scores (which relies on score_histories latest entry, wait, get_current_score does a query. Let's keep it simple or do a batch query for scores too).
            // Actually get_current_score() is O(1) query per child. Let's fix that N+1 as well!
            $sStmt = $pdo->prepare("
                SELECT sh.child_id, sh.score_after 
                FROM score_histories sh
                INNER JOIN (
                    SELECT child_id, MAX(snapshot_at) as max_snapshot 
                    FROM score_histories 
                    WHERE child_id IN ($ph)
                    GROUP BY child_id
                ) latest ON sh.child_id = latest.child_id AND sh.snapshot_at = latest.max_snapshot
            ");
            $sStmt->execute($childIds);
            foreach ($sStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
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

        json_response([
            'weeks' => array_map(fn($w) => [
                'id' => $w['id'],
                'weekNumber' => (int)$w['week_number'],
                'year' => (int)$w['year']
            ], $weeks),
            'children' => $children
        ]);
    }

    public function patchScoreAdjustment(): void {
        $user = require_auth();
        verify_csrf();
        require_role($user, 'ADMIN');

        $body = get_json_body();
        
        $childId = $body['childId'] ?? null;
        $weekNumber = $body['weekNumber'] ?? null;
        $year = $body['year'] ?? null;
        $permanencesDone = $body['permanencesDone'] ?? null;

        if (!$childId || !isset($weekNumber, $year, $permanencesDone)) {
            json_response(['error' => 'Paramètres manquants'], 400);
            return;
        }

        $pdo = get_db();
        
        $stmt = $pdo->prepare("SELECT id FROM score_histories WHERE child_id = ? AND week_number = ? AND year = ?");
        $stmt->execute([$childId, $weekNumber, $year]);
        $historyId = $stmt->fetchColumn();

        if ($historyId) {
            $update = $pdo->prepare("UPDATE score_histories SET permanences_done = ? WHERE id = ?");
            $update->execute([$permanencesDone, $historyId]);
        } else {
            $insert = $pdo->prepare("
                INSERT INTO score_histories (id, child_id, week_number, year, score_before, permanences_done, permanences_due, score_after)
                VALUES (?, ?, ?, ?, 0, ?, 0, 0)
            ");
            $insert->execute([generate_uuid(), $childId, $weekNumber, $year, $permanencesDone]);
        }

        recalculate_child_score_history($childId);

        $newScore = get_current_score($childId);
        
        json_response(['success' => true, 'newScore' => $newScore]);
    }
}
