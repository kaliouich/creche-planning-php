<?php
require_once __DIR__ . '/../services/ChildService.php';

class ChildController {
    private ChildService $service;

    public function __construct() {
        $this->service = new ChildService();
    }

    public function list(): void {
        require_auth();
        json_response($this->service->getAllChildren());
    }

    public function get(string $childId): void {
        require_auth();
        $child = $this->service->getChild($childId);
        if (!$child) {
            json_response(['error' => 'Enfant introuvable'], 404);
            return;
        }
        json_response($child);
    }

    public function create(): void {
        $user = require_auth();
        verify_csrf();
        require_role($user, 'ADMIN');

        try {
            $body = get_json_body();
            $result = $this->service->createChild($body);
            json_response($result, 201);
        } catch (InvalidArgumentException $e) {
            json_response(['error' => $e->getMessage()], 400);
        } catch (Exception $e) {
            json_response(['error' => 'Erreur interne'], 500);
        }
    }

    public function update(string $childId): void {
        $user = require_auth();
        verify_csrf();
        require_role($user, 'ADMIN');

        try {
            $body = get_json_body();
            $result = $this->service->updateChild($childId, $body);
            json_response($result);
        } catch (InvalidArgumentException $e) {
            json_response(['error' => $e->getMessage()], 400);
        } catch (RuntimeException $e) {
            json_response(['error' => $e->getMessage()], $e->getCode());
        } catch (Exception $e) {
            json_response(['error' => 'Erreur interne'], 500);
        }
    }

    public function delete(string $childId): void {
        $user = require_auth();
        verify_csrf();
        require_role($user, 'ADMIN');

        try {
            $this->service->deleteChild($childId);
            json_response(['message' => 'Enfant supprimé avec succès']);
        } catch (InvalidArgumentException $e) {
            json_response(['error' => $e->getMessage()], 400);
        } catch (RuntimeException $e) {
            json_response(['error' => $e->getMessage()], $e->getCode());
        } catch (\PDOException $e) {
            if (strpos($e->getMessage(), 'FOREIGN KEY') !== false) {
                json_response(['error' => 'Impossible de supprimer cet enfant car il est rattaché à des plannings.'], 409);
            } else {
                json_response(['error' => 'Erreur lors de la suppression de l\'enfant.'], 500);
            }
        } catch (Exception $e) {
            json_response(['error' => 'Erreur interne'], 500);
        }
    }

    public function updateStatus(string $childId): void {
        $user = require_auth();
        verify_csrf();
        require_role($user, 'ADMIN');

        try {
            $body = get_json_body();
            $this->service->updateStatus($childId, $body);
            json_response(['success' => true]);
        } catch (InvalidArgumentException $e) {
            json_response(['error' => $e->getMessage()], 400);
        } catch (RuntimeException $e) {
            json_response(['error' => $e->getMessage()], $e->getCode());
        } catch (Exception $e) {
            json_response(['error' => 'Erreur interne'], 500);
        }
    }

    public function updateDefaults(string $childId): void {
        $user = require_auth();
        verify_csrf();
        require_role($user, 'ADMIN');

        try {
            $body = get_json_body();
            $this->service->updateDefaults($childId, $body);
            json_response(['success' => true]);
        } catch (InvalidArgumentException $e) {
            json_response(['error' => $e->getMessage()], 400);
        } catch (RuntimeException $e) {
            json_response(['error' => $e->getMessage()], $e->getCode());
        } catch (Exception $e) {
            json_response(['error' => 'Erreur interne'], 500);
        }
    }

    public function listAbsences(string $childId): void {
        require_auth();
        json_response($this->service->getAbsences($childId));
    }

    public function createAbsence(string $childId): void {
        $user = require_auth();
        verify_csrf();
        require_role($user, 'ADMIN');

        try {
            $body = get_json_body();
            $this->service->createAbsence($childId, $body);
            json_response(['message' => 'Absence enregistrée avec succès']);
        } catch (Exception $e) {
            json_response(['error' => 'Erreur interne'], 500);
        }
    }

    public function updateAbsence(string $childId, string $absenceId): void {
        $user = require_auth();
        verify_csrf();
        require_role($user, 'ADMIN');

        try {
            $body = get_json_body();
            $this->service->updateAbsence($childId, $absenceId, $body);
            json_response(['message' => 'Absence modifiée avec succès']);
        } catch (RuntimeException $e) {
            json_response(['error' => $e->getMessage()], $e->getCode());
        } catch (Exception $e) {
            json_response(['error' => 'Erreur interne'], 500);
        }
    }

    public function deleteAbsence(string $childId, string $absenceId): void {
        $user = require_auth();
        verify_csrf();
        require_role($user, 'ADMIN');

        try {
            $this->service->deleteAbsence($childId, $absenceId);
            json_response(['message' => 'Absence supprimée avec succès']);
        } catch (RuntimeException $e) {
            json_response(['error' => $e->getMessage()], $e->getCode());
        } catch (Exception $e) {
            json_response(['error' => 'Erreur interne'], 500);
        }
    }

    public function history(string $childId): void {
        require_auth();
        json_response($this->service->getHistory($childId));
    }
}
