<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../services/allocation.php';

class AllocationTest extends TestCase
{
    public function testAlgorithmPrioritizesParentsInDebt()
    {
        // 1. Setup 3 parents with different scores
        $parentScores = [
            ['parentId' => 'p1', 'score' => 5.0], // Très en avance, ne doit pas être sélectionné
            ['parentId' => 'p2', 'score' => 0.0], // Equilibré
            ['parentId' => 'p3', 'score' => -3.0], // En dette, DOIT être sélectionné
        ];

        // 2. Setup 1 créneau (besoin de 1 parent)
        $slots = [
            [
                'slotId' => 's1',
                'dayOfWeek' => 'MONDAY',
                'halfDay' => 'MORNING',
                'requiredParents' => 1,
                'availableParentIds' => ['p1', 'p2', 'p3']
            ]
        ];

        $result = allocate($slots, $parentScores);

        $this->assertCount(1, $result['assignments']);
        $this->assertEquals('p3', $result['assignments'][0]['parentId'], 'Le parent le plus en dette doit être sélectionné.');
    }

    public function testAlgorithmOnlyAssignsOnePermanencePerWeek()
    {
        $parentScores = [
            ['parentId' => 'p1', 'score' => -10.0], // Très en dette
        ];

        // 2. Setup 2 créneaux, p1 est dispo pour les deux
        $slots = [
            [
                'slotId' => 's1',
                'dayOfWeek' => 'MONDAY',
                'halfDay' => 'MORNING',
                'requiredParents' => 1,
                'availableParentIds' => ['p1']
            ],
            [
                'slotId' => 's2',
                'dayOfWeek' => 'TUESDAY',
                'halfDay' => 'MORNING',
                'requiredParents' => 1,
                'availableParentIds' => ['p1']
            ]
        ];

        $result = allocate($slots, $parentScores);

        // Bien que p1 soit en dette, il ne doit recevoir qu'UNE SEULE permanence maximum.
        $this->assertCount(1, $result['assignments']);
        $this->assertCount(1, $result['unfilledSlots'], 'Un créneau doit rester vide car le parent a déjà fait sa permanence de la semaine.');
    }

    public function testAlgorithmEmergencyAssignment()
    {
        $parentScores = [
            ['parentId' => 'p1', 'score' => 2.0], // Relâche (normalement non prioritaire)
            ['parentId' => 'p2', 'score' => -2.0], // Dette
        ];

        $slots = [
            [
                'slotId' => 's1',
                'dayOfWeek' => 'MONDAY',
                'halfDay' => 'MORNING',
                'requiredParents' => 1,
                'availableParentIds' => ['p1'] // SEUL p1 est disponible
            ],
            [
                'slotId' => 's2',
                'dayOfWeek' => 'TUESDAY',
                'halfDay' => 'MORNING',
                'requiredParents' => 1,
                'availableParentIds' => ['p1', 'p2']
            ]
        ];

        $result = allocate($slots, $parentScores);

        // p1 est le seul dispo pour s1, donc il DOIT être pris même s'il est en relâche.
        // p2 est en dette, il DOIT avoir s2.
        $this->assertCount(2, $result['assignments']);
        
        $assignedToS1 = array_filter($result['assignments'], fn($a) => $a['slotId'] === 's1');
        $this->assertEquals('p1', array_values($assignedToS1)[0]['parentId']);
        
        $assignedToS2 = array_filter($result['assignments'], fn($a) => $a['slotId'] === 's2');
        $this->assertEquals('p2', array_values($assignedToS2)[0]['parentId']);
    }
}
