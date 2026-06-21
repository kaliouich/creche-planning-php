<?php
/**
 * Route profil utilisateur personnel.
 * PUT /profile — Modification de l'email et du mot de passe
 */

function handle_profile(string $route, string $method): void {
    if ($route === '' && $method === 'PUT') {
        profile_update();
    } else {
        json_response(['error' => 'Route non trouvée'], 404);
    }
}

function profile_update(): void {
    $user = require_auth();
    
    // Parents cannot update their profile
    if ($user['role'] === 'PARENT') {
        json_response(['error' => 'Accès non autorisé'], 403);
        return;
    }

    $input = get_json_input();
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';

    $pdo = get_db();
    
    if (!empty($email) && $email !== $user['email']) {
        // Verify email isn't used by someone else
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $user['id']]);
        if ($stmt->fetch()) {
            json_response(['error' => 'Cet email est déjà utilisé par un autre compte'], 409);
            return;
        }
        $pdo->prepare("UPDATE users SET email = ? WHERE id = ?")->execute([$email, $user['id']]);
    }

    if (!empty($password)) {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, $user['id']]);
    }

    json_response(['success' => true]);
}
