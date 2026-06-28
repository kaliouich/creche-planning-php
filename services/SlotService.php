<?php
require_once __DIR__ . '/../repositories/SlotRepository.php';

class SlotService {
    private SlotRepository $repo;

    public function __construct() {
        $this->repo = new SlotRepository();
    }

    public function updateSlotType(string $slotId, string $slotType): array {
        $slot = $this->repo->findByIdWithWeekStatus($slotId);

        if (!$slot) {
            throw new Exception('Créneau introuvable', 404);
        }

        if (in_array($slot['week_status'], ['CALCULATION', 'PUBLISHED'])) {
            throw new Exception('Impossible de modifier un créneau d\'une semaine déjà verrouillée', 403);
        }

        $requiredParents = 1;
        if ($slotType === 'DOUBLE_PERM') $requiredParents = 2;
        if ($slotType === 'CLOSED' || $slotType === 'NO_PERM') $requiredParents = 0;

        $this->repo->updateSlotType($slotId, $slotType, $requiredParents);

        return [
            'id'              => $slotId,
            'planningWeekId'  => $slot['planning_week_id'],
            'dayOfWeek'       => $slot['day_of_week'],
            'halfDay'         => $slot['half_day'],
            'slotType'        => $slotType,
            'requiredParents' => $requiredParents,
        ];
    }
}
