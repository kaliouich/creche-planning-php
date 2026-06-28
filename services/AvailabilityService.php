<?php
require_once __DIR__ . '/../repositories/AvailabilityRepository.php';
require_once __DIR__ . '/../repositories/WeekRepository.php';
require_once __DIR__ . '/../repositories/ChildRepository.php';

class AvailabilityService {
    private AvailabilityRepository $repo;
    private WeekRepository $planningRepo;
    private ChildRepository $childRepo;

    public function __construct() {
        $this->repo = new AvailabilityRepository();
        $this->planningRepo = new WeekRepository();
        $this->childRepo = new ChildRepository();
    }

    public function submitAvailabilities(string $weekId, string $childId, array $availabilities, string $userId, string $userRole): void {
        $child = $this->childRepo->findById($childId);
        if (!$child) {
            throw new Exception('Enfant introuvable', 404);
        }

        // Sécurité : Un PARENT ne peut modifier que ses propres enfants
        if ($userRole === 'PARENT' && $child['parent_id'] !== $userId && $child['parent2_id'] !== $userId) {
            throw new Exception('Accès interdit : cet enfant ne vous appartient pas', 403);
        }

        $slotIds = array_map(function ($a) { return $a['slotId']; }, $availabilities);
        
        $validSlots = $this->repo->getValidSlots($slotIds, $weekId);

        if (count($validSlots) !== count($slotIds)) {
            throw new Exception('Certains créneaux sont invalides ou fermés', 400);
        }

        $this->repo->beginTransaction();
        try {
            $this->repo->deleteAvailabilities($childId, $slotIds);
            $this->repo->insertAvailabilities($childId, $availabilities);

            $this->repo->deletePresences($childId, $slotIds);
            $this->repo->insertPresences($childId, $availabilities);

            $this->planningRepo->markNeedsRecalculation($weekId);

            $this->repo->commit();
        } catch (Exception $e) {
            $this->repo->rollBack();
            throw $e;
        }
    }
}
