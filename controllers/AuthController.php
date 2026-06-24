<?php

class AuthController {
    public function handle(string $route, string $method): void {
        if ($route === 'login' && $method === 'POST') {
            $this->login();
        } elseif ($route === 'logout' && $method === 'POST') {
            $this->logout();
        } elseif ($route === 'forgot-password' && $method === 'POST') {
            $this->forgotPassword();
        } elseif ($route === 'reset-password' && $method === 'POST') {
            $this->resetPassword();
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

        $pdo = get_db();
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

    private function forgotPassword(): void {
        $body = get_json_body();
        $email = trim($body['email'] ?? '');
        $appUrl = rtrim($body['appUrl'] ?? 'http://localhost:5173', '/');

        if (!validate_email($email)) {
            // Pour des raisons de sécurité, on ne dit pas si l'email existe ou non
            json_response(['message' => 'Si cette adresse existe, un email a été envoyé.']);
            return;
        }

        $pdo = get_db();
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ? AND is_active = 1');
        $stmt->execute([$email]);
        if (!$stmt->fetch()) {
            json_response(['message' => 'Si cette adresse existe, un email a été envoyé.']);
            return;
        }

        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 86400); // 24 heures

        $stmt = $pdo->prepare('INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)');
        $stmt->execute([$email, $token, $expiresAt]);

        require_once __DIR__ . '/../services/EmailService.php';
        $emailHtml = render_reset_password_email($appUrl, $token);
        send_email($email, 'Réinitialisation de votre mot de passe', $emailHtml);

        json_response(['message' => 'Si cette adresse existe, un email a été envoyé.']);
    }

    private function resetPassword(): void {
        $body = get_json_body();
        $token = trim($body['token'] ?? '');
        $password = $body['password'] ?? '';

        if (empty($token) || strlen($password) < 8) {
            json_response(['error' => 'Données invalides ou mot de passe trop court.'], 400);
            return;
        }

        $pdo = get_db();
        // Vérifier le token
        $stmt = $pdo->prepare('SELECT email FROM password_resets WHERE token = ? AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1');
        $stmt->execute([$token]);
        $reset = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$reset) {
            json_response(['error' => 'Ce lien a expiré ou est invalide.'], 400);
            return;
        }

        $email = $reset['email'];
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $pdo->beginTransaction();
        try {
            $updateStmt = $pdo->prepare('UPDATE users SET password_hash = ? WHERE email = ?');
            $updateStmt->execute([$hash, $email]);

            // Invalider tous les tokens pour cet email
            $delStmt = $pdo->prepare('DELETE FROM password_resets WHERE email = ?');
            $delStmt->execute([$email]);

            $pdo->commit();
            json_response(['message' => 'Mot de passe mis à jour avec succès.']);
        } catch (Exception $e) {
            $pdo->rollBack();
            json_response(['error' => 'Erreur lors de la mise à jour.'], 500);
        }
    }
}
