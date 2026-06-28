<?php

require_once __DIR__ . '/../services/AvailabilityService.php';

class AvailabilityController {
    private AvailabilityService $service;

    public function __construct() {
        $this->service = new AvailabilityService();
    }

    public function submit(string $weekId): void {
        $user = require_auth();
        verify_csrf();
        require_role($user, 'PARENT');

        if (!validate_uuid($weekId)) {
            json_response(['error' => 'ID invalide'], 400);
            return;
        }

        require_week_status($weekId, ['OPEN_TO_PARENTS']);

        $body = get_json_body();
        $childId = $body['childId'] ?? '';
        $availabilities = $body['availabilities'] ?? [];

        if (empty($childId) || !validate_uuid($childId)) {
            json_response(['error' => 'childId est requis'], 400);
            return;
        }

        if (empty($availabilities)) {
            json_response(['error' => 'Au moins une disponibilité requise'], 400);
            return;
        }

        try {
            $this->service->submitAvailabilities($weekId, $childId, $availabilities, $user['userId'], $user['role']);
            json_response(['message' => 'Disponibilités enregistrées avec succès']);
        } catch (Exception $e) {
            $code = $e->getCode() ?: 500;
            // Map 0 code from default exception to 500
            if ($code < 400 || $code >= 600) {
                $code = 500;
            }
            json_response(['error' => $e->getMessage()], $code);
        }
    }
}
