<?php
require_once __DIR__ . '/../services/WeekService.php';

class WeekController {
    private WeekService $service;

    public function __construct() {
        $this->service = new WeekService();
    }

    public function list(): void {
        $user = require_auth();
        $openOnly = ($user['role'] === 'PARENT');
        json_response($this->service->getAllWeeks($openOnly));
    }

    public function create(): void {
        $user = require_auth();
        verify_csrf();
        require_role($user, 'ADMIN');

        try {
            $body = get_json_body();
            $result = $this->service->createWeek($body);
            json_response($result, 201);
        } catch (InvalidArgumentException $e) {
            json_response(['error' => $e->getMessage()], 400);
        } catch (RuntimeException $e) {
            json_response(['error' => $e->getMessage()], $e->getCode());
        } catch (Exception $e) {
            json_response(['error' => 'Erreur interne'], 500);
        }
    }

    public function updateStatus(string $weekId): void {
        $user = require_auth();
        verify_csrf();
        require_role($user, 'ADMIN');

        try {
            $body = get_json_body();
            $result = $this->service->updateStatus($weekId, $body);
            json_response($result);
        } catch (InvalidArgumentException $e) {
            json_response(['error' => $e->getMessage()], 400);
        } catch (RuntimeException $e) {
            if ($e->getCode() === 400) {
                http_response_code(400);
                header('Content-Type: application/json');
                echo $e->getMessage();
                exit;
            }
            json_response(['error' => $e->getMessage()], $e->getCode());
        } catch (Exception $e) {
            error_log('Error in updateStatus: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
            json_response(['error' => 'Erreur lors de la publication : ' . $e->getMessage()], 500);
        }
    }

    public function updateAssignments(string $weekId): void {
        $user = require_auth();
        verify_csrf();
        require_role($user, 'ADMIN');

        try {
            $body = get_json_body();
            $this->service->updateAssignments($weekId, $body);
            json_response(['success' => true]);
        } catch (InvalidArgumentException $e) {
            json_response(['error' => $e->getMessage()], 400);
        } catch (RuntimeException $e) {
            json_response(['error' => $e->getMessage()], $e->getCode());
        } catch (Exception $e) {
            error_log('Error in updateAssignments: ' . $e->getMessage());
            json_response(['error' => 'Erreur lors de la sauvegarde : ' . $e->getMessage()], 500);
        }
    }

    public function delete(string $weekId): void {
        $user = require_auth();
        verify_csrf();
        require_role($user, 'ADMIN');

        try {
            $this->service->deleteWeek($weekId);
            json_response(['message' => 'Semaine supprimée et scores recalculés avec succès']);
        } catch (InvalidArgumentException $e) {
            json_response(['error' => $e->getMessage()], 400);
        } catch (RuntimeException $e) {
            json_response(['error' => $e->getMessage()], $e->getCode());
        } catch (Exception $e) {
            error_log('Erreur lors de la suppression de la semaine: ' . $e->getMessage());
            json_response(['error' => 'Erreur lors de la suppression'], 500);
        }
    }
}
