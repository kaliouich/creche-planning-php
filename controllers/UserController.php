<?php

class UserController {
    public function handle(string $route, string $method): void {
        if ($route === '' && $method === 'GET') {
            $this->list();
        } elseif ($route === '' && $method === 'POST') {
            $this->create();
        } elseif ($route === 'parents' && $method === 'GET') {
            $this->parents();
        } elseif (preg_match('#^([a-f0-9\-]+)$#', $route, $m) && $method === 'PUT') {
            $this->update($m[1]);
        } elseif (preg_match('#^([a-f0-9\-]+)/notify$#', $route, $m) && $method === 'POST') {
            $this->notify($m[1]);
        } elseif (preg_match('#^([a-f0-9\-]+)$#', $route, $m) && $method === 'DELETE') {
            $this->delete($m[1]);
        } else {
            json_response(['error' => 'Route non trouvée'], 404);
        }
    }

    private function list(): void {
        $user = require_auth();
        require_role($user, 'ADMIN');

        $pdo = get_db();
        $stmt = $pdo->query("SELECT id, first_name AS firstName, last_name AS lastName, email, role FROM users WHERE email != 'parent@creche.fr' ORDER BY created_at DESC");
        json_response($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function delete(string $id): void {
        $user = require_auth();
        require_role($user, 'ADMIN');

        if ($id === $user['id']) {
            json_response(['error' => 'Vous ne pouvez pas supprimer votre propre compte.'], 400);
            return;
        }

        $userModel = User::find($id);
        if (!$userModel) {
            json_response(['error' => 'Utilisateur introuvable.'], 404);
            return;
        }

        if ($userModel->role === 'PARENT') {
            json_response(['error' => 'Les comptes PARENT sont gérés automatiquement et ne peuvent pas être supprimés manuellement.'], 403);
            return;
        }

        try {
            $userModel->delete();
            json_response(['message' => 'Utilisateur supprimé avec succès']);
        } catch (\PDOException $e) {
            if (strpos($e->getMessage(), 'FOREIGN KEY') !== false || strpos($e->getMessage(), 'fk_children_parent') !== false) {
                json_response(['error' => 'Impossible de supprimer ce compte : des enfants y sont encore rattachés. Veuillez d\'abord marquer les enfants comme absents.'], 409);
            } else {
                json_response(['error' => 'Erreur lors de la suppression de l\'utilisateur.'], 500);
            }
        }
    }

    private function create(): void {
        $authUser = require_auth();
        require_role($authUser, 'ADMIN');

        $input = get_json_body();
        $email = trim($input['email'] ?? '');
        $role = $input['role'] ?? '';
        $firstName = trim($input['firstName'] ?? 'Nouveau');
        $lastName = trim($input['lastName'] ?? 'Utilisateur');
        $appUrl = rtrim($input['appUrl'] ?? 'http://localhost:5173', '/');

        if (empty($email) || empty($role)) {
            json_response(['error' => 'Email et rôle requis'], 400);
            return;
        }

        if (!in_array($role, ['ADMIN', 'PROFESSIONAL'])) {
            json_response(['error' => 'Les rôles PARENT sont gérés automatiquement via les enfants.'], 400);
            return;
        }

        $existingUsers = User::where('email', $email);
        if (count($existingUsers) > 0) {
            json_response(['error' => 'Cet email est déjà utilisé'], 409);
            return;
        }

        $id = generate_uuid();
        $hash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        $now = date('Y-m-d H:i:s');

        User::create([
            'id' => $id,
            'email' => $email,
            'password_hash' => $hash,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'role' => $role,
            'created_at' => $now,
            'updated_at' => $now
        ]);

        $pdo = get_db();
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + 86400);
        $pdo->prepare('INSERT INTO password_resets (email, token, expires_at) VALUES (?, ?, ?)')->execute([$email, $token, $expiresAt]);

        require_once __DIR__ . '/../services/EmailService.php';
        $emailHtml = render_welcome_email($appUrl, $token);
        send_email($email, 'Bienvenue sur Crèche Planning', $emailHtml);

        json_response(['id' => $id, 'email' => $email, 'role' => $role, 'firstName' => $firstName, 'lastName' => $lastName]);
    }

    private function update(string $id): void {
        $authUser = require_auth();
        require_role($authUser, 'ADMIN');

        $input = get_json_body();
        $email = trim($input['email'] ?? '');
        $role = $input['role'] ?? '';

        $userModel = User::find($id);
        if (!$userModel) {
            json_response(['error' => 'Utilisateur introuvable'], 404);
            return;
        }

        if ($userModel->role === 'PARENT') {
            json_response(['error' => 'Les comptes PARENT sont gérés automatiquement via les fiches enfants.'], 403);
            return;
        }

        if (!empty($email)) {
            $existing = User::where('email', $email);
            foreach ($existing as $ex) {
                if ($ex->id !== $id) {
                    json_response(['error' => 'Email déjà utilisé par un autre utilisateur'], 409);
                    return;
                }
            }
            $userModel->email = $email;
        }

        if (!empty($role) && in_array($role, ['ADMIN', 'PROFESSIONAL'])) {
            $userModel->role = $role;
        }

        $userModel->save();

        json_response(['success' => true]);
    }

    private function parents(): void {
        $authUser = require_auth();
        require_role($authUser, 'ADMIN');

        $parentsModels = User::where('role', 'PARENT');
        
        $parents = [];
        foreach ($parentsModels as $p) {
            $parents[] = [
                'id'        => $p->id,
                'firstName' => $p->first_name,
                'lastName'  => $p->last_name,
                'email'     => $p->email,
            ];
        }
        
        usort($parents, function($a, $b) {
            return strcmp($a['lastName'], $b['lastName']);
        });

        json_response($parents);
    }

    private function notify(string $userId): void {
        $authUser = require_auth();
        require_role($authUser, ['ADMIN', 'PROFESSIONAL']);

        $parent = User::find($userId);

        if (!$parent) {
            json_response(['error' => 'Parent introuvable'], 404);
            return;
        }

        $emails = [$parent->email];
        if (!empty($parent->second_email)) {
            $emails[] = $parent->second_email;
        }

        $emailsStr = implode(', ', $emails);
        
        $subject = "Rappel : Saisie de vos disponibilités";
        $message = "Bonjour " . htmlspecialchars($parent->first_name) . ",<br><br>"
                 . "Ceci est un rappel automatique.<br>"
                 . "Veuillez vous connecter à l'application pour saisir vos disponibilités de permanence.<br><br>"
                 . "Merci,<br>Le Pôle Planning.";

        $success = send_email($emailsStr, $subject, $message);

        if ($success || defined('IS_LOCAL_DEV')) {
            json_response(['success' => true, 'message' => 'Rappel envoyé avec succès à ' . $emailsStr]);
        } else {
            json_response(['error' => 'L\'envoi de l\'e-mail a échoué par le serveur.'], 500);
        }
    }
}
