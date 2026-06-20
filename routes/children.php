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
               u.id as parent_db_id, u.first_name as parent_first_name, u.last_name as parent_last_name, u.email as parent_email, u.second_email as parent_second_email
        FROM children c
        JOIN users u ON c.parent_id = u.id
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
                'id'          => $r['parent_db_id'],
                'firstName'   => $r['parent_first_name'],
                'lastName'    => $r['parent_last_name'],
                'email'       => $r['parent_email'],
                'secondEmail' => $r['parent_second_email'],
            ],
            'defaultPresences' => $presencesByChild[$r['id']] ?? [],
        ];
    }

    json_response($children);
}

function children_create(): void {
    $user = require_auth();
    verify_csrf();
    require_role($user, 'ADMIN');

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
        $parentId = '';

        if ($siblingId) {
            // Récupérer le parent de la fratrie
            $stmt = $pdo->prepare('SELECT parent_id FROM children WHERE id = ?');
            $stmt->execute([$siblingId]);
            $sibling = $stmt->fetch();
            if (!$sibling) {
                $pdo->rollBack();
                json_response(['error' => 'Enfant spécifié comme fratrie introuvable'], 400);
                return;
            }
            $parentId = $sibling['parent_id'];
        } else {
            // Créer un compte Famille
            if (empty($parent1Email)) {
                $pdo->rollBack();
                json_response(['error' => 'L\'adresse email du Parent 1 est obligatoire pour créer une nouvelle famille'], 400);
                return;
            }

            if (!filter_var($parent1Email, FILTER_VALIDATE_EMAIL)) {
                $pdo->rollBack();
                json_response(['error' => 'L\'adresse email du Parent 1 est invalide'], 400);
                return;
            }

            if (!empty($parent2Email) && !filter_var($parent2Email, FILTER_VALIDATE_EMAIL)) {
                $pdo->rollBack();
                json_response(['error' => 'L\'adresse email du Parent 2 est invalide'], 400);
                return;
            }

            // Vérifier si l'email existe déjà
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $stmt->execute([$parent1Email]);
            if ($stmt->fetch()) {
                $pdo->rollBack();
                json_response(['error' => 'Un compte existe déjà avec cette adresse email'], 400);
                return;
            }

            $parentId = generate_uuid();
            $passwordHash = password_hash('password123', PASSWORD_BCRYPT);
            $now = date('Y-m-d H:i:s');
            
            $parent1Name = trim($body['parent1FirstName'] ?? 'Famille');
            $parent2Name = trim($body['parent2FirstName'] ?? '');

            $stmt = $pdo->prepare('INSERT INTO users (id, email, second_email, password_hash, first_name, last_name, role, is_active, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)');
            $stmt->execute([$parentId, $parent1Email, empty($parent2Email) ? null : $parent2Email, $passwordHash, $parent1Name, $parent2Name, 'PARENT', $now, $now]);
        }

        // Créer l'enfant
        $childId = generate_uuid();
        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare('INSERT INTO children (id, first_name, last_name, parent_id, is_active, age_group, created_at) VALUES (?, ?, ?, ?, 1, ?, ?)');
        $stmt->execute([$childId, $firstName, $lastName, $parentId, $ageGroup, $now]);

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

        // Récupérer le parent
        $stmt = $pdo->prepare('SELECT id, first_name, last_name FROM users WHERE id = ?');
        $stmt->execute([$parentId]);
        $parent = $stmt->fetch();

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
                'id'        => $parent['id'],
                'firstName' => $parent['first_name'],
                'lastName'  => $parent['last_name'],
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
    require_role($user, 'ADMIN');

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
            $p1 = trim($body['parent1FirstName'] ?? '');
            $p2 = trim($body['parent2FirstName'] ?? '');
            $e1 = trim($body['parent1Email'] ?? '');
            $e2 = trim($body['parent2Email'] ?? '');
            
            $updateFields = [];
            $updateValues = [];
            
            if ($p1 !== '') {
                $updateFields[] = 'first_name = ?';
                $updateValues[] = $p1;
            }
            if ($p2 !== '') {
                $updateFields[] = 'last_name = ?';
                $updateValues[] = $p2;
            }
            if ($e1 !== '') {
                // Check if email already exists for another user
                $checkEmailStmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
                $checkEmailStmt->execute([$e1, $child['parent_id']]);
                if ($checkEmailStmt->fetch()) {
                    $pdo->rollBack();
                    json_response(['error' => 'Cette adresse email est déjà utilisée par une autre famille.'], 400);
                    return;
                }
                $updateFields[] = 'email = ?';
                $updateValues[] = $e1;
            }
            if ($e2 !== '') {
                $updateFields[] = 'second_email = ?';
                $updateValues[] = $e2;
            }
            
            if (!empty($updateFields)) {
                $updateValues[] = $child['parent_id'];
                $pStmt = $pdo->prepare('UPDATE users SET ' . implode(', ', $updateFields) . ' WHERE id = ?');
                $pStmt->execute($updateValues);
            }
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
    require_role($user, 'ADMIN');

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

        // Vérifier s'il reste d'autres enfants pour ce parent
        $stmtCheck = $pdo->prepare('SELECT COUNT(*) FROM children WHERE parent_id = ?');
        $stmtCheck->execute([$child['parent_id']]);
        if ($stmtCheck->fetchColumn() == 0) {
            // Aucun autre enfant, on peut supprimer le parent
            $pdo->prepare('DELETE FROM users WHERE id = ? AND role = "PARENT"')->execute([$child['parent_id']]);
        }
        
        $pdo->commit();
        json_response(['message' => 'Enfant supprimé avec succès']);
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
}
