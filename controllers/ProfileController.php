<?php

require_once __DIR__ . '/../services/UserService.php';

class ProfileController {
    private UserService $userService;

    public function __construct() {
        $this->userService = new UserService();
    }

    public function get(): void {
        $user = require_auth();
        $profile = $this->userService->getUserProfile($user['userId']);
        
        if (!$profile) {
            json_response(['error' => 'Utilisateur non trouvé'], 404);
            return;
        }
        
        json_response($profile);
    }

    public function update(): void {
        $user = require_auth();
        verify_csrf();

        $input = get_json_body();
        $email = trim($input['email'] ?? '');
        $password = $input['password'] ?? '';

        $result = $this->userService->updateProfile($user['userId'], $email, $password);
        if (isset($result['error'])) {
            json_response(['error' => $result['error']], $result['code'] ?? 400);
            return;
        }

        json_response(['success' => true]);
    }
}
