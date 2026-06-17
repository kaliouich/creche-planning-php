<?php
/**
 * Routes utilisateurs.
 * GET /users/parents — Liste des parents
 */

function handle_users(string $route, string $method): void {
    if ($route === 'parents' && $method === 'GET') {
        users_parents();
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
