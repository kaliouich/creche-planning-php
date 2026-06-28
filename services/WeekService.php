<?php
require_once __DIR__ . '/../repositories/WeekRepository.php';
require_once __DIR__ . '/../services/EmailService.php';
require_once __DIR__ . '/../services/score.php';

class WeekService {
    private WeekRepository $repo;

    public function __construct() {
        $this->repo = new WeekRepository();
    }

    public function getAllWeeks(bool $openOnly = false): array {
        $weeks = $this->repo->findAll($openOnly);
        
        foreach ($weeks as &$w) {
            $w['needsRecalculation'] = (bool) $w['needs_recalculation'];
            $w['hasAssignments'] = (bool) $w['has_assignments'];
            $w['weekNumber'] = (int) $w['week_number'];
            unset($w['needs_recalculation'], $w['week_number'], $w['has_assignments']);
        }

        return $weeks;
    }

    public function createWeek(array $data): array {
        $weekNumber = (int) ($data['weekNumber'] ?? 0);
        $year = (int) ($data['year'] ?? 0);

        if ($weekNumber < 1 || $weekNumber > 53 || $year < 2024 || $year > 2100) {
            throw new InvalidArgumentException('Données invalides');
        }

        if ($this->repo->findByWeekAndYear($weekNumber, $year)) {
            throw new RuntimeException('Cette semaine existe déjà', 409);
        }

        $this->repo->beginTransaction();
        try {
            $weekId = $this->repo->createWeek(['weekNumber' => $weekNumber, 'year' => $year]);
            $slots = $this->repo->createSlotsForWeek($weekId);
            
            $now = date('Y-m-d H:i:s');
            $this->repo->commit();

            return [
                'id' => $weekId,
                'weekNumber' => $weekNumber,
                'year' => $year,
                'status' => 'PREPARATION',
                'needsRecalculation' => false,
                'createdAt' => $now,
                'updatedAt' => $now,
                'slots' => $slots,
            ];
        } catch (Exception $e) {
            $this->repo->rollBack();
            throw $e;
        }
    }

    public function updateStatus(string $weekId, array $data): array {
        if (!validate_uuid($weekId)) {
            throw new InvalidArgumentException('ID invalide');
        }

        $newStatus = $data['status'] ?? '';
        $validStatuses = ['PREPARATION', 'OPEN_TO_PARENTS', 'CALCULATION', 'PUBLISHED'];
        if (!in_array($newStatus, $validStatuses)) {
            throw new InvalidArgumentException('Statut invalide');
        }

        $week = $this->repo->findById($weekId);
        if (!$week) {
            throw new RuntimeException('Semaine introuvable', 404);
        }

        $allowedTransitions = [
            'PREPARATION'     => ['OPEN_TO_PARENTS'],
            'OPEN_TO_PARENTS' => ['PUBLISHED', 'PREPARATION'],
            'CALCULATION'     => ['PUBLISHED', 'PREPARATION', 'OPEN_TO_PARENTS'],
            'PUBLISHED'       => ['PREPARATION']
        ];

        $allowed = $allowedTransitions[$week['status']] ?? [];
        if (!in_array($newStatus, $allowed)) {
            throw new RuntimeException(json_encode([
                'error' => 'Transition de statut invalide',
                'current' => $week['status'],
                'requested' => $newStatus,
                'allowed' => $allowed,
            ]), 400);
        }

        if ($newStatus === 'PUBLISHED') {
            snapshot_scores_for_week($weekId, (int) $week['week_number'], (int) $week['year']);
        }

        $this->repo->updateStatus($weekId, $newStatus);

        if ($newStatus === 'OPEN_TO_PARENTS' || $newStatus === 'PUBLISHED') {
            $this->notifyParentsForWeek($newStatus, (int) $week['week_number'], $weekId);
        }

        return [
            'id' => $weekId,
            'weekNumber' => (int) $week['week_number'],
            'year' => (int) $week['year'],
            'status' => $newStatus,
            'needsRecalculation' => (bool) $week['needs_recalculation'],
            'createdAt' => $week['created_at'],
            'updatedAt' => date('Y-m-d H:i:s'),
        ];
    }

    public function updateAssignments(string $weekId, array $data): void {
        if (!validate_uuid($weekId)) {
            throw new InvalidArgumentException('ID invalide');
        }

        if (!isset($data['slots']) || !is_array($data['slots'])) {
            throw new InvalidArgumentException('Format invalide (slots object attendu)');
        }

        $week = $this->repo->findById($weekId);
        if (!$week) {
            throw new RuntimeException('Semaine introuvable', 404);
        }
        
        $this->repo->beginTransaction();
        try {
            foreach ($data['slots'] as $slotId => $childIds) {
                if (!validate_uuid($slotId)) continue;
                
                if (!$this->repo->slotExistsInWeek($slotId, $weekId)) continue;

                $this->repo->deleteAssignmentsForSlot($slotId);
                
                if (is_array($childIds)) {
                    foreach ($childIds as $childId) {
                        if (!validate_uuid($childId)) continue;
                        $this->repo->createManualAssignment($childId, $slotId);
                    }
                }
            }

            $this->repo->commit();
        } catch (Throwable $e) {
            $this->repo->rollBack();
            throw new RuntimeException('Erreur lors de la sauvegarde : ' . $e->getMessage(), 500);
        }
    }

    public function deleteWeek(string $weekId): void {
        if (!validate_uuid($weekId)) {
            throw new InvalidArgumentException('ID invalide');
        }

        $week = $this->repo->findById($weekId);
        if (!$week) {
            throw new RuntimeException('Semaine introuvable', 404);
        }

        $this->repo->beginTransaction();
        try {
            $this->repo->deleteWeek($weekId);
            $this->repo->deleteScoreHistories((int)$week['week_number'], (int)$week['year']);

            $allChildren = $this->repo->getAllChildrenIds();
            foreach ($allChildren as $childId) {
                recalculate_child_score_history($childId);
            }

            $this->repo->commit();
        } catch (Exception $e) {
            $this->repo->rollBack();
            throw new RuntimeException('Erreur lors de la suppression', 500);
        }
    }

    public function notifyParentsForWeek(string $status, int $weekNumber, string $weekId, bool $isExchange = false, string $exchangeMessage = ''): void {
        $parents = $this->repo->getActiveParents();

        $appUrl = 'https://www.lesfruitsdelapassion.fr/planning';

        $tableHtml = '';
        if ($status === 'PUBLISHED') {
            $tableHtml = $this->buildPlanningHtmlEmail($weekId);
        }

        foreach ($parents as $p) {
            $toEmails = [];
            if (!empty($p['email']) && filter_var($p['email'], FILTER_VALIDATE_EMAIL)) {
                $toEmails[] = $p['email'];
            }
            if (!empty($p['second_email']) && filter_var($p['second_email'], FILTER_VALIDATE_EMAIL)) {
                $toEmails[] = $p['second_email'];
            }
            if (empty($toEmails)) continue;

            $firstName = $p['first_name'];

            if ($status === 'OPEN_TO_PARENTS') {
                $subject = "Ouverture des disponibilités - Semaine $weekNumber";
                $message = render_open_email($firstName, $weekNumber, $appUrl);
            } elseif ($status === 'PUBLISHED') {
                if ($isExchange) {
                    $subject = "Planning mis à jour - Échange (Semaine $weekNumber)";
                } else {
                    $subject = "Planning de la semaine $weekNumber publié";
                }
                
                $isForced = false;
                if (!$isExchange) {
                    $isForced = $this->repo->checkIsForcedAssignment($p['id'], $weekId);
                }
                
                $message = render_published_email($firstName, $weekNumber, $tableHtml, $appUrl, $isForced, $exchangeMessage);
            } else {
                return;
            }

            foreach ($toEmails as $email) {
                send_email($email, $subject, $message);
            }
        }
    }

    private function buildPlanningHtmlEmail(string $weekId): string {
        $slots = $this->repo->getSlotsForWeek($weekId);
        $childrenRows = $this->repo->getActiveChildrenWithDefaults();
        
        $children = [];
        foreach ($childrenRows as $r) {
            $cid = $r['id'];
            if (!isset($children[$cid])) {
                $children[$cid] = ['first_name' => $r['first_name'], 'age_group' => $r['age_group'], 'defaults' => []];
            }
            if ($r['day_of_week']) {
                $children[$cid]['defaults'][$r['day_of_week'] . '_' . $r['half_day']] = true;
            }
        }

        $presences = $this->repo->getPresencesForWeek($weekId);
        $presBySlotAndChild = [];
        foreach ($presences as $p) {
            $presBySlotAndChild[$p['slot_id']][$p['child_id']] = (bool) $p['is_present'];
        }

        $assignments = $this->repo->getAssignmentsForWeek($weekId);
        $assignBySlot = [];
        foreach ($assignments as $a) {
            $p1 = trim($a['parent1_first_name'] ?? '');
            $p2 = trim($a['parent2_first_name'] ?? '');
            $parents = $p1;
            if ($p2 !== '') {
                $parents .= ' & ' . $p2;
            }
            $assignBySlot[$a['slot_id']][] = htmlspecialchars($a['first_name']) . '<br><span style="font-size: 12px; font-weight: normal;">(' . htmlspecialchars($parents) . ')</span>';
        }

        $availabilities = $this->repo->getAvailabilitiesForWeek($weekId);
        $availBySlot = [];
        foreach ($availabilities as $a) {
            $availBySlot[$a['slot_id']][] = htmlspecialchars($a['first_name']);
        }

        $days = ['MONDAY' => 'Lundi', 'TUESDAY' => 'Mardi', 'WEDNESDAY' => 'Mercredi', 'THURSDAY' => 'Jeudi', 'FRIDAY' => 'Vendredi'];
        $halfDays = ['MORNING' => 'Matin', 'AFTERNOON' => 'Après-midi'];
        
        $html = '<table border="1" cellpadding="10" cellspacing="0" style="border-collapse: collapse; width: 100%; max-width: 900px; font-family: Arial, sans-serif; font-size: 13px;">';
        $html .= '<tr style="background-color: #6d28d9; color: white;"><th>Jour</th><th>Matin (8h30 - 12h30)</th><th>Après-midi (14h00 - 18h00)</th></tr>';

        foreach ($days as $day => $frDay) {
            $html .= '<tr>';
            $html .= '<td style="background-color: #fef08a; font-weight: bold; width: 15%; text-align: center; color: #6d28d9;">' . $frDay . '</td>';
            
            foreach ($halfDays as $half => $frHalf) {
                $slot = null;
                foreach ($slots as $s) {
                    if ($s['day_of_week'] === $day && $s['half_day'] === $half) {
                        $slot = $s;
                        break;
                    }
                }
                
                $html .= '<td style="width: 42.5%; vertical-align: top;">';
                if (!$slot) {
                    $html .= '-';
                } elseif ($slot['slot_type'] === 'CLOSED') {
                    $html .= '<div style="color: #666; font-style: italic; text-align: center; padding: 10px;">Fermé</div>';
                } elseif ($slot['slot_type'] === 'NO_PERM') {
                    $html .= '<div style="color: #999; font-style: italic; text-align: center; padding: 10px;">Pas de perm</div>';
                } else {
                    $html .= '<div style="margin-bottom: 8px; text-align: center; background-color: #f5f3ff; border: 1px solid #e9d5ff; padding: 5px; border-radius: 4px;">';
                    $html .= '<strong style="color: #6d28d9;">Permanence :</strong><br>';
                    $assigns = $assignBySlot[$slot['id']] ?? [];
                    if (empty($assigns)) {
                        $html .= '<span style="color: #999; font-style: italic;">Équipe / Non rempli</span>';
                    } else {
                        $html .= '<span style="color: #b45309; font-weight: bold; font-size: 14px;">' . implode('<br><br>', $assigns) . '</span>';
                    }
                    $html .= '</div>';
                    
                    $grandsPres = []; $petitsPres = [];
                    $grandsAbs = []; $petitsAbs = [];
                    
                    foreach ($children as $cid => $c) {
                        $isEnrolled = isset($c['defaults'][$day . '_' . $half]);
                        $override = isset($presBySlotAndChild[$slot['id']][$cid]) ? $presBySlotAndChild[$slot['id']][$cid] : null;
                        $isPresent = ($override !== null) ? $override : $isEnrolled;
                        
                        if ($isPresent) {
                            if ($c['age_group'] === 'GRAND') $grandsPres[] = $c['first_name'];
                            else $petitsPres[] = $c['first_name'];
                        } else {
                            if ($c['age_group'] === 'GRAND') $grandsAbs[] = $c['first_name'];
                            else $petitsAbs[] = $c['first_name'];
                        }
                    }
                    
                    $html .= '<div style="font-size: 11px; text-align: left;">';
                    $html .= '<div style="margin-bottom: 4px;"><strong style="color: #e11d48;">Grands : ' . count($grandsPres) . ' présents / ' . count($grandsAbs) . ' absents</strong><br>';
                    $html .= '<span style="color: #666;">Pr: ' . (empty($grandsPres) ? '-' : implode(', ', $grandsPres)) . ' | Abs: ' . (empty($grandsAbs) ? '-' : implode(', ', $grandsAbs)) . '</span></div>';
                    
                    $html .= '<div><strong style="color: #84cc16;">Petits : ' . count($petitsPres) . ' présents / ' . count($petitsAbs) . ' absents</strong><br>';
                    $html .= '<span style="color: #666;">Pr: ' . (empty($petitsPres) ? '-' : implode(', ', $petitsPres)) . ' | Abs: ' . (empty($petitsAbs) ? '-' : implode(', ', $petitsAbs)) . '</span></div>';
                    
                    $avails = $availBySlot[$slot['id']] ?? [];
                    if (!empty($avails)) {
                        $html .= '<div style="margin-top: 4px; color: #16a34a;"><strong>Parents Dispos: </strong>' . implode(', ', $avails) . '</div>';
                    }
                    
                    $html .= '</div>';
                }
                $html .= '</td>';
            }
            $html .= '</tr>';
        }
        
        $html .= '</table>';
        return $html;
    }
}
