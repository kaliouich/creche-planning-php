<?php
require_once __DIR__ . '/../repositories/UserRepository.php';

class UserService {
    private UserRepository $repo;

    public function __construct() {
        $this->repo = new UserRepository();
    }

    public function getUserProfile(string $id): ?array {
        return $this->repo->findById($id);
    }

    public function updateProfile(string $userId, string $email, string $password): array {
        if (!empty($email)) {
            $existing = $this->repo->findByEmailExcludeId($email, $userId);
            if ($existing) {
                return ['error' => 'Cet email est déjà utilisé par un autre compte', 'code' => 409];
            }
            $this->repo->updateEmail($userId, $email);
        }

        if (!empty($password)) {
            if (strlen($password) < 8) {
                return ['error' => 'Le mot de passe doit contenir au moins 8 caractères', 'code' => 400];
            }
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $this->repo->updatePassword($userId, $hash);
        }

        return ['success' => true];
    }

    public function getAllUsers(): array {
        return $this->repo->findAll();
    }

    public function createUser(string $email, string $password, string $firstName, string $lastName, string $role): void {
        $data = [
            'id' => generate_uuid(),
            'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'role' => $role
        ];
        $this->repo->create($data);
    }

    public function updateUser(string $id, string $email, ?string $password, string $firstName, string $lastName, string $role, bool $isActive): void {
        $data = [
            'id' => $id,
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'role' => $role,
            'is_active' => $isActive ? 1 : 0
        ];
        if (!empty($password)) {
            $data['password_hash'] = password_hash($password, PASSWORD_BCRYPT);
        }
        $this->repo->update($data);
    }

    public function deleteUser(string $id): void {
        $this->repo->delete($id);
    }

    // Auth logic
    public function login(string $email, string $password): array {
        $user = $this->repo->findByEmail($email);
        if (!$user || !$user['is_active']) {
            password_verify($password, '$2y$10$dummyHashToPreventTimingAttacks000000000000000000000');
            return ['error' => 'Identifiants invalides'];
        }

        if (!password_verify($password, $user['password_hash'])) {
            return ['error' => 'Identifiants invalides'];
        }

        return ['user' => $user];
    }

    public function createPasswordResetToken(string $email): ?string {
        $user = $this->repo->findByEmail($email);
        if (!$user || !$user['is_active']) {
            return null; // Don't leak if email exists
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 86400); // 24h
        
        $this->repo->createPasswordReset($email, $token, $expiresAt);
        return $token;
    }

    public function resetPassword(string $token, string $password): array {
        if (strlen($password) < 8) {
            return ['error' => 'Le mot de passe doit contenir au moins 8 caractères.'];
        }

        $reset = $this->repo->findValidPasswordReset($token);
        if (!$reset) {
            return ['error' => 'Ce lien a expiré ou est invalide.'];
        }

        $email = $reset['email'];
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $this->repo->beginTransaction();
        try {
            $this->repo->updatePasswordByEmail($email, $hash);
            $this->repo->deletePasswordResetsByEmail($email);
            $this->repo->commit();
            return ['success' => true];
        } catch (Exception $e) {
            $this->repo->rollBack();
            return ['error' => 'Erreur lors de la mise à jour.'];
        }
    }
}
