<?php

require_once __DIR__ . '/../services/SlotService.php';

class SlotController {
    private SlotService $service;

    public function __construct() {
        $this->service = new SlotService();
    }

    public function update(string $slotId): void {
        $user = require_auth();
        verify_csrf();
        require_role($user, 'ADMIN');

        if (!validate_uuid($slotId)) {
            json_response(['error' => 'ID invalide'], 400);
            return;
        }

        $body = get_json_body();
        $slotType = $body['slotType'] ?? '';

        if (!in_array($slotType, ['OPEN', 'DOUBLE_PERM', 'CLOSED', 'NO_PERM'])) {
            json_response(['error' => 'Type de créneau invalide'], 400);
            return;
        }

        try {
            $updatedSlot = $this->service->updateSlotType($slotId, $slotType);
            json_response($updatedSlot);
        } catch (Exception $e) {
            $code = $e->getCode() ?: 500;
            if ($code < 400 || $code >= 600) {
                $code = 500;
            }
            json_response(['error' => $e->getMessage()], $code);
        }
    }
}
