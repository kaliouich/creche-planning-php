<?php
/**
 * Algorithme de répartition des permanences.
 * Port exact de allocation.service.ts.
 *
 * Règle d'or : Un parent ne fait JAMAIS plus d'une seule permanence par semaine.
 */

/**
 * Lance l'algorithme de répartition pour une semaine donnée.
 *
 * @param array $slots       Liste des créneaux (slotId, dayOfWeek, halfDay, requiredParents, availableParentIds)
 * @param array $parentScores Scores historiques (parentId, firstName, lastName, score)
 * @return array Résultat (assignments, unfilledSlots, stats)
 */
function allocate(array $slots, array $parentScores): array {
    $assignments = [];
    $assignedParents = []; // Set: parentId => true
    $activeGroupUsed = 0;
    $reliefGroupUsed = 0;

    // Counter map: slotId => number of assigned parents (avoids linear scan)
    $assignmentCounts = [];
    foreach ($slots as $slot) {
        $assignmentCounts[$slot['slotId']] = 0;
    }

    // --- Séparation en 2 groupes basés sur le score ---
    // Groupe Actif : Score <= 0 (En dette ou à l'équilibre)
    $activeGroup = array_filter($parentScores, fn($p) => $p['score'] <= 0);
    usort($activeGroup, fn($a, $b) => $a['score'] <=> $b['score']);

    // Groupe Relâche : Score > 0 (En avance)
    $reliefGroup = array_filter($parentScores, fn($p) => $p['score'] > 0);
    usort($reliefGroup, fn($a, $b) => $a['score'] <=> $b['score']);

    // Fonctions utilitaires utilisant le counter map (O(1) au lieu de O(n))
    $getRemainingNeeded = function ($slot) use (&$assignmentCounts) {
        return $slot['requiredParents'] - ($assignmentCounts[$slot['slotId']] ?? 0);
    };

    $needsMoreParents = function ($slot) use ($getRemainingNeeded) {
        return $getRemainingNeeded($slot) > 0;
    };

    $addAssignment = function ($slotId, $parentId, &$groupUsed) use (&$assignments, &$assignedParents, &$assignmentCounts) {
        $assignments[] = ['slotId' => $slotId, 'parentId' => $parentId];
        $assignedParents[$parentId] = true;
        $assignmentCounts[$slotId] = ($assignmentCounts[$slotId] ?? 0) + 1;
        $groupUsed++;
    };

    // --- PHASE 1 : URGENCES (Cas Uniques) ---
    foreach ($slots as $slot) {
        if (!$needsMoreParents($slot)) continue;

        $availableActives = array_filter(
            $slot['availableParentIds'],
            function ($pid) use (&$assignedParents, $activeGroup) {
                if (isset($assignedParents[$pid])) return false;
                foreach ($activeGroup as $p) {
                    if ($p['parentId'] === $pid) return true;
                }
                return false;
            }
        );
        $availableActives = array_values($availableActives);

        if (count($availableActives) === 1 && $getRemainingNeeded($slot) > 0) {
            $addAssignment($slot['slotId'], $availableActives[0], $activeGroupUsed);
        }
    }

    // --- PHASE 2 : REMPLISSAGE STANDARD (Groupe Actif) ---
    // activeGroup is already sorted — no need to re-sort inside the loop
    foreach ($slots as $slot) {
        while ($needsMoreParents($slot)) {
            $candidates = array_filter($activeGroup, function ($p) use (&$assignedParents, $slot) {
                return !isset($assignedParents[$p['parentId']]) &&
                       in_array($p['parentId'], $slot['availableParentIds']);
            });
            $candidates = array_values($candidates);
            // Already sorted by score (inherited from $activeGroup sort order)

            if (!empty($candidates)) {
                $addAssignment($slot['slotId'], $candidates[0]['parentId'], $activeGroupUsed);
            } else {
                break;
            }
        }
    }

    // --- PHASE 3 : LE SECOURS (Groupe Relâche) ---
    // reliefGroup is already sorted — no need to re-sort inside the loop
    foreach ($slots as $slot) {
        while ($needsMoreParents($slot)) {
            $candidates = array_filter($reliefGroup, function ($p) use (&$assignedParents, $slot) {
                return !isset($assignedParents[$p['parentId']]) &&
                       in_array($p['parentId'], $slot['availableParentIds']);
            });
            $candidates = array_values($candidates);
            // Already sorted by score (inherited from $reliefGroup sort order)

            if (!empty($candidates)) {
                $addAssignment($slot['slotId'], $candidates[0]['parentId'], $reliefGroupUsed);
            } else {
                break;
            }
        }
    }

    // --- Statistiques et slots non remplis ---
    $unfilledSlots = [];
    foreach ($slots as $slot) {
        $assigned = $assignmentCounts[$slot['slotId']] ?? 0;
        if ($assigned < $slot['requiredParents']) {
            $unfilledSlots[] = [
                'slotId'    => $slot['slotId'],
                'dayOfWeek' => $slot['dayOfWeek'],
                'halfDay'   => $slot['halfDay'],
                'required'  => $slot['requiredParents'],
                'assigned'  => $assigned,
            ];
        }
    }

    $totalPlaces = array_reduce($slots, fn($acc, $s) => $acc + $s['requiredParents'], 0);

    return [
        'assignments'   => $assignments,
        'unfilledSlots' => $unfilledSlots,
        'stats'         => [
            'totalSlots'      => count($slots),
            'totalPlaces'     => $totalPlaces,
            'filledPlaces'    => count($assignments),
            'activeGroupUsed' => $activeGroupUsed,
            'reliefGroupUsed' => $reliefGroupUsed,
        ],
    ];
}
