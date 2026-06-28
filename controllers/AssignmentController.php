<?php
require_once __DIR__ . '/../services/AssignmentService.php';

class AssignmentController {
    private AssignmentService $service;

    public function __construct() {
        $this->service = new AssignmentService();
    }

    public function myAssignments(string $childId): void {
        $user = require_auth();

        if (!validate_uuid($childId)) {
            json_response(['error' => 'ID invalide'], 400);
            return;
        }

        require_once __DIR__ . '/../repositories/ChildRepository.php';
        $childRepo = new ChildRepository();
        $child = $childRepo->findById($childId);

        if (!$child) {
            json_response(['error' => 'Enfant introuvable'], 404);
            return;
        }

        // Vérification de sécurité (Parent seulement autorisé pour son enfant, ADMIN peut tout voir)
        if ($user['role'] !== 'ADMIN' && $child['parent_id'] !== $user['userId'] && $child['parent2_id'] !== $user['userId']) {
            json_response(['error' => 'Accès refusé'], 403);
            return;
        }

        $assignments = $this->service->getMyAssignments($childId);
        
        json_response($assignments);
    }
}
