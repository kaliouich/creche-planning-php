<?php

class PlanningController {
    private PlanningService $planningService;

    public function __construct() {
        require_once __DIR__ . '/../repositories/PlanningRepository.php';
        require_once __DIR__ . '/../services/PlanningService.php';
        $repo = new PlanningRepository();
        $this->planningService = new PlanningService($repo);
    }

    public function get(string $weekId): void {
        $user = require_auth();

        if (!validate_uuid($weekId)) {
            json_response(['error' => 'ID invalide'], 400);
            return;
        }

        $childIdFilter = $_GET['childId'] ?? null;
        if ($childIdFilter && !validate_uuid($childIdFilter)) {
            $childIdFilter = null;
        }

        $weekData = $this->planningService->getWeekPlanning($weekId, $childIdFilter);

        if (!$weekData) {
            json_response(['error' => 'Semaine introuvable'], 404);
            return;
        }

        json_response($weekData);
    }

    public function generate(string $weekId): void {
        $user = require_auth();
        verify_csrf();
        require_role($user, 'ADMIN');

        if (!validate_uuid($weekId)) {
            json_response(['error' => 'ID invalide'], 400);
            return;
        }

        require_week_status($weekId, ['OPEN_TO_PARENTS', 'CALCULATION']);

        try {
            $result = $this->planningService->generateWeek($weekId);

            json_response([
                'message'       => 'Planning généré avec succès',
                'stats'         => $result['stats'],
                'unfilledSlots' => $result['unfilledSlots'],
            ]);
        } catch (Exception $e) {
            json_response(['error' => 'Erreur lors de la génération: ' . $e->getMessage()], 500);
        }
    }
}
