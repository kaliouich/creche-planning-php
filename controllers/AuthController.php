<?php
require_once __DIR__ . '/../models/User.php';

class AuthController {
    public function handle(string $route, string $method): void {
        if ($route === 'login' && $method === 'POST') {
            $this->login();
        } elseif ($route === 'logout' && $method === 'POST') {
            $this->logout();
        } else {
            json_response(['error' => 'Route non trouvée'], 404);
        }
    }

    private function login(): void {
        $body = get_json_body();
        $email = trim($body['email'] ?? '');
        $password = $body['password'] ?? '';

        // Rate limiting by IP + email combo
        $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $rateLimitKey = $clientIp . '_' . md5($email);
        if (!check_rate_limit($rateLimitKey)) {
            json_response(['error' => 'Trop de tentatives. Réessayez dans 15 minutes.'], 429);
            return;
        }

        if (!validate_email($email) || strlen($password) < 8) {
            json_response(['error' => 'Données invalides'], 400);
            return;
        }

        $pdo = get_db_connection();
        $stmt = $pdo->prepare('SELECT id, email, password_hash, first_name, last_name, role, is_active FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !$user['is_active']) {
            // Temps constant pour éviter l'énumération des utilisateurs
            password_verify($password, '$2y$10$dummyHashToPreventTimingAttacks000000000000000000000');
            json_response(['error' => 'Identifiants invalides'], 401);
            return;
        }

        if (!password_verify($password, $user['password_hash'])) {
            json_response(['error' => 'Identifiants invalides'], 401);
            return;
        }

        // Création du JWT
        $payload = ['userId' => $user['id'], 'role' => $user['role']];
        $token = jwt_encode($payload);

        // Génération du token CSRF
        $csrfToken = bin2hex(random_bytes(32));

        // Envoi des cookies
        set_session_cookies($token, $csrfToken);

        json_response([
            'message' => 'Connexion réussie',
            'user' => [
                'id'        => $user['id'],
                'firstName' => $user['first_name'],
                'lastName'  => $user['last_name'],
                'role'      => $user['role'],
            ]
        ]);
    }

    private function logout(): void {
        $user = require_auth();
        clear_session_cookies();
        json_response(['message' => 'Déconnexion réussie']);
    }
}
