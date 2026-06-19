<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/services/allocation.php';
require_once __DIR__ . '/services/score.php';

function log_msg($msg) {
    echo date('[Y-m-d H:i:s]') . " " . $msg . "\n";
}

try {
    $pdo = get_db();

    $pdo->exec("DELETE FROM score_histories WHERE year = 2099");
    // Nettoyage de la semaine 100 et des enfants de test
    $pdo->exec("DELETE FROM planning_weeks WHERE week_number = 100 AND year = 2099");
    $pdo->exec("DELETE FROM children WHERE first_name LIKE 'EnfantTest%'");
    $pdo->exec("DELETE FROM users WHERE email LIKE 'parent_test_%@test.com'");

    log_msg("=== Début du Test de Charge (Multiples Enfants) ===");

    $weekId = generate_uuid();
    $pdo->prepare("INSERT INTO planning_weeks (id, week_number, year, status) VALUES (?, 100, 2099, 'OPEN_TO_PARENTS')")
        ->execute([$weekId]);

    // On va créer 4 créneaux (besoin de 4 permanences au total)
    $slots = [];
    $days = ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY'];
    foreach ($days as $day) {
        $slotId = generate_uuid();
        $pdo->prepare("INSERT INTO slots (id, planning_week_id, day_of_week, half_day, required_parents) VALUES (?, ?, ?, 'MORNING', 1)")
            ->execute([$slotId, $weekId, $day]);
        $slots[] = ['id' => $slotId, 'day' => $day];
    }
    
    log_msg("Semaine 100 créée avec 4 créneaux de 1 place (Besoin total = 4 parents).");

    // Création de 3 enfants avec des contrats différents
    $childrenData = [
        ['name' => 'EnfantTest TempsPlein', 'days' => ['MONDAY', 'TUESDAY', 'WEDNESDAY', 'THURSDAY', 'FRIDAY']], // 5 demi-journées
        ['name' => 'EnfantTest MiTemps', 'days' => ['MONDAY', 'TUESDAY']], // 2 demi-journées
        ['name' => 'EnfantTest Occasionnel', 'days' => ['WEDNESDAY']], // 1 demi-journée
    ];

    $childrenIds = [];
    $parentIds = [];
    $algoSlots = [];

    // Préparer l'algo
    foreach ($slots as $s) {
        $algoSlots[] = ['slotId' => $s['id'], 'dayOfWeek' => $s['day'], 'halfDay' => 'MORNING', 'requiredParents' => 1, 'availableParentIds' => []];
    }

    $parentScores = [];

    foreach ($childrenData as $index => $cData) {
        $parentId = generate_uuid();
        $childId = generate_uuid();
        $parentIds[] = $parentId;
        $childrenIds[] = $childId;

        $pdo->prepare("INSERT INTO users (id, first_name, last_name, email, password_hash, role) VALUES (?, 'Parent', ?, ?, 'hash', 'PARENT')")
            ->execute([$parentId, $cData['name'], "parent_test_{$index}@test.com"]);
            
        $pdo->prepare("INSERT INTO children (id, first_name, last_name, parent_id) VALUES (?, ?, 'Test', ?)")
            ->execute([$childId, $cData['name'], $parentId]);

        // Ajouter les contrats
        foreach ($cData['days'] as $day) {
            $pdo->prepare("INSERT INTO child_default_presences (id, child_id, day_of_week, half_day) VALUES (?, ?, ?, 'MORNING')")
                ->execute([generate_uuid(), $childId, $day]);
        }

        // Simuler les disponibilités : tout le monde est dispo tout le temps (s'il est présent ce jour-là)
        foreach ($algoSlots as &$algoSlot) {
            if (in_array($algoSlot['dayOfWeek'], $cData['days'])) {
                $pdo->prepare("INSERT INTO availabilities (id, child_id, slot_id, is_available) VALUES (?, ?, ?, 1)")
                    ->execute([generate_uuid(), $childId, $algoSlot['slotId']]);
                $pdo->prepare("INSERT INTO child_presences (id, child_id, slot_id, is_present) VALUES (?, ?, ?, 1)")
                    ->execute([generate_uuid(), $childId, $algoSlot['slotId']]);
                
                $algoSlot['availableParentIds'][] = $childId;
            }
        }

        // Score de départ à 0
        $pdo->prepare("INSERT INTO score_histories (id, child_id, week_number, year, score_before, permanences_done, permanences_due, score_after) VALUES (?, ?, 99, 2099, 0, 0, 0, 0)")
            ->execute([generate_uuid(), $childId]);

        $parentScores[] = ['parentId' => $childId, 'score' => 0];

        log_msg("Créé : {$cData['name']} (Contrat = " . count($cData['days']) . " présences/semaine).");
    }

    // Poids total = 5 + 2 + 1 = 8. Besoin = 4. 
    // Dette prévue pour TempsPlein = 4 * (5/8) = 2.5
    // Dette prévue pour MiTemps = 4 * (2/8) = 1.0
    // Dette prévue pour Occasionnel = 4 * (1/8) = 0.5

    // Lancement de l'allocation
    $result = allocate($algoSlots, $parentScores);

    log_msg("=== Résultat de l'Assignation ===");
    $counts = array_fill_keys($childrenIds, 0);
    foreach ($result['assignments'] as $a) {
        $counts[$a['parentId']]++;
        $pdo->prepare("INSERT INTO assignments (id, child_id, slot_id, assigned_at) VALUES (?, ?, ?, NOW())")
            ->execute([generate_uuid(), $a['parentId'], $a['slotId']]);
    }

    foreach ($childrenData as $index => $cData) {
        $id = $childrenIds[$index];
        log_msg("- {$cData['name']} a reçu {$counts[$id]} permanence(s).");
    }

    // Publication et Score
    log_msg("=== Publication (Calcul des Dettes) ===");
    snapshot_scores_for_week($weekId, 100, 2099);

    foreach ($childrenData as $index => $cData) {
        $id = $childrenIds[$index];
        $stmt = $pdo->prepare("SELECT permanences_due, score_after FROM score_histories WHERE week_number = 100 AND year = 2099 AND child_id = ?");
        $stmt->execute([$id]);
        $history = $stmt->fetch();

        log_msg("=> {$cData['name']} : Dette (Fair Share) = " . $history['permanences_due'] . " | Score Final = " . $history['score_after']);
    }

} catch (Exception $e) {
    log_msg("ERREUR: " . $e->getMessage());
} finally {
    $pdo->exec("DELETE FROM planning_weeks WHERE week_number = 100 AND year = 2099");
    $pdo->exec("DELETE FROM children WHERE first_name LIKE 'EnfantTest%'");
    $pdo->exec("DELETE FROM users WHERE email LIKE 'parent_test_%@test.com'");
}
