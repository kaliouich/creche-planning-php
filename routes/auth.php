<?php
/**
 * Routes d'authentification.
 * POST /auth/login — Connexion
 * POST /auth/logout — Déconnexion
 */

function handle_auth(string $route, string $method): void {
    if ($route === 'login' && $method === 'POST') {
        auth_login();
    } elseif ($route === 'logout' && $method === 'POST') {
        auth_logout();
    } else {
        json_response(['error' => 'Route non trouvée'], 404);
    }
}

function auth_login(): void {
    $body = get_json_body();
    $email = trim($body['email'] ?? '');
    $password = $body['password'] ?? '';

    if (!validate_email($email) || strlen($password) < 8) {
        json_response(['error' => 'Données invalides'], 400);
        return;
    }

    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT id, email, password_hash, first_name, last_name, role, is_active FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

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

function auth_logout(): void {
    $user = require_auth();
    clear_session_cookies();
    json_response(['message' => 'Déconnexion réussie']);
}
