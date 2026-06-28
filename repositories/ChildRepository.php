<?php
require_once __DIR__ . '/../db.php';
require_once __DIR__ . '/../helpers.php';

class ChildRepository {
    private PDO $pdo;

    public function __construct() {
        $this->pdo = get_db();
    }

    public function findAllWithDetails(): array {
        $sql = '
            SELECT c.id, c.first_name, c.last_name, c.parent_id, c.parent2_id, c.is_active, c.age_group, c.created_at,
                   c.parent1_first_name, c.parent2_first_name, c.parent1_email, c.parent2_email
            FROM children c
            ORDER BY c.last_name ASC
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Récupérer les présences par défaut (1 query)
        $presStmt = $this->pdo->query('SELECT * FROM child_default_presences');
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

        // Batch score fetch: get latest score_after for each child in 1 query
        $scoreStmt = $this->pdo->query('
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
        $absStmt = $this->pdo->prepare('
            SELECT DISTINCT child_id 
            FROM child_absences 
            WHERE start_date <= ? AND (end_date IS NULL OR end_date >= ?)
        ');
        $absStmt->execute([$today, $today]);
        $currentlyAbsentChildren = $absStmt->fetchAll(PDO::FETCH_COLUMN);

        $children = [];
        foreach ($rows as $r) {
            $children[] = [
                'id'        => $r['id'],
                'firstName' => $r['first_name'],
                'lastName'  => $r['last_name'],
                'parentId'  => $r['parent_id'],
                'isActive'  => (bool) $r['is_active'],
                'ageGroup'  => $r['age_group'],
                'createdAt' => $r['created_at'],
                'score'     => $scoresByChild[$r['id']] ?? 0.0,
                'isCurrentlyAbsent' => in_array($r['id'], $currentlyAbsentChildren),
                'parent'    => [
                    'id'          => $r['parent_id'],
                    'secondId'    => $r['parent2_id'],
                    'firstName'   => $r['parent1_first_name'],
                    'lastName'    => $r['parent2_first_name'],
                    'email'       => $r['parent1_email'],
                    'secondEmail' => $r['parent2_email'],
                ],
                'defaultPresences' => $presencesByChild[$r['id']] ?? [],
            ];
        }

        return $children;
    }

    public function getParentsBySiblingId(string $siblingId): ?array {
        $stmt = $this->pdo->prepare('SELECT parent_id, parent2_id FROM children WHERE id = ?');
        $stmt->execute([$siblingId]);
        $sibling = $stmt->fetch(PDO::FETCH_ASSOC);
        return $sibling ?: null;
    }

    public function findById(string $id): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM children WHERE id = ?');
        $stmt->execute([$id]);
        $child = $stmt->fetch(PDO::FETCH_ASSOC);
        return $child ?: null;
    }

    public function createChild(array $data): string {
        $childId = generate_uuid();
        $now = date('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare('INSERT INTO children (id, first_name, last_name, parent_id, parent2_id, is_active, age_group, created_at, parent1_first_name, parent2_first_name, parent1_email, parent2_email) VALUES (?, ?, ?, ?, ?, 1, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $childId, 
            $data['firstName'], 
            $data['lastName'], 
            $data['parentId'], 
            $data['parent2Id'], 
            $data['ageGroup'], 
            $now, 
            $data['parent1Name'], 
            $data['parent2Name'], 
            $data['parent1Email'], 
            empty($data['parent2Email']) ? null : $data['parent2Email']
        ]);
        return $childId;
    }

    public function updateChild(string $id, array $data): void {
        $stmt = $this->pdo->prepare('UPDATE children SET first_name = ?, last_name = ?, age_group = ?, is_active = ?, parent1_first_name = ?, parent2_first_name = ?, parent1_email = ?, parent2_email = ? WHERE id = ?');
        $stmt->execute([
            $data['firstName'], 
            $data['lastName'], 
            $data['ageGroup'], 
            $data['isActive'],
            $data['parent1FirstName'],
            $data['parent2FirstName'],
            $data['parent1Email'],
            $data['parent2Email'],
            $id
        ]);
    }

    public function updateChildParent(string $childId, string $parentIdField, ?string $newParentId): void {
        $stmt = $this->pdo->prepare("UPDATE children SET $parentIdField = ? WHERE id = ?");
        $stmt->execute([$newParentId, $childId]);
    }

    public function deleteChild(string $id): void {
        $this->pdo->prepare('DELETE FROM children WHERE id = ?')->execute([$id]);
    }

    public function createDefaultPresences(string $childId, array $presences): array {
        $createdPresences = [];
        $presStmt = $this->pdo->prepare('INSERT INTO child_default_presences (id, child_id, day_of_week, half_day) VALUES (?, ?, ?, ?)');
        foreach ($presences as $dp) {
            $presId = generate_uuid();
            $presStmt->execute([$presId, $childId, $dp['dayOfWeek'], $dp['halfDay']]);
            $createdPresences[] = [
                'id'        => $presId,
                'childId'   => $childId,
                'dayOfWeek' => $dp['dayOfWeek'],
                'halfDay'   => $dp['halfDay'],
            ];
        }
        return $createdPresences;
    }

    public function deleteDefaultPresences(string $childId): void {
        $this->pdo->prepare('DELETE FROM child_default_presences WHERE child_id = ?')->execute([$childId]);
    }

    public function getDefaultPresences(string $childId): array {
        $stmt = $this->pdo->prepare('SELECT * FROM child_default_presences WHERE child_id = ?');
        $stmt->execute([$childId]);
        $presences = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $presences[] = [
                'id'        => $p['id'],
                'childId'   => $p['child_id'],
                'dayOfWeek' => $p['day_of_week'],
                'halfDay'   => $p['half_day'],
            ];
        }
        return $presences;
    }

    public function findUserById(string $id): ?array {
        $stmt = $this->pdo->prepare('SELECT id, first_name, last_name, email, second_email FROM users WHERE id = ?');
        $stmt->execute([$id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
    }

    public function findUserByEmail(string $email): ?array {
        $stmt = $this->pdo->prepare('SELECT id, is_active FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        return $user ?: null;
    }

    public function activateUser(string $id): void {
        $this->pdo->prepare('UPDATE users SET is_active = 1 WHERE id = ?')->execute([$id]);
    }

    public function deactivateUser(string $id): void {
        $this->pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$id]);
    }

    public function deleteUser(string $id): void {
        $this->pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }

    public function updateUserEmail(string $id, string $email): void {
        $this->pdo->prepare('UPDATE users SET email = ? WHERE id = ?')->execute([$email, $id]);
    }

    public function countActiveChildrenForParent(string $parentId): int {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM children WHERE (parent_id = ? OR parent2_id = ?) AND is_active = 1');
        $stmt->execute([$parentId, $parentId]);
        return (int)$stmt->fetchColumn();
    }

    public function countTotalChildrenForParent(string $parentId): int {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM children WHERE (parent_id = ? OR parent2_id = ?)');
        $stmt->execute([$parentId, $parentId]);
        return (int)$stmt->fetchColumn();
    }

    public function createUser(array $data): string {
        $userId = generate_uuid();
        $dummyPassword = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        $stmt = $this->pdo->prepare('INSERT INTO users (id, email, password_hash, first_name, last_name, role, is_active) VALUES (?, ?, ?, ?, ?, "PARENT", 1)');
        $stmt->execute([$userId, $data['email'], $dummyPassword, $data['firstName'], $data['lastName']]);
        return $userId;
    }

    public function createPasswordResetToken(string $email): string {
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 86400);
        $this->pdo->prepare('INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)')->execute([$email, $token, $expiresAt]);
        return $token;
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

    public function findAbsences(string $childId): array {
        $stmt = $this->pdo->prepare('
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
        return $absences;
    }

    public function createAbsence(string $childId, array $data): void {
        $id = generate_uuid();
        $stmt = $this->pdo->prepare('INSERT INTO child_absences (id, child_id, start_date, start_half_day, end_date, end_half_day, is_conge) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $id, 
            $childId, 
            $data['startDate'], 
            $data['startHalfDay'], 
            $data['endDate'], 
            $data['endHalfDay'], 
            $data['isConge']
        ]);
    }

    public function updateAbsence(string $childId, string $absenceId, array $data): int {
        $stmt = $this->pdo->prepare('UPDATE child_absences SET start_date = ?, start_half_day = ?, end_date = ?, end_half_day = ?, is_conge = ? WHERE id = ? AND child_id = ?');
        $stmt->execute([
            $data['startDate'], 
            $data['startHalfDay'], 
            $data['endDate'], 
            $data['endHalfDay'], 
            $data['isConge'], 
            $absenceId, 
            $childId
        ]);
        return $stmt->rowCount();
    }

    public function deleteAbsence(string $childId, string $absenceId): int {
        $stmt = $this->pdo->prepare('DELETE FROM child_absences WHERE id = ? AND child_id = ?');
        $stmt->execute([$absenceId, $childId]);
        return $stmt->rowCount();
    }

    public function findHistory(string $childId): array {
        $stmt = $this->pdo->prepare('
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
        return $history;
    }
}
