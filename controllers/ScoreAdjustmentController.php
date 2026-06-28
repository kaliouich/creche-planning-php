<?php
require_once __DIR__ . '/../services/ScoreAdjustmentService.php';

class ScoreAdjustmentController {
    private ScoreAdjustmentService $service;

    public function __construct() {
        $this->service = new ScoreAdjustmentService();
    }

    public function getScoreMatrix(): void {
        $user = require_auth();
        require_role($user, 'ADMIN');

        try {
            $matrix = $this->service->getScoreMatrix();
            json_response($matrix);
        } catch (Exception $e) {
            error_log($e->getMessage());
            json_response(['error' => 'Erreur serveur'], 500);
        }
    }

    public function patchScoreAdjustment(): void {
        $user = require_auth();
        verify_csrf();
        require_role($user, 'ADMIN');

        $body = get_json_body();
        
        $childId = $body['childId'] ?? null;
        $weekNumber = $body['weekNumber'] ?? null;
        $year = $body['year'] ?? null;
        $delta = $body['delta'] ?? null; // Assume delta is sent by frontend now instead of permanencesDone

        if (!$childId || !isset($weekNumber, $year, $delta)) {
            json_response(['error' => 'Paramètres manquants'], 400);
            return;
        }

        try {
            $this->service->patchScoreAdjustment($childId, (int)$weekNumber, (int)$year, (float)$delta);
            $newScore = get_current_score($childId);
            json_response(['success' => true, 'newScore' => $newScore]);
        } catch (Exception $e) {
            json_response(['error' => $e->getMessage()], 400);
        }
    }
}
