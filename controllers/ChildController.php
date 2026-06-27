<?php

class ChildController {
    public function handle(string $route, string $method): void {
        if ($route === '' && $method === 'GET') {
            $this->list();
        } elseif ($route === '' && $method === 'POST') {
            $this->create();
        } elseif (preg_match('#^([a-f0-9\-]+)$#', $route, $m) && $method === 'PUT') {
            $this->update($m[1]);
        } elseif (preg_match('#^([a-f0-9\-]+)$#', $route, $m) && $method === 'DELETE') {
            $this->delete($m[1]);
        } elseif (preg_match('#^([a-f0-9\-]+)/absences$#', $route, $m) && $method === 'GET') {
            $this->listAbsences($m[1]);
        } elseif (preg_match('#^([a-f0-9\-]+)/absences$#', $route, $m) && $method === 'POST') {
            $this->createAbsence($m[1]);
        } elseif (preg_match('#^([a-f0-9\-]+)/absences/([a-f0-9\-]+)$#', $route, $m) && $method === 'PUT') {
            $this->updateAbsence($m[1], $m[2]);
        } elseif (preg_match('#^([a-f0-9\-]+)/absences/([a-f0-9\-]+)$#', $route, $m) && $method === 'DELETE') {
            $this->deleteAbsence($m[1], $m[2]);
        } elseif (preg_match('#^([a-f0-9\-]+)/history$#', $route, $m) && $method === 'GET') {
            $this->history($m[1]);
        } else {
            json_response(['error' => 'Route non trouvée'], 404);
        }
    }

    private function list(): void {
        $user = require_auth();
        $pdo = get_db();

        $sql = '
            SELECT c.id, c.first_name, c.last_name, c.parent_id, c.parent2_id, c.is_active, c.age_group, c.created_at,
                   c.parent1_first_name, c.parent2_first_name, c.parent1_email, c.parent2_email
            FROM children c
            ORDER BY c.last_name ASC
        ';

        $stmt = $pdo->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Récupérer les présences par défaut (1 query)
        $presStmt = $pdo->query('SELECT * FROM child_default_presences');
        $allPresences = $presStmt->fetchAll(PDO::FETCH_ASSOC);
        $presencesByChild = [];
        foreach ($allPresences as $p) {
            $presencesByChild[$p['child_id']][] = [
                'id'        => $p['id'],
                'childId'   => $p['child_id'],
                'dayOfWeek' => $p['day_of_week'],
                'halfDay'   => $p['half_day'],
            ];
        }

        // Batch score fetch: get latest score_after for each child in 1 query (fixes N+1)
        $scoreStmt = $pdo->query('
            SELECT sh.child_id, sh.score_after 
            FROM score_histories sh
            INNER JOIN (
                SELECT child_id, MAX(snapshot_at) as max_snapshot 
                FROM score_histories 
                GROUP BY child_id
            ) latest ON sh.child_id = latest.child_id AND sh.snapshot_at = latest.max_snapshot
        ');
        $scoreRows = $scoreStmt->fetchAll(PDO::FETCH_ASSOC);
        $scoresByChild = [];
        foreach ($scoreRows as $s) {
            $scoresByChild[$s['child_id']] = (float) $s['score_after'];
        }

        $today = (new DateTime())->format('Y-m-d');
        $absStmt = $pdo->prepare('
            SELECT DISTINCT child_id 
            FROM child_absences 
            WHERE start_date <= ? AND (end_date IS NULL OR end_date >= ?)
        ');
        $absStmt->execute([$today, $today]);
        $currentlyAbsentChildren = $absStmt->fetchAll(PDO::FETCH_COLUMN);

        $isParentRole = $user['role'] === 'PARENT';

        $children = [];
        foreach ($rows as $r) {
            $isOwnChild = $r['parent_id'] === $user['userId'];
            $hidePII = $isParentRole && !$isOwnChild;

            $children[] = [
                'id'        => $r['id'],
                'firstName' => $r['first_name'],
                'lastName'  => $hidePII ? mb_substr($r['last_name'], 0, 1) . '.' : $r['last_name'],
                'parentId'  => $r['parent_id'],
                'isActive'  => (bool) $r['is_active'],
                'ageGroup'  => $r['age_group'],
                'createdAt' => $r['created_at'],
                'score'     => $scoresByChild[$r['id']] ?? 0.0,
                'isCurrentlyAbsent' => in_array($r['id'], $currentlyAbsentChildren),
                'parent'    => [
                    'id'          => $r['parent_id'],
                    'secondId'    => $r['parent2_id'],
                    'firstName'   => $hidePII ? null : $r['parent1_first_name'],
                    'lastName'    => $hidePII ? null : $r['parent2_first_name'],
                    'email'       => $hidePII ? null : $r['parent1_email'],
                    'secondEmail' => $hidePII ? null : $r['parent2_email'],
                ],
                'defaultPresences' => $presencesByChild[$r['id']] ?? [],
            ];
        }

        json_response($children);
    }

    private function create(): void {
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
            $parentId = null;
            $parent2Id = null;
            $appUrl = rtrim($body['appUrl'] ?? 'http://localhost:5173', '/');
            $parent1Name = trim($body['parent1FirstName'] ?? 'Famille');
            $parent2Name = trim($body['parent2FirstName'] ?? '');

            if ($siblingId) {
                // Fratrie : on récupère les parents du frère/de la sœur
                $stmt = $pdo->prepare('SELECT parent_id, parent2_id FROM children WHERE id = ?');
                $stmt->execute([$siblingId]);
                $sibling = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($sibling) {
                    $parentId = $sibling['parent_id'];
                    $parent2Id = $sibling['parent2_id'];
                }
            }

            if (!$parentId && !empty($parent1Email)) {
                $parentId = $this->ensureParentAccount($pdo, $parent1Email, $parent1Name, $lastName, $appUrl);
            }
            if (!$parent2Id && !empty($parent2Email)) {
                $parent2Id = $this->ensureParentAccount($pdo, $parent2Email, $parent2Name, $lastName, $appUrl);
            }

            if (!$parentId && !$parent2Id) {
                $pdo->rollBack();
                json_response(['error' => 'Veuillez fournir au moins un email ou lier à une fratrie'], 400);
                return;
            }
            
            // Si $parentId est null mais $parent2Id est rempli, on échange
            if (!$parentId) {
                $parentId = $parent2Id;
                $parent2Id = null;
            }

            // Créer l'enfant
            $childId = generate_uuid();
            $now = date('Y-m-d H:i:s');

            $stmt = $pdo->prepare('INSERT INTO children (id, first_name, last_name, parent_id, parent2_id, is_active, age_group, created_at, parent1_first_name, parent2_first_name, parent1_email, parent2_email) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$childId, $firstName, $lastName, $parentId, $parent2Id, $ageGroup, $now, $parent1Name, $parent2Name, $parent1Email, empty($parent2Email) ? null : $parent2Email]);

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

    private function update(string $childId): void {
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
        $child = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$child) {
            json_response(['error' => 'Enfant introuvable'], 404);
            return;
        }

        $pdo->beginTransaction();
        try {
            $firstName = trim($body['firstName'] ?? $child['first_name']);
            $lastName  = trim($body['lastName'] ?? $child['last_name']);
            $ageGroup  = $body['ageGroup'] ?? $child['age_group'];
            $isActive  = isset($body['isActive']) ? (int) $body['isActive'] : $child['is_active'];

            $stmt = $pdo->prepare('UPDATE children SET first_name = ?, last_name = ?, age_group = ?, is_active = ? WHERE id = ?');
            $stmt->execute([$firstName, $lastName, $ageGroup, $isActive, $childId]);
            
            // Mettre à jour les noms et emails des parents si fournis
            if (isset($body['parent1FirstName']) || isset($body['parent2FirstName']) || isset($body['parent1Email']) || isset($body['parent2Email'])) {
                $p1 = trim($body['parent1FirstName'] ?? $child['parent1_first_name'] ?? '');
                $p2 = trim($body['parent2FirstName'] ?? $child['parent2_first_name'] ?? '');
                $e1 = trim($body['parent1Email'] ?? $child['parent1_email'] ?? '');
                $e2 = trim($body['parent2Email'] ?? $child['parent2_email'] ?? '');
                
                $stmt = $pdo->prepare('UPDATE children SET parent1_first_name = ?, parent2_first_name = ?, parent1_email = ?, parent2_email = ? WHERE id = ?');
                $stmt->execute([$p1, $p2, $e1, $e2, $childId]);

                $appUrl = rtrim($body['appUrl'] ?? 'http://localhost:5173/planning', '/');

                if (!empty($e1)) {
                    if (!$child['parent_id']) {
                        $newId = $this->ensureParentAccount($pdo, $e1, $p1, $lastName, $appUrl);
                        $pdo->prepare('UPDATE children SET parent_id = ? WHERE id = ?')->execute([$newId, $childId]);
                        $child['parent_id'] = $newId;
                    } elseif ($e1 !== $child['parent1_email']) {
                        $pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$e1, $child['parent_id']]);
                    }
                }
                if (!empty($e2)) {
                    if (!$child['parent2_id']) {
                        $newId2 = $this->ensureParentAccount($pdo, $e2, $p2, $lastName, $appUrl);
                        $pdo->prepare('UPDATE children SET parent2_id = ? WHERE id = ?')->execute([$newId2, $childId]);
                        $child['parent2_id'] = $newId2;
                    } elseif ($e2 !== $child['parent2_email']) {
                        $pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$e2, $child['parent2_id']]);
                    }
                } elseif (isset($body['parent2Email']) && empty(trim($body['parent2Email'])) && $child['parent2_id']) {
                    $pidToClean = $child['parent2_id'];
                    $pdo->prepare('UPDATE children SET parent2_id = NULL WHERE id = ?')->execute([$childId]);
                    $this->cleanupParentsIfNoChildren($pdo, null, $pidToClean);
                }
            }

            if ($child['is_active'] && !$isActive) {
                $this->cleanupParentsIfNoChildren($pdo, $child['parent_id'], $child['parent2_id'] ?? null);
            } elseif (!$child['is_active'] && $isActive) {
                // Réactiver les parents
                if ($child['parent_id']) $pdo->prepare('UPDATE users SET is_active = 1 WHERE id = ?')->execute([$child['parent_id']]);
                if ($child['parent2_id']) $pdo->prepare('UPDATE users SET is_active = 1 WHERE id = ?')->execute([$child['parent2_id']]);
            }

            // Mettre à jour les présences par défaut
            $createdPresences = [];
            if (isset($body['defaultPresences'])) {
                $pdo->prepare('DELETE FROM child_default_presences WHERE child_id = ?')->execute([$childId]);
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
                $stmt = $pdo->prepare('SELECT * FROM child_default_presences WHERE child_id = ?');
                $stmt->execute([$childId]);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
                    $createdPresences[] = [
                        'id'        => $p['id'],
                        'childId'   => $p['child_id'],
                        'dayOfWeek' => $p['day_of_week'],
                        'halfDay'   => $p['half_day'],
                    ];
                }
            }

            $stmt = $pdo->prepare('SELECT id, first_name, last_name FROM users WHERE id = ?');
            $stmt->execute([$child['parent_id']]);
            $parent = $stmt->fetch(PDO::FETCH_ASSOC);

            $pdo->commit();

            json_response([
                'id'        => $childId,
                'firstName' => $firstName,
                'lastName'  => $lastName,
                'ageGroup'  => $ageGroup,
                'parentId'  => $child['parent_id'],
                'isActive'  => (bool) $isActive,
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

    private function delete(string $childId): void {
        $user = require_auth();
        verify_csrf();
        require_role($user, 'ADMIN');

        if (!validate_uuid($childId)) {
            json_response(['error' => 'ID invalide'], 400);
            return;
        }

        $pdo = get_db();

        $stmt = $pdo->prepare('SELECT id, parent_id FROM children WHERE id = ?');
        $stmt->execute([$childId]);
        $child = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$child) {
            json_response(['error' => 'Enfant introuvable'], 404);
            return;
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM children WHERE id = ?')->execute([$childId]);
            $this->cleanupParentsIfNoChildren($pdo, $child['parent_id'], $child['parent2_id'] ?? null);
            $pdo->commit();
            json_response(['message' => 'Enfant supprimé avec succès']);
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function listAbsences(string $childId): void {
        $user = require_auth();
        $pdo = get_db();

        $stmt = $pdo->prepare('
            SELECT id, start_date, start_half_day, end_date, end_half_day, is_conge, created_at
            FROM child_absences
            WHERE child_id = ?
            ORDER BY start_date DESC
        ');
        $stmt->execute([$childId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $absences = [];
        foreach ($rows as $r) {
            $absences[] = [
                'id' => $r['id'],
                'startDate' => $r['start_date'],
                'startHalfDay' => $r['start_half_day'],
                'endDate' => $r['end_date'],
                'endHalfDay' => $r['end_half_day'],
                'isConge' => (bool)$r['is_conge'],
                'createdAt' => $r['created_at']
            ];
        }

        json_response($absences);
    }

    private function createAbsence(string $childId): void {
        $user = require_auth();
        verify_csrf();
        require_role($user, 'ADMIN');

        $body = get_json_body();
        $startDate = $body['startDate'] ?? date('Y-m-d');
        $startHalfDay = $body['startHalfDay'] ?? 'ALL';
        $endDate = $body['endDate'] ?? null;
        $endHalfDay = $body['endHalfDay'] ?? 'ALL';
        $isConge = isset($body['isConge']) ? (int)(bool)$body['isConge'] : 0;

        $pdo = get_db();
        $pdo->beginTransaction();
        try {
            $id = generate_uuid();
            $stmt = $pdo->prepare('INSERT INTO child_absences (id, child_id, start_date, start_half_day, end_date, end_half_day, is_conge) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$id, $childId, $startDate, $startHalfDay, $endDate, $endHalfDay, $isConge]);

            sync_child_absences_retroactive();

            $pdo->commit();
            json_response(['message' => 'Absence enregistrée avec succès']);
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function updateAbsence(string $childId, string $absenceId): void {
        $user = require_auth();
        verify_csrf();
        require_role($user, 'ADMIN');

        $body = get_json_body();
        $startDate = $body['startDate'] ?? date('Y-m-d');
        $startHalfDay = $body['startHalfDay'] ?? 'ALL';
        $endDate = $body['endDate'] ?? null;
        $endHalfDay = $body['endHalfDay'] ?? 'ALL';
        $isConge = isset($body['isConge']) ? (int)(bool)$body['isConge'] : 0;

        $pdo = get_db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('UPDATE child_absences SET start_date = ?, start_half_day = ?, end_date = ?, end_half_day = ?, is_conge = ? WHERE id = ? AND child_id = ?');
            $stmt->execute([$startDate, $startHalfDay, $endDate, $endHalfDay, $isConge, $absenceId, $childId]);

            if ($stmt->rowCount() === 0) {
                $pdo->rollBack();
                json_response(['error' => 'Absence introuvable ou aucune modification'], 404);
                return;
            }

            sync_child_absences_retroactive();

            $pdo->commit();
            json_response(['message' => 'Absence modifiée avec succès']);
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function deleteAbsence(string $childId, string $absenceId): void {
        $user = require_auth();
        verify_csrf();
        require_role($user, 'ADMIN');

        $pdo = get_db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('DELETE FROM child_absences WHERE id = ? AND child_id = ?');
            $stmt->execute([$absenceId, $childId]);

            if ($stmt->rowCount() === 0) {
                $pdo->rollBack();
                json_response(['error' => 'Absence introuvable'], 404);
                return;
            }

            sync_child_absences_retroactive();

            $pdo->commit();
            json_response(['message' => 'Absence supprimée avec succès']);
        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private function history(string $childId): void {
        $user = require_auth();
        $pdo = get_db();

        $stmt = $pdo->prepare('
            SELECT week_number, year, permanences_done, permanences_due, score_after, snapshot_at
            FROM score_histories
            WHERE child_id = ?
            ORDER BY year DESC, week_number DESC
        ');
        $stmt->execute([$childId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $history = [];
        foreach ($rows as $r) {
            $history[] = [
                'weekNumber' => (int)$r['week_number'],
                'year' => (int)$r['year'],
                'permanencesDone' => (float)$r['permanences_done'],
                'permanencesDue' => (float)$r['permanences_due'],
                'scoreAfter' => (float)$r['score_after'],
                'snapshotAt' => $r['snapshot_at']
            ];
        }

        json_response($history);
    }

    private function cleanupParentsIfNoChildren(PDO $pdo, ?string $parentId, ?string $parent2Id): void {
        foreach ([$parentId, $parent2Id] as $pid) {
            if (!$pid) continue;
            // Check if there are active children for this parent
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM children WHERE (parent_id = ? OR parent2_id = ?) AND is_active = 1');
            $stmt->execute([$pid, $pid]);
            $count = (int)$stmt->fetchColumn();
            if ($count === 0) {
                // No active children, we can deactivate the user
                // The user requested: "supprimé", but we usually do soft-delete `is_active = 0` to preserve foreign keys.
                $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$pid]);
                
                // If they have NO children at all (even inactive), we could hard delete them, but foreign keys to score histories or availabilities might fail. Soft delete is safer.
                $stmtTotal = $pdo->prepare('SELECT COUNT(*) FROM children WHERE (parent_id = ? OR parent2_id = ?)');
                $stmtTotal->execute([$pid, $pid]);
                if ((int)$stmtTotal->fetchColumn() === 0) {
                    try {
                        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$pid]);
                    } catch (Exception $e) {
                        // Keep soft deleted if foreign key constraint fails
                    }
                }
            }
        }
    }

    private function ensureParentAccount(PDO $pdo, string $email, string $firstName, string $lastName, string $appUrl): string {
        $stmt = $pdo->prepare('SELECT id, is_active FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user) {
            if (!$user['is_active']) {
                $pdo->prepare('UPDATE users SET is_active = 1 WHERE id = ?')->execute([$user['id']]);
            }
            return $user['id'];
        }

        $userId = generate_uuid();
        $dummyPassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO users (id, email, password_hash, first_name, last_name, role, is_active) VALUES (?, ?, ?, ?, ?, "PARENT", 1)');
        $stmt->execute([$userId, $email, $dummyPassword, $firstName, $lastName]);

        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 86400);
        $pdo->prepare('INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)')->execute([$email, $token, $expiresAt]);

        require_once __DIR__ . '/../services/EmailService.php';
        $emailHtml = render_welcome_email($appUrl, $token);
        send_email($email, 'Bienvenue sur Crèche Planning', $emailHtml);

        return $userId;
    }
}
