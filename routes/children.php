<?php
/**
 * Routes de gestion des enfants.
 * GET  /children     — Liste des enfants
 * POST /children     — Ajouter un enfant
 * PUT  /children/:id — Mettre à jour un enfant
 */

function handle_children(string $route, string $method): void {
    if ($route === '' && $method === 'GET') {
        children_list();
    } elseif ($route === '' && $method === 'POST') {
        children_create();
    } elseif (preg_match('#^([a-f0-9\-]+)$#', $route, $m) && $method === 'PUT') {
        children_update($m[1]);
    } elseif (preg_match('#^([a-f0-9\-]+)$#', $route, $m) && $method === 'DELETE') {
        children_delete($m[1]);
    } else {
        json_response(['error' => 'Route non trouvée'], 404);
    }
}

function children_list(): void {
    $user = require_auth();
    $pdo = get_db();
    require_once __DIR__ . '/../services/score.php';

    $sql = '
        SELECT c.id, c.first_name, c.last_name, c.parent_id, c.is_active, c.age_group, c.created_at,
               c.parent1_first_name, c.parent2_first_name, c.parent1_email, c.parent2_email
        FROM children c
        ORDER BY c.last_name ASC
    ';

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    // Récupérer les présences par défaut
    $presStmt = $pdo->query('SELECT * FROM child_default_presences');
    $allPresences = $presStmt->fetchAll();
    $presencesByChild = [];
    foreach ($allPresences as $p) {
        $presencesByChild[$p['child_id']][] = [
            'id'        => $p['id'],
            'childId'   => $p['child_id'],
            'dayOfWeek' => $p['day_of_week'],
            'halfDay'   => $p['half_day'],
        ];
    }

    $children = [];
    foreach ($rows as $r) {
        $score = get_current_score($r['id']);
        
        $children[] = [
            'id'        => $r['id'],
            'firstName' => $r['first_name'],
            'lastName'  => $r['last_name'],
            'parentId'  => $r['parent_id'],
            'isActive'  => (bool) $r['is_active'],
            'ageGroup'  => $r['age_group'],
            'createdAt' => $r['created_at'],
            'score'     => $score,
            'parent'    => [
                'id'          => $r['parent_id'],
                'firstName'   => $r['parent1_first_name'],
                'lastName'    => $r['parent2_first_name'],
                'email'       => $r['parent1_email'],
                'secondEmail' => $r['parent2_email'],
            ],
            'defaultPresences' => $presencesByChild[$r['id']] ?? [],
        ];
    }

    json_response($children);
}

function children_create(): void {
    $user = require_auth();
    verify_csrf();
    require_role($user, ['ADMIN', 'PROFESSIONAL']);

    $body = get_json_body();
    $firstName = trim($body['firstName'] ?? '');
    $lastName  = trim($body['lastName'] ?? '');
    $ageGroup  = $body['ageGroup'] ?? 'GRAND';
    $siblingId = $body['siblingId'] ?? null;
    $parent1Email = trim($body['parent1Email'] ?? '');
    $parent2Email = trim($body['parent2Email'] ?? '');
    $defaultPresences = $body['defaultPresences'] ?? [];

    if (!validate_string($firstName, 1, 100) || !validate_string($lastName, 1, 100)) {
        json_response(['error' => 'Prénom et nom requis'], 400);
        return;
    }

    if (!in_array($ageGroup, ['PETIT', 'GRAND'])) {
        json_response(['error' => 'Groupe d\'âge invalide'], 400);
        return;
    }

    $pdo = get_db();
    $pdo->beginTransaction();

    try {
        // Trouver le compte global Parent
        $stmt = $pdo->prepare('SELECT id FROM users WHERE role = "PARENT" LIMIT 1');
        $stmt->execute();
        $globalParent = $stmt->fetch();
        if (!$globalParent) {
            $pdo->rollBack();
            json_response(['error' => 'Compte global Parent introuvable'], 500);
            return;
        }
        $parentId = $globalParent['id'];

        // Créer l'enfant
        $childId = generate_uuid();
        $now = date('Y-m-d H:i:s');
        
        $parent1Name = trim($body['parent1FirstName'] ?? 'Famille');
        $parent2Name = trim($body['parent2FirstName'] ?? '');

        $stmt = $pdo->prepare('INSERT INTO children (id, first_name, last_name, parent_id, is_active, age_group, created_at, parent1_first_name, parent2_first_name, parent1_email, parent2_email) VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$childId, $firstName, $lastName, $parentId, $ageGroup, $now, $parent1Name, $parent2Name, $parent1Email, empty($parent2Email) ? null : $parent2Email]);

        // Créer les présences par défaut
        $createdPresences = [];
        if (!empty($defaultPresences)) {
            $presStmt = $pdo->prepare('INSERT INTO child_default_presences (id, child_id, day_of_week, half_day) VALUES (?, ?, ?, ?)');
            foreach ($defaultPresences as $dp) {
                $presId = generate_uuid();
                $presStmt->execute([$presId, $childId, $dp['dayOfWeek'], $dp['halfDay']]);
                $createdPresences[] = [
                    'id'        => $presId,
                    'childId'   => $childId,
                    'dayOfWeek' => $dp['dayOfWeek'],
                    'halfDay'   => $dp['halfDay'],
                ];
            }
        }

        $pdo->commit();

        json_response([
            'id'        => $childId,
            'firstName' => $firstName,
            'lastName'  => $lastName,
            'ageGroup'  => $ageGroup,
            'parentId'  => $parentId,
            'isActive'  => true,
            'createdAt' => $now,
            'parent'    => [
                'id'        => $parentId,
                'firstName' => $parent1Name,
                'lastName'  => $parent2Name,
            ],
            'defaultPresences' => $createdPresences,
        ], 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function children_update(string $childId): void {
    $user = require_auth();
    verify_csrf();
    require_role($user, ['ADMIN', 'PROFESSIONAL']);

    if (!validate_uuid($childId)) {
        json_response(['error' => 'ID invalide'], 400);
        return;
    }

    $body = get_json_body();
    $pdo = get_db();

    $stmt = $pdo->prepare('SELECT * FROM children WHERE id = ?');
    $stmt->execute([$childId]);
    $child = $stmt->fetch();

    if (!$child) {
        json_response(['error' => 'Enfant introuvable'], 404);
        return;
    }

    $pdo->beginTransaction();
    try {
        // Mettre à jour les infos de base
        $firstName = trim($body['firstName'] ?? $child['first_name']);
        $lastName  = trim($body['lastName'] ?? $child['last_name']);
        $ageGroup  = $body['ageGroup'] ?? $child['age_group'];

        $stmt = $pdo->prepare('UPDATE children SET first_name = ?, last_name = ?, age_group = ? WHERE id = ?');
        $stmt->execute([$firstName, $lastName, $ageGroup, $childId]);
        
        // Mettre à jour les noms et emails des parents si fournis
        if (isset($body['parent1FirstName']) || isset($body['parent2FirstName']) || isset($body['parent1Email']) || isset($body['parent2Email'])) {
            $p1 = trim($body['parent1FirstName'] ?? $child['parent1_first_name'] ?? '');
            $p2 = trim($body['parent2FirstName'] ?? $child['parent2_first_name'] ?? '');
            $e1 = trim($body['parent1Email'] ?? $child['parent1_email'] ?? '');
            $e2 = trim($body['parent2Email'] ?? $child['parent2_email'] ?? '');
            
            $stmt = $pdo->prepare('UPDATE children SET parent1_first_name = ?, parent2_first_name = ?, parent1_email = ?, parent2_email = ? WHERE id = ?');
            $stmt->execute([$p1, $p2, $e1, $e2, $childId]);
        }

        // Mettre à jour les présences par défaut
        $createdPresences = [];
        if (isset($body['defaultPresences'])) {
            // Supprimer les anciennes
            $pdo->prepare('DELETE FROM child_default_presences WHERE child_id = ?')->execute([$childId]);

            // Créer les nouvelles
            if (!empty($body['defaultPresences'])) {
                $presStmt = $pdo->prepare('INSERT INTO child_default_presences (id, child_id, day_of_week, half_day) VALUES (?, ?, ?, ?)');
                foreach ($body['defaultPresences'] as $dp) {
                    $presId = generate_uuid();
                    $presStmt->execute([$presId, $childId, $dp['dayOfWeek'], $dp['halfDay']]);
                    $createdPresences[] = [
                        'id'        => $presId,
                        'childId'   => $childId,
                        'dayOfWeek' => $dp['dayOfWeek'],
                        'halfDay'   => $dp['halfDay'],
                    ];
                }
            }
        } else {
            // Récupérer les existantes
            $stmt = $pdo->prepare('SELECT * FROM child_default_presences WHERE child_id = ?');
            $stmt->execute([$childId]);
            foreach ($stmt->fetchAll() as $p) {
                $createdPresences[] = [
                    'id'        => $p['id'],
                    'childId'   => $p['child_id'],
                    'dayOfWeek' => $p['day_of_week'],
                    'halfDay'   => $p['half_day'],
                ];
            }
        }

        // Récupérer le parent
        $stmt = $pdo->prepare('SELECT id, first_name, last_name FROM users WHERE id = ?');
        $stmt->execute([$child['parent_id']]);
        $parent = $stmt->fetch();

        $pdo->commit();

        json_response([
            'id'        => $childId,
            'firstName' => $firstName,
            'lastName'  => $lastName,
            'ageGroup'  => $ageGroup,
            'parentId'  => $child['parent_id'],
            'isActive'  => (bool) $child['is_active'],
            'createdAt' => $child['created_at'],
            'parent'    => [
                'id'          => $parent['id'],
                'firstName'   => $parent['first_name'],
                'lastName'    => $parent['last_name'],
                'email'       => $parent['email'] ?? '',
                'secondEmail' => $parent['second_email'] ?? '',
            ],
            'defaultPresences' => $createdPresences,
        ]);
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function children_delete(string $childId): void {
    $user = require_auth();
    verify_csrf();
    require_role($user, ['ADMIN', 'PROFESSIONAL']);

    if (!validate_uuid($childId)) {
        json_response(['error' => 'ID invalide'], 400);
        return;
    }

    $pdo = get_db();

    // Check if child exists and get parent_id
    $stmt = $pdo->prepare('SELECT id, parent_id FROM children WHERE id = ?');
    $stmt->execute([$childId]);
    $child = $stmt->fetch();
    if (!$child) {
        json_response(['error' => 'Enfant introuvable'], 404);
        return;
    }

    $pdo->beginTransaction();
    try {
        // Supprimer les présences par défaut
        $pdo->prepare('DELETE FROM child_default_presences WHERE child_id = ?')->execute([$childId]);
        
        // Supprimer l'enfant
        $pdo->prepare('DELETE FROM children WHERE id = ?')->execute([$childId]);


        
        $pdo->commit();
        json_response(['message' => 'Enfant supprimé avec succès']);
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
