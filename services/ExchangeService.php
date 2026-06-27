<?php
require_once __DIR__ . '/../repositories/ExchangeRepository.php';
require_once __DIR__ . '/score.php';

class ExchangeService {
    private ExchangeRepository $repo;

    public function __construct(ExchangeRepository $repo) {
        $this->repo = $repo;
    }

    /**
     * @throws Exception
     */
    public function createOffer(string $offerId, string $assignmentId): void {
        try {
            $this->repo->beginTransaction();
            $this->repo->createOffer($offerId, $assignmentId);
            $this->repo->commit();
        } catch (Exception $e) {
            $this->repo->rollBack();
            throw $e;
        }
    }

    /**
     * @throws Exception
     */
    public function cancelOffer(string $offerId, string $assignmentId): void {
        try {
            $this->repo->beginTransaction();
            $this->repo->cancelOffer($offerId, $assignmentId);
            $this->repo->commit();
        } catch (Exception $e) {
            $this->repo->rollBack();
            throw $e;
        }
    }

    /**
     * Crée une proposition. Si aucun échange n'est demandé, valide directement l'échange.
     * @return string 'ACCEPTED' ou 'PENDING'
     * @throws Exception
     */
    public function proposeOrTake(string $offerId, string $childId, ?string $offeredAssignmentId, array $offer): string {
        try {
            $this->repo->beginTransaction();

            $proposalId = generate_uuid();
            $this->repo->createProposal($proposalId, $offerId, $childId, $offeredAssignmentId);

            if (!$offeredAssignmentId) {
                // Validation immédiate
                $this->repo->validateProposal($proposalId, $offerId, $offer['assignment_id'], $childId, null, $offer['owner_child_id']);
                $this->repo->commit();

                // Actions post-transaction
                recalculate_child_score_history($childId);
                recalculate_child_score_history($offer['owner_child_id']);

                require_once __DIR__ . '/../controllers/WeekController.php';
                $weekController = new WeekController();
                $weekController->notifyParentsForWeek(get_db(), 'PUBLISHED', $offer['week_number'], $offer['week_id'], true, '');

                return 'ACCEPTED';
            }

            $this->repo->commit();
            return 'PENDING';
        } catch (Exception $e) {
            $this->repo->rollBack();
            throw $e;
        }
    }

    /**
     * Valide une proposition existante et déclenche le processus d'échange.
     * @throws Exception
     */
    public function validateProposal(array $prop, string $exchangeMessage): void {
        try {
            $this->repo->beginTransaction();

            $this->repo->validateProposal(
                $prop['id'], 
                $prop['exchange_offer_id'], 
                $prop['assignment_id'], 
                $prop['proposed_by_child_id'], 
                $prop['offered_assignment_id'], 
                $prop['owner_child_id']
            );
            
            $this->repo->commit();

            // Actions post-transaction
            recalculate_child_score_history($prop['proposed_by_child_id']);
            recalculate_child_score_history($prop['owner_child_id']);

            require_once __DIR__ . '/../controllers/WeekController.php';
            $weekController = new WeekController();
            $weekController->notifyParentsForWeek(get_db(), 'PUBLISHED', $prop['week_number'], $prop['planning_week_id'], true, $exchangeMessage);
        } catch (Exception $e) {
            $this->repo->rollBack();
            throw $e;
        }
    }
}
