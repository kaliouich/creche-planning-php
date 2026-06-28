<?php

require_once __DIR__ . '/../services/UserService.php';

class AuthController {
    private UserService $userService;

    public function __construct() {
        $this->userService = new UserService();
    }

    public function login(): void {
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

        $result = $this->userService->login($email, $password);
        if (isset($result['error'])) {
            json_response(['error' => $result['error']], 401);
            return;
        }
        $user = $result['user'];

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

    public function logout(): void {
        $user = require_auth();
        clear_session_cookies();
        json_response(['message' => 'Déconnexion réussie']);
    }

    public function forgotPassword(): void {
        $body = get_json_body();
        $email = trim($body['email'] ?? '');
        $appUrl = rtrim($body['appUrl'] ?? 'http://localhost:5173', '/');

        if (!validate_email($email)) {
            // Pour des raisons de sécurité, on ne dit pas si l'email existe ou non
            json_response(['message' => 'Si cette adresse existe, un email a été envoyé.']);
            return;
        }

        $token = $this->userService->createPasswordResetToken($email);
        if ($token) {
            require_once __DIR__ . '/../services/EmailService.php';
            $emailHtml = render_reset_password_email($appUrl, $token);
            send_email($email, 'Réinitialisation de votre mot de passe', $emailHtml);
        }

        json_response(['message' => 'Si cette adresse existe, un email a été envoyé.']);
    }

    public function resetPassword(): void {
        $body = get_json_body();
        $token = trim($body['token'] ?? '');
        $password = $body['password'] ?? '';

        if (empty($token) || strlen($password) < 8) {
            json_response(['error' => 'Données invalides ou mot de passe trop court.'], 400);
            return;
        }

        $result = $this->userService->resetPassword($token, $password);
        if (isset($result['error'])) {
            json_response(['error' => $result['error']], isset($result['code']) ? $result['code'] : 400);
            return;
        }

        json_response(['message' => 'Mot de passe mis à jour avec succès.']);
    }
}
