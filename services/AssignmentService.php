<?php
require_once __DIR__ . '/../repositories/AssignmentRepository.php';

class AssignmentService {
    private AssignmentRepository $repo;

    public function __construct() {
        $this->repo = new AssignmentRepository();
    }

    public function getMyAssignments(string $childId): array {
        return $this->repo->getPublishedAssignmentsForChild($childId);
    }
}
