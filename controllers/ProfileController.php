<?php
require_once __DIR__ . '/../models/User.php';

class ProfileController {
    public function handle(string $route, string $method): void {
        if ($route === '' && $method === 'PUT') {
            $this->update();
        } else {
            json_response(['error' => 'Route non trouvée'], 404);
        }
    }

    private function update(): void {
        $user = require_auth();
        verify_csrf();
        
        if ($user['role'] === 'PARENT') {
            json_response(['error' => 'Accès non autorisé'], 403);
            return;
        }

        $input = get_json_body();
        $email = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';

        $pdo = get_db_connection();
        
        if (!empty($email) && $email !== $user['email']) {
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
            $stmt->execute([$email, $user['id']]);
            if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                json_response(['error' => 'Cet email est déjà utilisé par un autre compte'], 409);
                return;
            }
            $pdo->prepare("UPDATE users SET email = ? WHERE id = ?")->execute([$email, $user['id']]);
        }

        if (!empty($password)) {
            if (strlen($password) < 8) {
                json_response(['error' => 'Le mot de passe doit contenir au moins 8 caractères'], 400);
                return;
            }
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, $user['id']]);
        }

        json_response(['success' => true]);
    }
}
