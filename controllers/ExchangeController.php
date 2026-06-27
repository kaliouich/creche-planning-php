<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/score.php';
require_once __DIR__ . '/../services/EmailService.php';
require_once __DIR__ . '/../repositories/ExchangeRepository.php';
require_once __DIR__ . '/../services/ExchangeService.php';

class ExchangeController {
    private ExchangeRepository $repo;
    private ExchangeService $service;

    public function __construct() {
        $this->repo = new ExchangeRepository();
        $this->service = new ExchangeService($this->repo);
    }

    public function handle(string $route, string $method): void {
        $parts = explode('/', $route);
        $base = $parts[0] ?? '';
        
        if ($base === 'offers') {
            if ($method === 'GET' && empty($parts[1])) {
                $this->getOffers();
            } elseif ($method === 'POST' && empty($parts[1])) {
                $this->createOffer();
            } elseif ($method === 'POST' && isset($parts[1]) && isset($parts[2]) && $parts[2] === 'take') {
                $this->takeOffer($parts[1]);
            } elseif ($method === 'DELETE' && isset($parts[1])) {
                $this->cancelOffer($parts[1]);
            } else {
                json_response(['error' => 'Route non trouvée'], 404);
            }
        } elseif ($base === 'proposals') {
            if ($method === 'POST' && isset($parts[1]) && isset($parts[2]) && $parts[2] === 'validate') {
                $this->validateProposal($parts[1]);
            } else {
                json_response(['error' => 'Route non trouvée'], 404);
            }
        } else {
            json_response(['error' => 'Route non trouvée'], 404);
        }
    }

    private function getOffers(): void {
        $user = require_auth();
        $offers = $this->repo->getPendingOffers();
        json_response(['offers' => $offers]);
    }

    private function createOffer(): void {
        $user = require_auth();
        verify_csrf();

        $body = get_json_body();
        $assignmentId = $body['assignmentId'] ?? null;

        if (!$assignmentId) {
            json_response(['error' => 'Paramètre manquant'], 400);
            return;
        }

        $assignment = $this->repo->findAssignmentDetails($assignmentId);

        if (!$assignment) {
            json_response(['error' => 'Assignation introuvable'], 404);
            return;
        }

        if ($user['role'] !== 'ADMIN' && $assignment['parent_id'] !== $user['userId'] && $assignment['parent2_id'] !== $user['userId']) {
            json_response(['error' => 'Non autorisé'], 403);
            return;
        }

        if ($this->repo->hasPendingOfferForAssignment($assignmentId)) {
            json_response(['error' => 'Une offre est déjà en cours pour ce créneau'], 400);
            return;
        }

        $offerId = generate_uuid();
        
        try {
            $this->service->createOffer($offerId, $assignmentId);

            $ownerChildrenStr = $this->repo->getFamilyString($assignment['parent_id'], $assignment['parent2_id']);
            $this->broadcastNewOffer($assignment['day_of_week'], $assignment['half_day'], $assignment['week_number'], $user['userId'], $ownerChildrenStr);

            json_response(['success' => true, 'offerId' => $offerId]);
        } catch (Exception $e) {
            error_log($e->getMessage());
            json_response(['error' => 'Erreur serveur'], 500);
        }
    }

    private function takeOffer(string $offerId): void {
        $user = require_auth();
        verify_csrf();

        $body = get_json_body();
        $childId = $body['childId'] ?? null;
        $offeredAssignmentId = $body['offeredAssignmentId'] ?? null;

        if (!$childId) {
            json_response(['error' => 'Enfant manquant'], 400);
            return;
        }

        $offer = $this->repo->findOfferDetails($offerId);
        if (!$offer) {
            json_response(['error' => 'Offre introuvable ou déjà traitée'], 404);
            return;
        }

        if ($offer['parent_id'] === $user['userId'] || $offer['parent2_id'] === $user['userId']) {
            json_response(['error' => 'Vous ne pouvez pas prendre votre propre offre'], 400);
            return;
        }

        if ($offeredAssignmentId) {
            $offeredAssignment = $this->repo->findAssignmentDetails($offeredAssignmentId);
            if (!$offeredAssignment || $offeredAssignment['child_id'] !== $childId || $offeredAssignment['planning_week_id'] !== $offer['week_id']) {
                json_response(['error' => 'Créneau proposé invalide'], 400);
                return;
            }
        }

        try {
            $status = $this->service->proposeOrTake($offerId, $childId, $offeredAssignmentId, $offer);

            if ($status === 'ACCEPTED') {
                json_response(['success' => true, 'status' => 'ACCEPTED']);
                return;
            }

            $owner = $this->repo->getParentUserByChildId($offer['owner_child_id']);
            $takerParent = $this->repo->findChildParents($childId);
            
            $takerName = 'une famille';
            if ($takerParent) {
                $takerName = $this->repo->getFamilyString($takerParent['parent_id'], $takerParent['parent2_id']);
            }
            
            if ($owner) {
                $subject = "Bourse d'échange : Proposition de troc";
                $message = "<p>Bonjour {$owner['first_name']},</p>";
                $message .= "<p>La famille <strong>{$takerName}</strong> vient de répondre à votre offre d'échange pour la Semaine {$offer['week_number']}.</p>";
                
                if ($offeredAssignmentId) {
                    $offeredAssignment = $this->repo->findAssignmentDetails($offeredAssignmentId);
                    $days = ['MONDAY' => 'Lundi', 'TUESDAY' => 'Mardi', 'WEDNESDAY' => 'Mercredi', 'THURSDAY' => 'Jeudi', 'FRIDAY' => 'Vendredi'];
                    $halfs = ['MORNING' => 'Matin', 'AFTERNOON' => 'Après-midi'];
                    $offeredDay = $days[$offeredAssignment['day_of_week']] ?? $offeredAssignment['day_of_week'];
                    $offeredHalf = $halfs[$offeredAssignment['half_day']] ?? $offeredAssignment['half_day'];
                    
                    $message .= "<p>Ils vous proposent de prendre en échange leur permanence du <strong>{$offeredDay} {$offeredHalf}</strong> de la même semaine.</p>";
                } else {
                    $message .= "<p>Ils vous proposent de prendre votre permanence sans vous demander de permanence en retour.</p>";
                }
                
                $message .= "<p>Connectez-vous sur le planning pour accepter ou refuser cette proposition.</p>";
                
                if (!empty($owner['email'])) send_email($owner['email'], $subject, $message);
                if (!empty($owner['second_email'])) send_email($owner['second_email'], $subject, $message);
            }

            json_response(['success' => true, 'status' => 'PENDING']);
        } catch (Exception $e) {
            error_log($e->getMessage());
            json_response(['error' => 'Erreur serveur: ' . $e->getMessage()], 500);
        }
    }

    private function validateProposal(string $proposalId): void {
        $user = require_auth();
        verify_csrf();

        $prop = $this->repo->getProposalFullDetails($proposalId);

        if (!$prop) {
            json_response(['error' => 'Proposition introuvable'], 404);
            return;
        }

        if ($prop['prop_status'] !== 'PENDING' || $prop['offer_status'] !== 'PENDING') {
            json_response(['error' => 'Proposition ou offre non valide'], 400);
            return;
        }

        if ($user['role'] !== 'ADMIN' && $prop['owner_parent_id'] !== $user['userId'] && $prop['owner_parent2_id'] !== $user['userId']) {
            json_response(['error' => 'Non autorisé'], 403);
            return;
        }

        try {
            $childrenNames = $this->repo->getChildrenNames([$prop['owner_child_id'], $prop['proposed_by_child_id']]);
            $ownerName = $childrenNames[$prop['owner_child_id']] ?? '';
            $takerName = $childrenNames[$prop['proposed_by_child_id']] ?? '';
            
            if ($prop['offered_assignment_id']) {
                $exchangeMessage = "La famille de {$ownerName} a échangé avec la famille de {$takerName}.";
            } else {
                $exchangeMessage = "La famille de {$takerName} a remplacé la famille de {$ownerName}.";
            }

            $this->service->validateProposal($prop, $exchangeMessage);

            $proposerParent = $this->repo->getParentUserByChildId($prop['proposed_by_child_id']);
            if ($proposerParent) {
                $subject = "Bourse d'échange : Troc validé !";
                $message = "<p>Bonjour {$proposerParent['first_name']},</p><p>Excellente nouvelle : votre proposition d'échange pour la Semaine {$prop['week_number']} a été acceptée et validée par l'autre famille.</p><p>Le planning a été mis à jour avec vos nouvelles permanences.</p>";
                if (!empty($proposerParent['email'])) send_email($proposerParent['email'], $subject, $message);
                if (!empty($proposerParent['second_email'])) send_email($proposerParent['second_email'], $subject, $message);
            }

            json_response(['success' => true]);
        } catch (Exception $e) {
            error_log($e->getMessage());
            json_response(['error' => 'Erreur serveur'], 500);
        }
    }

    private function cancelOffer(string $offerId): void {
        $user = require_auth();
        verify_csrf();

        $offer = $this->repo->findOfferDetails($offerId);

        if (!$offer) {
            json_response(['error' => 'Offre introuvable'], 404);
            return;
        }

        if ($user['role'] !== 'ADMIN' && $offer['parent_id'] !== $user['userId'] && $offer['parent2_id'] !== $user['userId']) {
            json_response(['error' => 'Non autorisé'], 403);
            return;
        }

        try {
            $this->service->cancelOffer($offerId, $offer['assignment_id']);
            
            $ownerChildrenStr = $this->repo->getFamilyString($offer['parent_id'], $offer['parent2_id']);

            $this->broadcastCancelledOffer($offer['day_of_week'], $offer['half_day'], $offer['week_number'], $offer['parent_id'], $offer['parent2_id'], $ownerChildrenStr);
            
            json_response(['success' => true]);
        } catch (Exception $e) {
            json_response(['error' => 'Erreur serveur'], 500);
        }
    }

    private function broadcastNewOffer(string $dayOfWeek, string $halfDay, int $weekNumber, string $userIdToExclude = '', string $ownerChildrenStr = 'une famille'): void {
        $users = $this->repo->getAllParentUsers();

        $appUrl = IS_PRODUCTION ? 'https://www.lesfruitsdelapassion.fr/planning' : 'http://localhost:5173/planning';
        $days = ['MONDAY' => 'Lundi', 'TUESDAY' => 'Mardi', 'WEDNESDAY' => 'Mercredi', 'THURSDAY' => 'Jeudi', 'FRIDAY' => 'Vendredi'];
        $halfs = ['MORNING' => 'Matin', 'AFTERNOON' => 'Après-midi'];
        
        $dayLabel = $days[$dayOfWeek] ?? $dayOfWeek;
        $halfLabel = $halfs[$halfDay] ?? $halfDay;

        $subject = "Bourse d'échange : Nouvelle offre pour la Semaine $weekNumber";
        $message = "
        <p>Bonjour,</p>
        <p>La famille <strong>$ownerChildrenStr</strong> vient de proposer une permanence à l'échange pour la <strong>Semaine $weekNumber</strong> :</p>
        <p><strong>$dayLabel - $halfLabel</strong></p>
        <p>Si vous êtes intéressé(e), connectez-vous sur la <a href=\"$appUrl/exchange\">Bourse d'échange</a> pour la récupérer ou proposer un troc.</p>
        ";

        foreach ($users as $u) {
            if ($u['id'] === $userIdToExclude) continue;
            if (!empty($u['email'])) send_email($u['email'], $subject, $message);
            if (!empty($u['second_email'])) send_email($u['second_email'], $subject, $message);
        }
    }

    private function broadcastCancelledOffer(string $dayOfWeek, string $halfDay, int $weekNumber, ?string $parentId1, ?string $parentId2, string $ownerChildrenStr): void {
        $users = $this->repo->getAllParentUsers();

        $appUrl = IS_PRODUCTION ? 'https://www.lesfruitsdelapassion.fr/planning' : 'http://localhost:5173/planning';
        $days = ['MONDAY' => 'Lundi', 'TUESDAY' => 'Mardi', 'WEDNESDAY' => 'Mercredi', 'THURSDAY' => 'Jeudi', 'FRIDAY' => 'Vendredi'];
        $halfs = ['MORNING' => 'Matin', 'AFTERNOON' => 'Après-midi'];
        
        $dayLabel = $days[$dayOfWeek] ?? $dayOfWeek;
        $halfLabel = $halfs[$halfDay] ?? $halfDay;

        $subject = "Bourse d'échange : Offre retirée (Semaine $weekNumber)";
        $message = "
        <p>Bonjour,</p>
        <p>L'offre de permanence à l'échange pour la <strong>Semaine $weekNumber ($dayLabel - $halfLabel)</strong> a été retirée de la bourse d'échange par la famille <strong>$ownerChildrenStr</strong>.</p>
        <p>Elle n'est donc plus disponible.</p>
        ";

        foreach ($users as $u) {
            if ($u['id'] === $parentId1 || $u['id'] === $parentId2) continue;
            if (!empty($u['email'])) send_email($u['email'], $subject, $message);
            if (!empty($u['second_email'])) send_email($u['second_email'], $subject, $message);
        }
    }
}
