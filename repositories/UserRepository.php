<?php

class UserRepository {
    private PDO $pdo;

    public function __construct() {
        $this->pdo = get_db();
    }

    public function findById(string $id): ?array {
        $stmt = $this->pdo->prepare("SELECT id, email, first_name as firstName, last_name as lastName, role, is_active FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findByEmail(string $email): ?array {
        $stmt = $this->pdo->prepare("SELECT id, email, password_hash, first_name, last_name, role, is_active FROM users WHERE email = ?");
        $stmt->execute([$email]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function findByEmailExcludeId(string $email, string $excludeId): ?array {
        $stmt = $this->pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $excludeId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function updateEmail(string $id, string $email): void {
        $stmt = $this->pdo->prepare("UPDATE users SET email = ? WHERE id = ?");
        $stmt->execute([$email, $id]);
    }

    public function updatePassword(string $id, string $passwordHash): void {
        $stmt = $this->pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
        $stmt->execute([$passwordHash, $id]);
    }

    public function updatePasswordByEmail(string $email, string $passwordHash): void {
        $stmt = $this->pdo->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
        $stmt->execute([$passwordHash, $email]);
    }

    public function createPasswordReset(string $email, string $token, string $expiresAt): void {
        $stmt = $this->pdo->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)");
        $stmt->execute([$email, $token, $expiresAt]);
    }

    public function findValidPasswordReset(string $token): ?array {
        $stmt = $this->pdo->prepare("SELECT email FROM password_resets WHERE token = ? AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1");
        $stmt->execute([$token]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function deletePasswordResetsByEmail(string $email): void {
        $stmt = $this->pdo->prepare("DELETE FROM password_resets WHERE email = ?");
        $stmt->execute([$email]);
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

    // For UserController
    public function findAll(): array {
        $stmt = $this->pdo->query("SELECT id, email, first_name, last_name, role, is_active FROM users ORDER BY last_name ASC, first_name ASC");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $data): void {
        $stmt = $this->pdo->prepare("INSERT INTO users (id, email, password_hash, first_name, last_name, role) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $data['id'],
            $data['email'],
            $data['password_hash'],
            $data['first_name'],
            $data['last_name'],
            $data['role']
        ]);
    }

    public function update(array $data): void {
        if (isset($data['password_hash'])) {
            $stmt = $this->pdo->prepare("UPDATE users SET email = ?, first_name = ?, last_name = ?, role = ?, password_hash = ?, is_active = ? WHERE id = ?");
            $stmt->execute([
                $data['email'],
                $data['first_name'],
                $data['last_name'],
                $data['role'],
                $data['password_hash'],
                $data['is_active'],
                $data['id']
            ]);
        } else {
            $stmt = $this->pdo->prepare("UPDATE users SET email = ?, first_name = ?, last_name = ?, role = ?, is_active = ? WHERE id = ?");
            $stmt->execute([
                $data['email'],
                $data['first_name'],
                $data['last_name'],
                $data['role'],
                $data['is_active'],
                $data['id']
            ]);
        }
    }

    public function delete(string $id): void {
        $stmt = $this->pdo->prepare("DELETE FROM users WHERE id = ?");
        $stmt->execute([$id]);
    }
}
