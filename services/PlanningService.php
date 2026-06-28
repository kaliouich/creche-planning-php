<?php
require_once __DIR__ . '/../repositories/PlanningRepository.php';

class PlanningService {
    private PlanningRepository $repo;

    public function __construct(PlanningRepository $repo) {
        $this->repo = $repo;
    }

    /**
     * Assemble all planning data for a week efficiently using O(1) queries.
     */
    public function getWeekPlanning(string $weekId, ?string $childIdFilter = null): ?array {
        $week = $this->repo->getWeekById($weekId);
        if (!$week) {
            return null;
        }

        $slots = $this->repo->getSlotsForWeek($weekId);
        $slotIds = array_column($slots, 'id');

        if (empty($slotIds)) {
            return $this->formatEmptyWeek($week);
        }

        $allAvails = $this->repo->getAvailabilitiesForSlots($slotIds, $childIdFilter);
        $allPresences = $this->repo->getPresencesForSlots($slotIds);
        $allAssignments = $this->repo->getAssignmentsForSlots($slotIds);

        return $this->assembleWeekData($week, $slots, $allAvails, $allPresences, $allAssignments);
    }

    private function formatEmptyWeek(array $week): array {
        return [
            'id'                 => $week['id'],
            'weekNumber'         => (int) $week['week_number'],
            'year'               => (int) $week['year'],
            'status'             => $week['status'],
            'needsRecalculation' => (bool) $week['needs_recalculation'],
            'createdAt'          => $week['created_at'],
            'updatedAt'          => $week['updated_at'],
            'slots'              => [],
        ];
    }

    private function assembleWeekData(array $week, array $slots, array $allAvails, array $allPresences, array $allAssignments): array {
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
                'isOfferedForExchange' => (bool) ($a['is_offered_for_exchange'] ?? false),
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

        return [
            'id'                  => $week['id'],
            'weekNumber'          => (int) $week['week_number'],
            'year'                => (int) $week['year'],
            'status'              => $week['status'],
            'needsRecalculation'  => (bool) $week['needs_recalculation'],
            'createdAt'           => $week['created_at'],
            'updatedAt'           => $week['updated_at'],
            'slots'               => $formattedSlots,
        ];
    }

    /**
     * Génère l'allocation de la semaine (gère la transaction et l'algorithme)
     */
    public function generateWeek(string $weekId): array {
        require_once __DIR__ . '/allocation.php';
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

            return $result;
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
