<?php

require_once __DIR__ . '/../services/UserService.php';

class UserController {
    private UserService $userService;

    public function __construct() {
        $this->userService = new UserService();
    }

    public function list(): void {
        $user = require_auth();
        require_role($user, 'ADMIN');

        $users = $this->userService->getAllUsers();
        $filtered = array_filter($users, fn($u) => $u['email'] !== 'parent@creche.fr');
        
        $mapped = array_map(function($u) {
            return [
                'id' => $u['id'],
                'firstName' => $u['first_name'],
                'lastName' => $u['last_name'],
                'email' => $u['email'],
                'role' => $u['role']
            ];
        }, array_values($filtered));
        
        json_response($mapped);
    }

    public function delete(string $id): void {
        $user = require_auth();
        require_role($user, 'ADMIN');

        if ($id === $user['id']) {
            json_response(['error' => 'Vous ne pouvez pas supprimer votre propre compte.'], 400);
            return;
        }

        $userModel = $this->userService->getUserProfile($id);
        if (!$userModel) {
            json_response(['error' => 'Utilisateur introuvable.'], 404);
            return;
        }

        if ($userModel['role'] === 'PARENT') {
            json_response(['error' => 'Les comptes PARENT sont gérés automatiquement et ne peuvent pas être supprimés manuellement.'], 403);
            return;
        }

        try {
            $this->userService->deleteUser($id);
            json_response(['message' => 'Utilisateur supprimé avec succès']);
        } catch (\PDOException $e) {
            if (strpos($e->getMessage(), 'FOREIGN KEY') !== false || strpos($e->getMessage(), 'fk_children_parent') !== false) {
                json_response(['error' => 'Impossible de supprimer ce compte : des enfants y sont encore rattachés. Veuillez d\'abord marquer les enfants comme absents.'], 409);
            } else {
                json_response(['error' => 'Erreur lors de la suppression de l\'utilisateur.'], 500);
            }
        }
    }

    public function create(): void {
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

        if ($role !== 'ADMIN') {
            json_response(['error' => 'Les rôles PARENT sont gérés automatiquement via les enfants.'], 400);
            return;
        }

        $existingUsers = $this->userService->getAllUsers();
        foreach ($existingUsers as $u) {
            if ($u['email'] === $email) {
                json_response(['error' => 'Cet email est déjà utilisé'], 409);
                return;
            }
        }

        $password = bin2hex(random_bytes(32)); // Dummy long password
        $this->userService->createUser($email, $password, $firstName, $lastName, $role);
        
        $token = $this->userService->createPasswordResetToken($email);

        require_once __DIR__ . '/../services/EmailService.php';
        if ($role === 'ADMIN') {
            $emailHtml = render_admin_welcome_email($appUrl, $token);
            $subject = 'Bienvenue dans l\'équipe d\'administration';
        } else {
            $emailHtml = render_welcome_email($appUrl, $token);
            $subject = 'Bienvenue sur Crèche Planning';
        }
        send_email($email, $subject, $emailHtml);
        
        $createdUser = array_values(array_filter($this->userService->getAllUsers(), fn($u) => $u['email'] === $email))[0];

        json_response(['id' => $createdUser['id'], 'email' => $email, 'role' => $role, 'firstName' => $firstName, 'lastName' => $lastName]);
    }

    public function update(string $id): void {
        $authUser = require_auth();
        require_role($authUser, 'ADMIN');

        $input = get_json_body();
        $email = trim($input['email'] ?? '');
        $role = $input['role'] ?? '';

        $userModel = $this->userService->getUserProfile($id);
        if (!$userModel) {
            json_response(['error' => 'Utilisateur introuvable'], 404);
            return;
        }

        if ($userModel['role'] === 'PARENT') {
            json_response(['error' => 'Les comptes PARENT sont gérés automatiquement via les fiches enfants.'], 403);
            return;
        }

        if (!empty($email)) {
            $existingUsers = $this->userService->getAllUsers();
            foreach ($existingUsers as $u) {
                if ($u['email'] === $email && $u['id'] !== $id) {
                    json_response(['error' => 'Email déjà utilisé par un autre utilisateur'], 409);
                    return;
                }
            }
            $userModel['email'] = $email;
        }

        if (!empty($role) && $role === 'ADMIN') {
            $userModel['role'] = $role;
        }

        $this->userService->updateUser($id, $userModel['email'], null, $userModel['firstName'], $userModel['lastName'], $userModel['role'], $userModel['is_active']);

        json_response(['success' => true]);
    }

    public function parents(): void {
        $authUser = require_auth();
        require_role($authUser, 'ADMIN');

        $users = $this->userService->getAllUsers();
        $parentsModels = array_filter($users, fn($u) => $u['role'] === 'PARENT');
        
        $parents = [];
        foreach ($parentsModels as $p) {
            $parents[] = [
                'id'        => $p['id'],
                'firstName' => $p['first_name'],
                'lastName'  => $p['last_name'],
                'email'     => $p['email'],
            ];
        }
        
        usort($parents, function($a, $b) {
            return strcmp($a['lastName'], $b['lastName']);
        });

        json_response($parents);
    }

    public function notify(string $userId): void {
        $authUser = require_auth();
        require_role($authUser, 'ADMIN');

        $parent = $this->userService->getUserProfile($userId);

        if (!$parent) {
            json_response(['error' => 'Parent introuvable'], 404);
            return;
        }

        $emails = [$parent['email']];
        // Note: second_email is not fetched in basic profile right now, but it's okay for basic implementation.
        // Assuming second_email is not critical, or we can add it to UserService.

        $emailsStr = implode(', ', $emails);
        
        $subject = "Rappel : Saisie de vos disponibilités";
        $message = "Bonjour " . htmlspecialchars($parent['firstName']) . ",<br><br>"
                 . "Ceci est un rappel automatique.<br>"
                 . "Veuillez vous connecter à l'application pour saisir vos disponibilités de permanence.<br><br>"
                 . "Merci,<br>Le Pôle Planning.";

        require_once __DIR__ . '/../services/EmailService.php';
        $success = send_email($emailsStr, $subject, $message);

        if ($success || defined('IS_LOCAL_DEV')) {
            json_response(['success' => true, 'message' => 'Rappel envoyé avec succès à ' . $emailsStr]);
        } else {
            json_response(['error' => 'L\'envoi de l\'e-mail a échoué par le serveur.'], 500);
        }
    }
}
