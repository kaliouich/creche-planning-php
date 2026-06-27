<?php

class ExchangeRepository {
    private PDO $pdo;

    public function __construct() {
        $this->pdo = get_db();
    }

    public function getPendingOffers(): array {
        $stmt = $this->pdo->query("
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

        if (empty($offersRaw)) {
            return [];
        }

        $offerIds = array_column($offersRaw, 'id');
        $placeholders = str_repeat('?,', count($offerIds) - 1) . '?';

        $propStmt = $this->pdo->prepare("
            SELECT ep.id, ep.exchange_offer_id, ep.status, ep.created_at,
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
            WHERE ep.exchange_offer_id IN ($placeholders)
        ");
        $propStmt->execute($offerIds);
        $proposalsRaw = $propStmt->fetchAll(PDO::FETCH_ASSOC);

        $proposalsByOffer = [];
        foreach ($proposalsRaw as $p) {
            $proposalsByOffer[$p['exchange_offer_id']][] = $p;
        }

        $offers = [];
        foreach ($offersRaw as $row) {
            $proposals = $proposalsByOffer[$row['id']] ?? [];

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

        return $offers;
    }

    public function findAssignmentDetails(string $assignmentId): ?array {
        $stmt = $this->pdo->prepare("
            SELECT a.id, c.parent_id, c.parent2_id, s.day_of_week, s.half_day, w.week_number, s.planning_week_id
            FROM assignments a
            JOIN children c ON a.child_id = c.id
            JOIN slots s ON a.slot_id = s.id
            JOIN planning_weeks w ON s.planning_week_id = w.id
            WHERE a.id = ?
        ");
        $stmt->execute([$assignmentId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function hasPendingOfferForAssignment(string $assignmentId): bool {
        $stmt = $this->pdo->prepare("SELECT id FROM exchange_offers WHERE assignment_id = ? AND status = 'PENDING'");
        $stmt->execute([$assignmentId]);
        return (bool) $stmt->fetch();
    }

    public function createOffer(string $offerId, string $assignmentId): void {
        $this->pdo->prepare("INSERT INTO exchange_offers (id, assignment_id) VALUES (?, ?)")
            ->execute([$offerId, $assignmentId]);
            
        $this->pdo->prepare("UPDATE assignments SET is_offered_for_exchange = 1 WHERE id = ?")
            ->execute([$assignmentId]);
    }

    public function getFamilyString(?string $parentId1, ?string $parentId2): string {
        $p1 = $parentId1 ?: 'none';
        $p2 = $parentId2 ?: 'none';
        
        $stmtC = $this->pdo->prepare("SELECT first_name, parent1_first_name, parent2_first_name FROM children WHERE parent_id = ? OR parent2_id = ? OR parent_id = ? OR parent2_id = ?");
        $stmtC->execute([$p1, $p1, $p2, $p2]);
        $rows = $stmtC->fetchAll(PDO::FETCH_ASSOC);

        $childrenNames = [];
        $parentsNames = [];
        foreach ($rows as $r) {
            if (!empty($r['first_name']) && !in_array($r['first_name'], $childrenNames)) {
                $childrenNames[] = $r['first_name'];
            }
            if (!empty($r['parent1_first_name']) && !in_array($r['parent1_first_name'], $parentsNames)) {
                $parentsNames[] = $r['parent1_first_name'];
            }
            if (!empty($r['parent2_first_name']) && !in_array($r['parent2_first_name'], $parentsNames)) {
                $parentsNames[] = $r['parent2_first_name'];
            }
        }

        $childrenStr = !empty($childrenNames) ? implode(' & ', array_map('ucfirst', $childrenNames)) : 'Enfant';
        $parentsStr = !empty($parentsNames) ? implode(' & ', array_map('ucfirst', $parentsNames)) : 'Parent';

        return "{$childrenStr} ({$parentsStr})";
    }

    public function findOfferDetails(string $offerId): ?array {
        $stmt = $this->pdo->prepare("
            SELECT o.id, o.assignment_id, c.parent_id, c.parent2_id, w.week_number, w.year, w.id as week_id, s.day_of_week, s.half_day, a.child_id as owner_child_id
            FROM exchange_offers o
            JOIN assignments a ON o.assignment_id = a.id
            JOIN children c ON a.child_id = c.id
            JOIN slots s ON a.slot_id = s.id
            JOIN planning_weeks w ON s.planning_week_id = w.id
            WHERE o.id = ? AND o.status = 'PENDING'
        ");
        $stmt->execute([$offerId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findChildParents(string $childId): ?array {
        $stmt = $this->pdo->prepare("SELECT parent_id, parent2_id FROM children WHERE id = ?");
        $stmt->execute([$childId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function cancelOffer(string $offerId, string $assignmentId): void {
        $this->pdo->prepare("UPDATE exchange_offers SET status = 'CANCELLED' WHERE id = ?")->execute([$offerId]);
        $this->pdo->prepare("UPDATE assignments SET is_offered_for_exchange = 0 WHERE id = ?")->execute([$assignmentId]);
        $this->pdo->prepare("UPDATE exchange_proposals SET status = 'REJECTED' WHERE exchange_offer_id = ? AND status = 'PENDING'")->execute([$offerId]);
    }

    public function createProposal(string $proposalId, string $offerId, string $childId, ?string $offeredAssignmentId): void {
        $this->pdo->prepare("INSERT INTO exchange_proposals (id, exchange_offer_id, proposed_by_child_id, offered_assignment_id, status) VALUES (?, ?, ?, ?, 'PENDING')")
            ->execute([$proposalId, $offerId, $childId, $offeredAssignmentId]);
    }

    public function getAssignmentOwner(string $assignmentId): ?array {
        $stmt = $this->pdo->prepare("SELECT c.id as owner_child_id, c.parent_id, c.parent2_id FROM assignments a JOIN children c ON a.child_id = c.id WHERE a.id = ?");
        $stmt->execute([$assignmentId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function validateProposal(string $proposalId, string $offerId, string $originalAssignmentId, string $takerChildId, ?string $offeredAssignmentId, string $ownerChildId): void {
        $this->pdo->prepare("UPDATE exchange_proposals SET status = 'ACCEPTED' WHERE id = ?")->execute([$proposalId]);
        $this->pdo->prepare("UPDATE exchange_proposals SET status = 'REJECTED' WHERE exchange_offer_id = ? AND id != ?")->execute([$offerId, $proposalId]);
        $this->pdo->prepare("UPDATE exchange_offers SET status = 'COMPLETED' WHERE id = ?")->execute([$offerId]);

        $this->pdo->prepare("UPDATE assignments SET child_id = ?, is_offered_for_exchange = 0, is_manual = 1 WHERE id = ?")
            ->execute([$takerChildId, $originalAssignmentId]);

        if ($offeredAssignmentId) {
            $this->pdo->prepare("UPDATE assignments SET child_id = ?, is_manual = 1 WHERE id = ?")
                ->execute([$ownerChildId, $offeredAssignmentId]);
        }
    }
    
    public function getProposalFullDetails(string $proposalId): ?array {
        $stmt = $this->pdo->prepare("
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
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getChildrenNames(array $childIds): array {
        if (empty($childIds)) return [];
        $placeholders = implode(',', array_fill(0, count($childIds), '?'));
        $stmt = $this->pdo->prepare("SELECT id, first_name FROM children WHERE id IN ($placeholders)");
        $stmt->execute($childIds);
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    
    public function getParentUserByChildId(string $childId): ?array {
        $stmt = $this->pdo->prepare("SELECT u.email, u.second_email, u.first_name FROM children c JOIN users u ON c.parent_id = u.id WHERE c.id = ?");
        $stmt->execute([$childId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    public function getAllParentUsers(): array {
        $stmt = $this->pdo->query('SELECT id, email, second_email FROM users WHERE role = "PARENT"');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function beginTransaction(): void {
        $this->pdo->beginTransaction();
    }

    public function commit(): void {
        $this->pdo->commit();
    }

    public function rollBack(): void {
        $this->pdo->rollBack();
    }
}
