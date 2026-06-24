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
        $password = $input['password'] ?? '';

        if (empty($email) || empty($password) || empty($role)) {
            json_response(['error' => 'Email, mot de passe et rôle requis'], 400);
            return;
        }

        if (strlen($password) < 8) {
            json_response(['error' => 'Le mot de passe doit contenir au moins 8 caractères'], 400);
            return;
        }

        if (!in_array($role, ['ADMIN', 'PROFESSIONAL', 'PARENT'])) {
            json_response(['error' => 'Rôle invalide'], 400);
            return;
        }

        $existingUsers = User::where('email', $email);
        if (count($existingUsers) > 0) {
            json_response(['error' => 'Cet email est déjà utilisé'], 409);
            return;
        }

        $id = generate_uuid();
        $hash = password_hash($password, PASSWORD_BCRYPT);
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

        // Send confirmation email (NEVER send plaintext passwords)
        $subject = "Votre compte Crèche Planning";
        $message = "Bonjour $firstName,<br><br>"
                 . "Votre compte a été créé avec succès sur l'application de la crèche.<br><br>"
                 . "Email de connexion : $email<br>"
                 . "Votre mot de passe vous a été communiqué par l'administrateur.<br><br>"
                 . "Vous pouvez vous connecter ici : <a href=\"https://lesfruitsdelapassion.fr/planning/\">https://lesfruitsdelapassion.fr/planning/</a><br>"
                 . "Nous vous invitons ensuite à modifier votre mot de passe en cliquant sur \"Profil\" tout en haut de la page.<br><br>"
                 . "Cordialement,<br>Le Pôle Planning";

        send_email($email, $subject, $message);

        json_response(['id' => $id, 'email' => $email, 'role' => $role, 'firstName' => $firstName, 'lastName' => $lastName]);
    }

    private function update(string $id): void {
        $authUser = require_auth();
        require_role($authUser, 'ADMIN');

        $input = get_json_body();
        $email = trim($input['email'] ?? '');
        $role = $input['role'] ?? '';
        $password = $input['password'] ?? '';

        $userModel = User::find($id);
        if (!$userModel) {
            json_response(['error' => 'Utilisateur introuvable'], 404);
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

        if (!empty($role) && in_array($role, ['ADMIN', 'PROFESSIONAL', 'PARENT'])) {
            $userModel->role = $role;
        }

        if (!empty($password)) {
            if (strlen($password) < 8) {
                json_response(['error' => 'Le mot de passe doit contenir au moins 8 caractères'], 400);
                return;
            }
            $userModel->password_hash = password_hash($password, PASSWORD_BCRYPT);
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
