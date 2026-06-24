<?php
require_once __DIR__ . '/vendor/autoload.php';

function runBenchmark(int $numChildren) {
    // 10 slots par semaine (5 jours * 2 demi-journées)
    $slots = [];
    $days = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY'];
    $halfs = ['MORNING', 'AFTERNOON'];
    
    // On génère N parents avec des scores aléatoires
    $parentScores = [];
    for ($i = 0; $i < $numChildren; $i++) {
        $parentScores[] = [
            'parentId' => "parent_$i",
            'firstName' => "Parent",
            'lastName' => "$i",
            'score' => (rand(-100, 100) / 10) // Score de -10 à +10
        ];
    }
    
    $slotId = 1;
    foreach ($days as $day) {
        foreach ($halfs as $half) {
            // Environ 30% des parents sont dispo pour chaque créneau
            $availables = [];
            for ($i = 0; $i < $numChildren; $i++) {
                if (rand(1, 100) <= 30) {
                    $availables[] = "parent_$i";
                }
            }
            
            $slots[] = [
                'slotId' => "slot_$slotId",
                'dayOfWeek' => $day,
                'halfDay' => $half,
                'requiredParents' => 1,
                'availableParentIds' => $availables
            ];
            $slotId++;
        }
    }

    $start = microtime(true);
    $result = allocate($slots, $parentScores);
    $end = microtime(true);
    
    $timeMs = round(($end - $start) * 1000, 2);
    
    echo "--- Test avec $numChildren enfants ---\n";
    echo "Temps d'exécution : {$timeMs} ms\n";
    echo "Places remplies   : {$result['stats']['filledPlaces']} / {$result['stats']['totalPlaces']}\n";
    echo "Non remplis       : " . count($result['unfilledSlots']) . " slots\n\n";
}

runBenchmark(20);
runBenchmark(50);
runBenchmark(100);
runBenchmark(500);
runBenchmark(1000);
