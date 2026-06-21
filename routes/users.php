<?php
/**
 * Routes utilisateurs.
 * GET /users/parents — Liste des parents
 */

function handle_users(string $route, string $method): void {
    if ($route === 'parents' && $method === 'GET') {
        users_parents();
    } elseif (preg_match('#^([a-f0-9\-]+)/notify$#', $route, $m) && $method === 'POST') {
        users_notify($m[1]);
    } else {
        json_response(['error' => 'Route non trouvée'], 404);
    }
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
