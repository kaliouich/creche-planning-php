<?php

class PlanningController {
    public function handle(string $route, string $method): void {
        if (preg_match('#^generate/([a-f0-9\-]+)$#', $route, $m) && $method === 'POST') {
            $this->generate($m[1]);
        } elseif (preg_match('#^([a-f0-9\-]+)$#', $route, $m) && $method === 'GET') {
            $this->get($m[1]);
        } else {
            json_response(['error' => 'Route non trouvée'], 404);
        }
    }

    private function get(string $weekId): void {
        $user = require_auth();

        if (!validate_uuid($weekId)) {
            json_response(['error' => 'ID invalide'], 400);
            return;
        }

        $pdo = get_db();

        $stmt = $pdo->prepare('SELECT * FROM planning_weeks WHERE id = ?');
        $stmt->execute([$weekId]);
        $week = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$week) {
            json_response(['error' => 'Semaine introuvable'], 404);
            return;
        }

        $stmt = $pdo->prepare('SELECT * FROM slots WHERE planning_week_id = ?');
        $stmt->execute([$weekId]);
        $slots = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $childIdFilter = $_GET['childId'] ?? null;

        $slotIds = array_column($slots, 'id');
        
        if (empty($slotIds)) {
            json_response([
                'id'         => $week['id'],
                'weekNumber' => (int) $week['week_number'],
                'year'       => (int) $week['year'],
                'status'     => $week['status'],
                'slots'      => [],
            ]);
            return;
        }

        $placeholders = implode(',', array_fill(0, count($slotIds), '?'));

        if ($childIdFilter && validate_uuid($childIdFilter)) {
            $availStmt = $pdo->prepare("SELECT * FROM availabilities WHERE slot_id IN ($placeholders) AND child_id = ?");
            $availStmt->execute(array_merge($slotIds, [$childIdFilter]));
        } else {
            $availStmt = $pdo->prepare("SELECT a.*, c.id as c_id, c.first_name as c_first_name, c.last_name as c_last_name, c.age_group as c_age_group FROM availabilities a JOIN children c ON a.child_id = c.id WHERE a.slot_id IN ($placeholders)");
            $availStmt->execute($slotIds);
        }
        $allAvails = $availStmt->fetchAll(PDO::FETCH_ASSOC);

        $presStmt = $pdo->prepare("SELECT cp.*, c.id as c_id, c.first_name as c_first_name, c.last_name as c_last_name, c.age_group as c_age_group FROM child_presences cp JOIN children c ON cp.child_id = c.id WHERE cp.slot_id IN ($placeholders)");
        $presStmt->execute($slotIds);
        $allPresences = $presStmt->fetchAll(PDO::FETCH_ASSOC);

        $assignStmt = $pdo->prepare("SELECT a.*, c.id as c_id, c.first_name as c_first_name, c.last_name as c_last_name, c.age_group as c_age_group, c.parent_id as c_parent_id, c.parent1_first_name, c.parent2_first_name FROM assignments a JOIN children c ON a.child_id = c.id WHERE a.slot_id IN ($placeholders)");
        $assignStmt->execute($slotIds);
        $allAssignments = $assignStmt->fetchAll(PDO::FETCH_ASSOC);

        $availBySlot = [];
        foreach ($allAvails as $a) {
            $entry = [
                'id'          => $a['id'],
                'isAvailable' => (bool) $a['is_available'],
                'childId'     => $a['child_id'],
            ];
            if (isset($a['c_id'])) {
                $entry['child'] = [
                    'id'        => $a['c_id'],
                    'firstName' => $a['c_first_name'],
                    'lastName'  => $a['c_last_name'],
                    'ageGroup'  => $a['c_age_group'],
                ];
            }
            $availBySlot[$a['slot_id']][] = $entry;
        }

        $presBySlot = [];
        foreach ($allPresences as $p) {
            $presBySlot[$p['slot_id']][] = [
                'id'        => $p['id'],
                'isPresent' => (bool) $p['is_present'],
                'childId'   => $p['child_id'],
                'child'     => [
                    'id'        => $p['c_id'],
                    'firstName' => $p['c_first_name'],
                    'lastName'  => $p['c_last_name'],
                    'ageGroup'  => $p['c_age_group'],
                ],
            ];
        }

        $assignBySlot = [];
        foreach ($allAssignments as $a) {
            $assignBySlot[$a['slot_id']][] = [
                'id'       => $a['id'],
                'isManual' => (bool) $a['is_manual'],
                'childId'  => $a['child_id'],
                'child'    => [
                    'id'        => $a['c_id'],
                    'firstName' => $a['c_first_name'],
                    'lastName'  => $a['c_last_name'],
                    'parentId'  => $a['c_parent_id'],
                    'ageGroup'  => $a['c_age_group'],
                ],
                'parent'   => [
                    'id'        => $a['c_parent_id'],
                    'firstName' => $a['parent1_first_name'] ?? '',
                    'lastName'  => $a['parent2_first_name'] ?? '',
                ],
            ];
        }

        $formattedSlots = [];
        foreach ($slots as $s) {
            $formattedSlots[] = [
                'id'              => $s['id'],
                'planningWeekId'  => $s['planning_week_id'],
                'dayOfWeek'       => $s['day_of_week'],
                'halfDay'         => $s['half_day'],
                'slotType'        => $s['slot_type'],
                'requiredParents' => (int) $s['required_parents'],
                'availabilities'  => $availBySlot[$s['id']] ?? [],
                'childPresences'  => $presBySlot[$s['id']] ?? [],
                'assignments'     => $assignBySlot[$s['id']] ?? [],
            ];
        }

        json_response([
            'id'                  => $week['id'],
            'weekNumber'          => (int) $week['week_number'],
            'year'                => (int) $week['year'],
            'status'              => $week['status'],
            'needsRecalculation'  => (bool) $week['needs_recalculation'],
            'createdAt'           => $week['created_at'],
            'updatedAt'           => $week['updated_at'],
            'slots'               => $formattedSlots,
        ]);
    }

    private function generate(string $weekId): void {
        $user = require_auth();
        verify_csrf();
        require_role($user, 'ADMIN');

        if (!validate_uuid($weekId)) {
            json_response(['error' => 'ID invalide'], 400);
            return;
        }

        require_week_status($weekId, ['OPEN_TO_PARENTS', 'CALCULATION']);


        $pdo = get_db();
        $pdo->beginTransaction();

        try {
            // Verrouiller la semaine pour empêcher les exécutions concurrentes
            $lockStmt = $pdo->prepare('SELECT id FROM planning_weeks WHERE id = ? FOR UPDATE');
            $lockStmt->execute([$weekId]);

            $stmt = $pdo->prepare("
                SELECT s.id, s.day_of_week, s.half_day, s.required_parents
                FROM slots s
                WHERE s.planning_week_id = ? AND s.slot_type NOT IN ('CLOSED', 'NO_PERM')
            ");
            $stmt->execute([$weekId]);
            $rawSlots = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $slotIds = array_column($rawSlots, 'id');
            $algoSlots = [];

            if (!empty($slotIds)) {
                $ph = implode(',', array_fill(0, count($slotIds), '?'));
                $availStmt = $pdo->prepare("SELECT slot_id, child_id FROM availabilities WHERE slot_id IN ($ph) AND is_available = 1");
                $availStmt->execute($slotIds);
                $allAvails = $availStmt->fetchAll(PDO::FETCH_ASSOC);

                $availBySlot = [];
                foreach ($allAvails as $a) {
                    $availBySlot[$a['slot_id']][] = $a['child_id'];
                }

                foreach ($rawSlots as $s) {
                    $algoSlots[] = [
                        'slotId'             => $s['id'],
                        'dayOfWeek'          => $s['day_of_week'],
                        'halfDay'            => $s['half_day'],
                        'requiredParents'    => (int) $s['required_parents'],
                        'availableParentIds' => $availBySlot[$s['id']] ?? [],
                    ];
                }
            }

            // Collect all unique child IDs from availabilities
            $uniqueChildIds = [];
            foreach ($algoSlots as $s) {
                foreach ($s['availableParentIds'] as $cid) {
                    $uniqueChildIds[$cid] = true;
                }
            }

            // Batch-fetch child names and scores (fixes N+1)
            $parentScores = [];
            if (!empty($uniqueChildIds)) {
                $childIds = array_keys($uniqueChildIds);
                $ph = implode(',', array_fill(0, count($childIds), '?'));

                // Batch: child names
                $nameStmt = $pdo->prepare("SELECT id, first_name, last_name FROM children WHERE id IN ($ph)");
                $nameStmt->execute($childIds);
                $childNames = [];
                foreach ($nameStmt->fetchAll(PDO::FETCH_ASSOC) as $c) {
                    $childNames[$c['id']] = $c;
                }

                // Batch: latest scores
                $scoreStmt = $pdo->prepare("
                    SELECT sh.child_id, sh.score_after 
                    FROM score_histories sh
                    INNER JOIN (
                        SELECT child_id, MAX(snapshot_at) as max_snapshot 
                        FROM score_histories 
                        WHERE child_id IN ($ph)
                        GROUP BY child_id
                    ) latest ON sh.child_id = latest.child_id AND sh.snapshot_at = latest.max_snapshot
                ");
                $scoreStmt->execute($childIds);
                $scoreMap = [];
                foreach ($scoreStmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
                    $scoreMap[$s['child_id']] = (float) $s['score_after'];
                }

                foreach ($childIds as $childId) {
                    $child = $childNames[$childId] ?? ['first_name' => '', 'last_name' => ''];
                    $parentScores[] = [
                        'parentId'  => $childId,
                        'firstName' => $child['first_name'],
                        'lastName'  => $child['last_name'],
                        'score'     => $scoreMap[$childId] ?? 0.0,
                    ];
                }
            }

            $result = allocate($algoSlots, $parentScores);

            if (!empty($slotIds)) {
                $ph = implode(',', array_fill(0, count($slotIds), '?'));
                $pdo->prepare("DELETE FROM assignments WHERE slot_id IN ($ph) AND is_manual = 0")->execute($slotIds);
            }

            $insStmt = $pdo->prepare('INSERT INTO assignments (id, child_id, slot_id, is_manual, assigned_at) VALUES (?, ?, ?, 0, ?)');
            $now = date('Y-m-d H:i:s');
            foreach ($result['assignments'] as $a) {
                $insStmt->execute([generate_uuid(), $a['parentId'], $a['slotId'], $now]);
            }

            $pdo->prepare("UPDATE planning_weeks SET needs_recalculation = 0, status = 'CALCULATION' WHERE id = ?")->execute([$weekId]);

            $pdo->commit();

            json_response([
                'message'       => 'Planning généré avec succès',
                'stats'         => $result['stats'],
                'unfilledSlots' => $result['unfilledSlots'],
            ]);
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
