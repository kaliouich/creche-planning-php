<?php

require_once __DIR__ . '/../services/score.php';

function handle_score_adjustments(string $route, string $method): void {
    if ($route === 'matrix' && $method === 'GET') {
        get_score_matrix();
    } elseif ($route === '' && $method === 'PATCH') {
        patch_score_adjustment();
    } else {
        json_response(['error' => 'Route non trouvée'], 404);
    }
}

function get_score_matrix(): void {
    $user = require_auth();
    require_role($user, 'ADMIN');

    $pdo = get_db();

    // 1. Récupérer toutes les semaines publiées (depuis planning_weeks)
    $stmt = $pdo->query("SELECT id, week_number, year FROM planning_weeks WHERE status = 'PUBLISHED' ORDER BY year ASC, week_number ASC");
    $weeks = $stmt->fetchAll();

    // 2. Récupérer tous les enfants actifs avec leurs informations
    $stmt = $pdo->query("
        SELECT c.id, c.first_name, c.last_name, 
               u.id as parent_id, u.first_name as parent_first_name, u.last_name as parent_last_name
        FROM children c
        JOIN users u ON c.parent_id = u.id
        WHERE c.is_active = 1
        ORDER BY c.last_name ASC, c.first_name ASC
    ");
    $childrenRows = $stmt->fetchAll();

    $children = [];
    foreach ($childrenRows as $row) {
        $childId = $row['id'];
        
        // Obtenir l'historique de score pour cet enfant
        $hStmt = $pdo->prepare("SELECT week_number, year, permanences_done FROM score_histories WHERE child_id = ?");
        $hStmt->execute([$childId]);
        $historiesRaw = $hStmt->fetchAll();
        
        // Transformer en dictionnaire pour accès facile par "year-week_number"
        $histories = [];
        foreach ($historiesRaw as $h) {
            $key = $h['year'] . '-' . $h['week_number'];
            $histories[$key] = [
                'permanencesDone' => (int)$h['permanences_done']
            ];
        }

        // Score courant
        $currentScore = get_current_score($childId);

        $children[] = [
            'id' => $childId,
            'firstName' => $row['first_name'],
            'lastName' => $row['last_name'],
            'parentFirstName' => $row['parent_first_name'],
            'parentLastName' => $row['parent_last_name'],
            'score' => $currentScore,
            'histories' => $histories
        ];
    }

    json_response([
        'weeks' => array_map(fn($w) => [
            'id' => $w['id'],
            'weekNumber' => (int)$w['week_number'],
            'year' => (int)$w['year']
        ], $weeks),
        'children' => $children
    ]);
}

function patch_score_adjustment(): void {
    $user = require_auth();
    verify_csrf();
    require_role($user, 'ADMIN');

    $body = get_json_body();
    
    $childId = $body['childId'] ?? null;
    $weekNumber = $body['weekNumber'] ?? null;
    $year = $body['year'] ?? null;
    $permanencesDone = $body['permanencesDone'] ?? null;

    if (!$childId || !isset($weekNumber, $year, $permanencesDone)) {
        json_response(['error' => 'Paramètres manquants'], 400);
        return;
    }

    $pdo = get_db();
    
    // Vérifier si l'enregistrement existe dans score_histories
    $stmt = $pdo->prepare("SELECT id FROM score_histories WHERE child_id = ? AND week_number = ? AND year = ?");
    $stmt->execute([$childId, $weekNumber, $year]);
    $historyId = $stmt->fetchColumn();

    if ($historyId) {
        $update = $pdo->prepare("UPDATE score_histories SET permanences_done = ? WHERE id = ?");
        $update->execute([$permanencesDone, $historyId]);
    } else {
        // Optionnel : s'il n'y a pas d'historique pour cet enfant sur cette semaine publiée
        // (par exemple enfant ajouté après publication). On peut créer un record vierge.
        $insert = $pdo->prepare("
            INSERT INTO score_histories (id, child_id, week_number, year, score_before, permanences_done, permanences_due, score_after)
            VALUES (?, ?, ?, ?, 0, ?, 0, 0)
        ");
        $insert->execute([generate_uuid(), $childId, $weekNumber, $year, $permanencesDone]);
    }

    // Recalculer l'ensemble de l'historique de l'enfant
    recalculate_child_score_history($childId);

    // Renvoyer le nouveau score
    $newScore = get_current_score($childId);
    
    json_response(['success' => true, 'newScore' => $newScore]);
}
