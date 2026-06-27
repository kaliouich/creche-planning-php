<?php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../services/score.php';
require_once __DIR__ . '/../services/EmailService.php';

class ExchangeController {
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
        $pdo = get_db();

        $stmt = $pdo->query("
            SELECT o.id, o.status as offer_status, o.created_at,
                   a.id as assignment_id, a.child_id as assigned_child_id,
                   s.id as slot_id, s.day_of_week, s.half_day,
                   w.week_number, w.year, w.id as week_id,
                   c.first_name as child_first_name, c.last_name as child_last_name,
                   c.parent_id as offering_parent_id,
                   p.first_name as parent_first_name, p.last_name as parent_last_name
            FROM exchange_offers o
            JOIN assignments a ON o.assignment_id = a.id
            JOIN children c ON a.child_id = c.id
            JOIN users p ON c.parent_id = p.id
            JOIN slots s ON a.slot_id = s.id
            JOIN planning_weeks w ON s.planning_week_id = w.id
            WHERE o.status = 'PENDING' AND w.status = 'PUBLISHED'
            ORDER BY w.year ASC, w.week_number ASC, o.created_at DESC
        ");
        $offersRaw = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $offers = [];
        foreach ($offersRaw as $row) {
            $propStmt = $pdo->prepare("
                SELECT ep.id, ep.status, ep.created_at,
                       ep.offered_assignment_id,
                       c.first_name as prop_child_first_name, c.last_name as prop_child_last_name,
                       c.parent_id as prop_parent_id,
                       p.first_name as prop_parent_first_name, p.last_name as prop_parent_last_name,
                       s.day_of_week, s.half_day
                FROM exchange_proposals ep
                JOIN children c ON ep.proposed_by_child_id = c.id
                JOIN users p ON c.parent_id = p.id
                LEFT JOIN assignments a ON ep.offered_assignment_id = a.id
                LEFT JOIN slots s ON a.slot_id = s.id
                WHERE ep.exchange_offer_id = ?
            ");
            $propStmt->execute([$row['id']]);
            $proposals = $propStmt->fetchAll(PDO::FETCH_ASSOC);

            $offers[] = [
                'id' => $row['id'],
                'assignmentId' => $row['assignment_id'],
                'weekId' => $row['week_id'],
                'weekNumber' => (int)$row['week_number'],
                'year' => (int)$row['year'],
                'dayOfWeek' => $row['day_of_week'],
                'halfDay' => $row['half_day'],
                'offeringParentId' => $row['offering_parent_id'],
                'offeringParentName' => $row['parent_first_name'] . ' ' . $row['parent_last_name'],
                'createdAt' => $row['created_at'],
                'proposals' => array_map(fn($p) => [
                    'id' => $p['id'],
                    'status' => $p['status'],
                    'proposingParentId' => $p['prop_parent_id'],
                    'proposingParentName' => $p['prop_parent_first_name'] . ' ' . $p['prop_parent_last_name'],
                    'offeredAssignmentId' => $p['offered_assignment_id'],
                    'offeredDayOfWeek' => $p['day_of_week'],
                    'offeredHalfDay' => $p['half_day'],
                    'createdAt' => $p['created_at']
                ], $proposals)
            ];
        }

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

        $pdo = get_db();
        
        $stmt = $pdo->prepare("
            SELECT a.id, c.parent_id, c.parent2_id, s.day_of_week, s.half_day, w.week_number 
            FROM assignments a
            JOIN children c ON a.child_id = c.id
            JOIN slots s ON a.slot_id = s.id
            JOIN planning_weeks w ON s.planning_week_id = w.id
            WHERE a.id = ?
        ");
        $stmt->execute([$assignmentId]);
        $assignment = $stmt->fetch();

        if (!$assignment) {
            json_response(['error' => 'Assignation introuvable'], 404);
            return;
        }

        if ($user['role'] !== 'ADMIN' && $assignment['parent_id'] !== $user['userId'] && $assignment['parent2_id'] !== $user['userId']) {
            json_response(['error' => 'Non autorisé'], 403);
            return;
        }

        $stmt = $pdo->prepare("SELECT id FROM exchange_offers WHERE assignment_id = ? AND status = 'PENDING'");
        $stmt->execute([$assignmentId]);
        if ($stmt->fetch()) {
            json_response(['error' => 'Une offre est déjà en cours pour ce créneau'], 400);
            return;
        }

        $offerId = generate_uuid();
        
        try {
            $pdo->beginTransaction();
            
            $pdo->prepare("INSERT INTO exchange_offers (id, assignment_id) VALUES (?, ?)")
                ->execute([$offerId, $assignmentId]);
                
            $pdo->prepare("UPDATE assignments SET is_offered_for_exchange = 1 WHERE id = ?")
                ->execute([$assignmentId]);
                
            $pdo->commit();

            $this->broadcastNewOffer($assignment['day_of_week'], $assignment['half_day'], $assignment['week_number'], $user['userId']);

            json_response(['success' => true, 'offerId' => $offerId]);
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log($e->getMessage());
            json_response(['error' => 'Erreur serveur'], 500);
        }
    }

    private function takeOffer(string $offerId): void {
        $user = require_auth();
        verify_csrf();

        $body = get_json_body();
        $childId = $body['childId'] ?? null;
        $offeredAssignmentId = !empty($body['offeredAssignmentId']) ? $body['offeredAssignmentId'] : null;

        if (!$childId) {
            json_response(['error' => 'childId manquant'], 400);
            return;
        }

        $pdo = get_db();

        if ($user['role'] === 'PARENT') {
            $stmt = $pdo->prepare("SELECT id FROM children WHERE id = ? AND (parent_id = ? OR parent2_id = ?)");
            $stmt->execute([$childId, $user['userId'], $user['userId']]);
            if (!$stmt->fetch()) {
                json_response(['error' => 'Accès interdit'], 403);
                return;
            }
        }

        $stmt = $pdo->prepare("
            SELECT o.id, o.assignment_id, o.status, a.child_id as owner_child_id, s.planning_week_id, w.week_number, w.year
            FROM exchange_offers o
            JOIN assignments a ON o.assignment_id = a.id
            JOIN slots s ON a.slot_id = s.id
            JOIN planning_weeks w ON s.planning_week_id = w.id
            WHERE o.id = ?
        ");
        $stmt->execute([$offerId]);
        $offer = $stmt->fetch();

        if (!$offer || $offer['status'] !== 'PENDING') {
            json_response(['error' => 'Offre indisponible'], 400);
            return;
        }

        $weekId = $offer['planning_week_id'];

        if ($offeredAssignmentId) {
            $stmt = $pdo->prepare("
                SELECT a.id FROM assignments a 
                JOIN slots s ON a.slot_id = s.id 
                WHERE a.id = ? AND a.child_id = ? AND s.planning_week_id = ?
            ");
            $stmt->execute([$offeredAssignmentId, $childId, $weekId]);
            if (!$stmt->fetch()) {
                json_response(['error' => 'Créneau proposé invalide'], 400);
                return;
            }
        }

        try {
            $pdo->beginTransaction();

            $proposalId = generate_uuid();
            $pdo->prepare("
                INSERT INTO exchange_proposals (id, exchange_offer_id, proposed_by_child_id, offered_assignment_id, status)
                VALUES (?, ?, ?, ?, 'PENDING')
            ")->execute([$proposalId, $offerId, $childId, $offeredAssignmentId]);

            if (!$offeredAssignmentId) {
                $this->executeExchange($pdo, $offerId, $proposalId, $offer['assignment_id'], $childId, null, $offer['owner_child_id'], $weekId, $offer['week_number'], $offer['year']);
                $pdo->commit();
                json_response(['success' => true, 'status' => 'ACCEPTED']);
                return;
            }

            $stmtOwner = $pdo->prepare("SELECT u.email, u.second_email, u.first_name FROM children c JOIN users u ON c.parent_id = u.id WHERE c.id = ?");
            $stmtOwner->execute([$offer['owner_child_id']]);
            $owner = $stmtOwner->fetch();
            
            $stmtTaker = $pdo->prepare("SELECT u.first_name, u.last_name FROM children c JOIN users u ON c.parent_id = u.id WHERE c.id = ?");
            $stmtTaker->execute([$childId]);
            $taker = $stmtTaker->fetch();
            $takerName = $taker ? $taker['first_name'] . ' ' . $taker['last_name'] : 'Un parent';
            
            if ($owner) {
                $subject = "Bourse d'échange : Proposition de troc";
                $message = "<p>Bonjour {$owner['first_name']},</p>";
                $message .= "<p>La famille <strong>{$takerName}</strong> vient de répondre à votre offre d'échange pour la Semaine {$offer['week_number']}.</p>";
                
                if ($offeredAssignmentId) {
                    $stmtOfferedSlot = $pdo->prepare("SELECT s.day_of_week, s.half_day FROM assignments a JOIN slots s ON a.slot_id = s.id WHERE a.id = ?");
                    $stmtOfferedSlot->execute([$offeredAssignmentId]);
                    $offeredSlot = $stmtOfferedSlot->fetch();
                    $days = ['MONDAY' => 'Lundi', 'TUESDAY' => 'Mardi', 'WEDNESDAY' => 'Mercredi', 'THURSDAY' => 'Jeudi', 'FRIDAY' => 'Vendredi'];
                    $halfs = ['MORNING' => 'Matin', 'AFTERNOON' => 'Après-midi'];
                    $offeredDay = $days[$offeredSlot['day_of_week']] ?? $offeredSlot['day_of_week'];
                    $offeredHalf = $halfs[$offeredSlot['half_day']] ?? $offeredSlot['half_day'];
                    
                    $message .= "<p>Ils vous proposent de prendre en échange leur permanence du <strong>{$offeredDay} {$offeredHalf}</strong> de la même semaine.</p>";
                } else {
                    $message .= "<p>Ils vous proposent de prendre votre permanence sans vous demander de permanence en retour.</p>";
                }
                
                $message .= "<p>Connectez-vous sur le planning pour accepter ou refuser cette proposition.</p>";
                
                if (!empty($owner['email'])) send_email($owner['email'], $subject, $message);
                if (!empty($owner['second_email'])) send_email($owner['second_email'], $subject, $message);
            }

            $pdo->commit();
            json_response(['success' => true, 'status' => 'PENDING']);
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log($e->getMessage());
            json_response(['error' => 'Erreur serveur: ' . $e->getMessage()], 500);
        }
    }

    private function validateProposal(string $proposalId): void {
        $user = require_auth();
        verify_csrf();

        $pdo = get_db();

        $stmt = $pdo->prepare("
            SELECT p.id, p.exchange_offer_id, p.proposed_by_child_id, p.offered_assignment_id, p.status as prop_status,
                   o.assignment_id, o.status as offer_status,
                   a.child_id as owner_child_id, c.parent_id as owner_parent_id, c.parent2_id as owner_parent2_id,
                   s.planning_week_id, w.week_number, w.year
            FROM exchange_proposals p
            JOIN exchange_offers o ON p.exchange_offer_id = o.id
            JOIN assignments a ON o.assignment_id = a.id
            JOIN children c ON a.child_id = c.id
            JOIN slots s ON a.slot_id = s.id
            JOIN planning_weeks w ON s.planning_week_id = w.id
            WHERE p.id = ?
        ");
        $stmt->execute([$proposalId]);
        $prop = $stmt->fetch();

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
            $pdo->beginTransaction();

            $stmtNames = $pdo->prepare("SELECT id, first_name FROM children WHERE id IN (?, ?)");
            $stmtNames->execute([$prop['owner_child_id'], $prop['proposed_by_child_id']]);
            $childrenNames = $stmtNames->fetchAll(PDO::FETCH_KEY_PAIR);
            $ownerName = $childrenNames[$prop['owner_child_id']] ?? '';
            $takerName = $childrenNames[$prop['proposed_by_child_id']] ?? '';
            
            if ($prop['offered_assignment_id']) {
                $exchangeMessage = "La famille de {$ownerName} a échangé avec la famille de {$takerName}.";
            } else {
                $exchangeMessage = "La famille de {$takerName} a remplacé la famille de {$ownerName}.";
            }

            $this->executeExchange($pdo, $prop['exchange_offer_id'], $prop['id'], $prop['assignment_id'], $prop['proposed_by_child_id'], $prop['offered_assignment_id'], $prop['owner_child_id'], $prop['planning_week_id'], $prop['week_number'], $prop['year'], $exchangeMessage);

            $stmtOwner = $pdo->prepare("SELECT u.email, u.second_email, u.first_name FROM children c JOIN users u ON c.parent_id = u.id WHERE c.id = ?");
            $stmtOwner->execute([$prop['proposed_by_child_id']]);
            $proposerParent = $stmtOwner->fetch();
            if ($proposerParent) {
                $subject = "Bourse d'échange : Troc validé !";
                $message = "<p>Bonjour {$proposerParent['first_name']},</p><p>Excellente nouvelle : votre proposition d'échange pour la Semaine {$prop['week_number']} a été acceptée et validée par l'autre famille.</p><p>Le planning a été mis à jour avec vos nouvelles permanences.</p>";
                if (!empty($proposerParent['email'])) send_email($proposerParent['email'], $subject, $message);
                if (!empty($proposerParent['second_email'])) send_email($proposerParent['second_email'], $subject, $message);
            }

            $pdo->commit();

            json_response(['success' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            error_log($e->getMessage());
            json_response(['error' => 'Erreur serveur'], 500);
        }
    }

    private function cancelOffer(string $offerId): void {
        $user = require_auth();
        verify_csrf();

        $pdo = get_db();
        
        $stmt = $pdo->prepare("
            SELECT o.id, o.assignment_id, c.parent_id, c.parent2_id, w.week_number, s.day_of_week, s.half_day
            FROM exchange_offers o
            JOIN assignments a ON o.assignment_id = a.id
            JOIN slots s ON a.slot_id = s.id
            JOIN planning_weeks w ON s.planning_week_id = w.id
            JOIN children c ON a.child_id = c.id
            WHERE o.id = ?
        ");
        $stmt->execute([$offerId]);
        $offer = $stmt->fetch();

        if (!$offer) {
            json_response(['error' => 'Offre introuvable'], 404);
            return;
        }

        if ($user['role'] !== 'ADMIN' && $offer['parent_id'] !== $user['userId'] && $offer['parent2_id'] !== $user['userId']) {
            json_response(['error' => 'Non autorisé'], 403);
            return;
        }

        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE exchange_offers SET status = 'CANCELLED' WHERE id = ?")->execute([$offerId]);
            $pdo->prepare("UPDATE assignments SET is_offered_for_exchange = 0 WHERE id = ?")->execute([$offer['assignment_id']]);
            $pdo->prepare("UPDATE exchange_proposals SET status = 'REJECTED' WHERE exchange_offer_id = ? AND status = 'PENDING'")->execute([$offerId]);
            $pdo->commit();
            
            $this->broadcastCancelledOffer($offer['day_of_week'], $offer['half_day'], $offer['week_number'], $offer['parent_id'], $offer['parent2_id']);
            
            json_response(['success' => true]);
        } catch (Exception $e) {
            $pdo->rollBack();
            json_response(['error' => 'Erreur serveur'], 500);
        }
    }

    private function executeExchange(PDO $pdo, string $offerId, string $proposalId, string $ownerAssignmentId, string $takingChildId, ?string $offeredAssignmentId, string $ownerChildId, string $weekId, int $weekNumber, int $year, string $exchangeMessage = ''): void {
        $pdo->prepare("UPDATE exchange_offers SET status = 'COMPLETED' WHERE id = ?")->execute([$offerId]);
        $pdo->prepare("UPDATE exchange_proposals SET status = 'ACCEPTED' WHERE id = ?")->execute([$proposalId]);
        $pdo->prepare("UPDATE exchange_proposals SET status = 'REJECTED' WHERE exchange_offer_id = ? AND id != ?")->execute([$offerId, $proposalId]);

        $pdo->prepare("UPDATE assignments SET child_id = ?, is_offered_for_exchange = 0, is_manual = 1 WHERE id = ?")->execute([$takingChildId, $ownerAssignmentId]);

        if ($offeredAssignmentId) {
            $pdo->prepare("UPDATE assignments SET child_id = ?, is_manual = 1 WHERE id = ?")->execute([$ownerChildId, $offeredAssignmentId]);
        }

        recalculate_child_score_history($takingChildId);
        recalculate_child_score_history($ownerChildId);

        require_once __DIR__ . '/WeekController.php';
        $weekController = new WeekController();
        $weekController->notifyParentsForWeek($pdo, 'PUBLISHED', $weekNumber, $weekId, true, $exchangeMessage);
    }

    private function broadcastNewOffer(string $dayOfWeek, string $halfDay, int $weekNumber, string $userIdToExclude = ''): void {
        $pdo = get_db();
        $stmt = $pdo->query('SELECT id, email, second_email FROM users WHERE role = "PARENT"');
        $users = $stmt->fetchAll();

        $appUrl = IS_PRODUCTION ? 'https://www.lesfruitsdelapassion.fr/planning' : 'http://localhost:5173/planning';
        $days = ['MONDAY' => 'Lundi', 'TUESDAY' => 'Mardi', 'WEDNESDAY' => 'Mercredi', 'THURSDAY' => 'Jeudi', 'FRIDAY' => 'Vendredi'];
        $halfs = ['MORNING' => 'Matin', 'AFTERNOON' => 'Après-midi'];
        
        $dayLabel = $days[$dayOfWeek] ?? $dayOfWeek;
        $halfLabel = $halfs[$halfDay] ?? $halfDay;

        $subject = "Bourse d'échange : Nouvelle offre pour la Semaine $weekNumber";
        $message = "
        <p>Bonjour,</p>
        <p>Un parent vient de proposer une permanence à l'échange pour la <strong>Semaine $weekNumber</strong> :</p>
        <p><strong>$dayLabel - $halfLabel</strong></p>
        <p>Si vous êtes intéressé(e), connectez-vous sur la <a href=\"$appUrl/exchange\">Bourse d'échange</a> pour la récupérer ou proposer un troc.</p>
        ";

        foreach ($users as $u) {
            if ($u['id'] === $userIdToExclude) continue;
            if (!empty($u['email'])) send_email($u['email'], $subject, $message);
            if (!empty($u['second_email'])) send_email($u['second_email'], $subject, $message);
        }
    }

    private function broadcastCancelledOffer(string $dayOfWeek, string $halfDay, int $weekNumber, ?string $parentId1, ?string $parentId2): void {
        $pdo = get_db();
        $stmt = $pdo->query('SELECT id, email, second_email FROM users WHERE role = "PARENT"');
        $users = $stmt->fetchAll();

        $appUrl = IS_PRODUCTION ? 'https://www.lesfruitsdelapassion.fr/planning' : 'http://localhost:5173/planning';
        $days = ['MONDAY' => 'Lundi', 'TUESDAY' => 'Mardi', 'WEDNESDAY' => 'Mercredi', 'THURSDAY' => 'Jeudi', 'FRIDAY' => 'Vendredi'];
        $halfs = ['MORNING' => 'Matin', 'AFTERNOON' => 'Après-midi'];
        
        $dayLabel = $days[$dayOfWeek] ?? $dayOfWeek;
        $halfLabel = $halfs[$halfDay] ?? $halfDay;

        $subject = "Bourse d'échange : Offre retirée (Semaine $weekNumber)";
        $message = "
        <p>Bonjour,</p>
        <p>L'offre de permanence à l'échange pour la <strong>Semaine $weekNumber ($dayLabel - $halfLabel)</strong> a été retirée de la bourse d'échange par la famille.</p>
        <p>Elle n'est donc plus disponible.</p>
        ";

        foreach ($users as $u) {
            if ($u['id'] === $parentId1 || $u['id'] === $parentId2) continue;
            if (!empty($u['email'])) send_email($u['email'], $subject, $message);
            if (!empty($u['second_email'])) send_email($u['second_email'], $subject, $message);
        }
    }
}
