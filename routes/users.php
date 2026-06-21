<?php
/**
 * Routes utilisateurs.
 * GET /users/parents — Liste des parents
 */

function handle_users(string $route, string $method): void {
    if ($route === '' && $method === 'GET') {
        users_list();
    } elseif ($route === '' && $method === 'POST') {
        users_create();
    } elseif ($route === 'parents' && $method === 'GET') {
        users_parents();
    } elseif (preg_match('#^([a-f0-9\-]+)$#', $route, $m) && $method === 'PUT') {
        users_update($m[1]);
    } elseif (preg_match('#^([a-f0-9\-]+)/notify$#', $route, $m) && $method === 'POST') {
        users_notify($m[1]);
    } else {
        json_response(['error' => 'Route non trouvée'], 404);
    }
}

function users_list(): void {
    $user = require_auth();
    require_role($user, 'ADMIN');

    $pdo = get_db();
    $stmt = $pdo->query("SELECT id, first_name, last_name, email, role FROM users ORDER BY created_at DESC");
    json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
}

function users_create(): void {
    $user = require_auth();
    require_role($user, 'ADMIN');

    $input = get_json_input();
    $email = trim($input['email'] ?? '');
    $role = $input['role'] ?? '';
    $firstName = trim($input['firstName'] ?? 'Nouveau');
    $lastName = trim($input['lastName'] ?? 'Utilisateur');
    $password = $input['password'] ?? '';

    if (empty($email) || empty($password) || empty($role)) {
        json_response(['error' => 'Email, mot de passe et rôle requis'], 400);
        return;
    }

    if (!in_array($role, ['ADMIN', 'PROFESSIONAL', 'PARENT'])) {
        json_response(['error' => 'Rôle invalide'], 400);
        return;
    }

    $pdo = get_db();
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        json_response(['error' => 'Cet email est déjà utilisé'], 409);
        return;
    }

    $id = generate_uuid();
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $now = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare("INSERT INTO users (id, email, password_hash, first_name, last_name, role, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$id, $email, $hash, $firstName, $lastName, $role, $now, $now]);

    // Send confirmation email
    $subject = "Votre compte Crèche Planning";
    $message = "Bonjour,\n\n"
             . "Votre compte a été créé avec succès sur l'application de la crèche.\n\n"
             . "Email de connexion : $email\n"
             . "Mot de passe provisoire : $password\n\n"
             . "Cordialement,\nLe Pôle Planning";

    $headers = [
        'From' => 'planning@lesfruitsdelapassion.fr',
        'Reply-To' => 'planning@lesfruitsdelapassion.fr',
        'Content-Type' => 'text/plain; charset=utf-8',
        'X-Mailer' => 'PHP/' . phpversion()
    ];

    @mail($email, $subject, $message, $headers);

    json_response(['id' => $id, 'email' => $email, 'role' => $role, 'firstName' => $firstName, 'lastName' => $lastName]);
}

function users_update(string $id): void {
    $user = require_auth();
    require_role($user, 'ADMIN');

    $input = get_json_input();
    $email = trim($input['email'] ?? '');
    $role = $input['role'] ?? '';
    $password = $input['password'] ?? '';

    $pdo = get_db();
    
    // Check if user exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE id = ?");
    $stmt->execute([$id]);
    if (!$stmt->fetch()) {
        json_response(['error' => 'Utilisateur introuvable'], 404);
        return;
    }

    if (!empty($email)) {
        // Verify email isn't used by someone else
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $stmt->execute([$email, $id]);
        if ($stmt->fetch()) {
            json_response(['error' => 'Email déjà utilisé par un autre utilisateur'], 409);
            return;
        }
        $pdo->prepare("UPDATE users SET email = ? WHERE id = ?")->execute([$email, $id]);
    }

    if (!empty($role) && in_array($role, ['ADMIN', 'PROFESSIONAL', 'PARENT'])) {
        $pdo->prepare("UPDATE users SET role = ? WHERE id = ?")->execute([$role, $id]);
    }

    if (!empty($password)) {
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")->execute([$hash, $id]);
    }

    json_response(['success' => true]);
}

function users_parents(): void {
    $user = require_auth();
    require_role($user, 'ADMIN');

    $pdo = get_db();
    $stmt = $pdo->query("SELECT id, first_name, last_name, email FROM users WHERE role = 'PARENT' ORDER BY last_name ASC");
    $parents = [];
    foreach ($stmt->fetchAll() as $p) {
        $parents[] = [
            'id'        => $p['id'],
            'firstName' => $p['first_name'],
            'lastName'  => $p['last_name'],
            'email'     => $p['email'],
        ];
    }
    json_response($parents);
}

function users_notify(string $userId): void {
    $user = require_auth();
    require_role($user, 'ADMIN');

    $pdo = get_db();
    $stmt = $pdo->prepare("SELECT id, first_name, last_name, email, second_email FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $parent = $stmt->fetch();

    if (!$parent) {
        json_response(['error' => 'Parent introuvable'], 404);
        return;
    }

    $emails = [$parent['email']];
    if (!empty($parent['second_email'])) {
        $emails[] = $parent['second_email'];
    }

    $emailsStr = implode(', ', $emails);
    
    $subject = "Rappel : Saisie de vos disponibilités";
    $message = "Bonjour " . $parent['first_name'] . ",\n\n"
             . "Ceci est un rappel automatique.\n"
             . "Veuillez vous connecter à l'application pour saisir vos disponibilités de permanence.\n\n"
             . "Merci,\nLe Pôle Planning.";

    // Pour OVH Shared Hosting, la fonction mail() fonctionne nativement.
    // L'adresse d'expédition (From) DOIT de préférence exister sur votre hébergement OVH.
    $headers = [
        'From' => 'planning@lesfruitsdelapassion.fr',
        'Reply-To' => 'planning@lesfruitsdelapassion.fr',
        'Content-Type' => 'text/plain; charset=utf-8',
        'X-Mailer' => 'PHP/' . phpversion()
    ];

    $success = @mail($emailsStr, $subject, $message, $headers);

    if ($success || defined('IS_LOCAL_DEV')) {
        // En local, mail() peut renvoyer false, on fait comme si ça marchait si on veut.
        // Mais par défaut, on informe du succès.
        json_response(['success' => true, 'message' => 'Rappel envoyé avec succès à ' . $emailsStr]);
    } else {
        json_response(['error' => 'L\'envoi de l\'e-mail a échoué par le serveur.'], 500);
    }
}
