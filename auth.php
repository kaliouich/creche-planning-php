<?php
/**
 * Authentification JWT en PHP pur.
 * Encode/Decode JWT avec HMAC-SHA256, gestion des cookies de session.
 */
require_once __DIR__ . '/config.php';

// ─── JWT Encode/Decode ──────────────────────────────────────

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/'));
}

/**
 * Crée un JWT signé avec HMAC-SHA256.
 */
function jwt_encode(array $payload): string {
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    
    $payload['iat'] = time();
    $payload['exp'] = time() + JWT_EXPIRATION;
    
    $headerEncoded = base64url_encode(json_encode($header));
    $payloadEncoded = base64url_encode(json_encode($payload));
    
    $signature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", JWT_SECRET, true);
    $signatureEncoded = base64url_encode($signature);
    
    return "$headerEncoded.$payloadEncoded.$signatureEncoded";
}

/**
 * Vérifie et décode un JWT.
 * @return array|null Le payload décodé, ou null si invalide/expiré.
 */
function jwt_decode(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    
    [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;
    
    // Vérification de la signature
    $expectedSignature = base64url_encode(
        hash_hmac('sha256', "$headerEncoded.$payloadEncoded", JWT_SECRET, true)
    );
    
    if (!hash_equals($expectedSignature, $signatureEncoded)) {
        return null; // Signature invalide
    }
    
    $payload = json_decode(base64url_decode($payloadEncoded), true);
    if (!$payload) return null;
    
    // Vérification de l'expiration
    if (isset($payload['exp']) && $payload['exp'] < time()) {
        return null; // Token expiré
    }
    
    return $payload;
}

// ─── Session / Cookies ──────────────────────────────────────

/**
 * Récupère l'utilisateur authentifié depuis le cookie de session.
 * @return array|null Le payload JWT ou null.
 */
function get_authenticated_user(): ?array {
    $cookieName = IS_PRODUCTION ? '__Host-session' : 'session';
    $token = $_COOKIE[$cookieName] ?? null;
    
    if (!$token) return null;
    
    return jwt_decode($token);
}

/**
 * Middleware d'authentification. Arrête l'exécution si non authentifié.
 */
function require_auth(): array {
    $user = get_authenticated_user();
    if (!$user) {
        json_response(['error' => 'Non authentifié'], 401);
        exit;
    }
    
    // Vérifier que l'utilisateur existe et est actif
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT is_active FROM users WHERE id = ?');
    $stmt->execute([$user['userId']]);
    $dbUser = $stmt->fetch();
    
    if (!$dbUser) {
        json_response(['error' => 'Utilisateur introuvable'], 401);
        exit;
    }
    
    if (!$dbUser['is_active']) {
        json_response(['error' => 'Compte désactivé'], 403);
        exit;
    }
    
    return $user;
}

/**
 * Vérifie que l'utilisateur a le rôle requis.
 */
function require_role(array $user, $role): void {
    if (is_array($role)) {
        if (!in_array($user['role'], $role)) {
            json_response(['error' => 'Accès interdit'], 403);
            exit;
        }
    } else {
        if ($user['role'] !== $role) {
            json_response(['error' => 'Accès interdit'], 403);
            exit;
        }
    }
}

/**
 * Vérifie le token CSRF pour les requêtes mutantes (POST, PUT, PATCH, DELETE).
 */
function verify_csrf(): void {
    $method = $_SERVER['REQUEST_METHOD'];
    if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'])) return;
    
    $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $csrfCookieName = IS_PRODUCTION ? '__Host-csrf-token' : 'csrf-token';
    $csrfCookie = $_COOKIE[$csrfCookieName] ?? '';
    
    if (empty($csrfHeader) || empty($csrfCookie) || !hash_equals($csrfCookie, $csrfHeader)) {
        json_response(['error' => 'Token CSRF invalide'], 403);
        exit;
    }
}

/**
 * Définit les cookies de session (JWT + CSRF).
 */
function set_session_cookies(string $jwt, string $csrfToken): void {
    $jwtName = IS_PRODUCTION ? '__Host-session' : 'session';
    $csrfName = IS_PRODUCTION ? '__Host-csrf-token' : 'csrf-token';
    $maxAge = JWT_EXPIRATION;
    $secure = IS_PRODUCTION;
    
    setcookie($jwtName, $jwt, [
        'expires'  => time() + $maxAge,
        'path'     => '/',
        'secure'   => $secure,
        'httponly'  => true,
        'samesite' => 'Lax',
    ]);
    
    setcookie($csrfName, $csrfToken, [
        'expires'  => time() + $maxAge,
        'path'     => '/',
        'secure'   => $secure,
        'httponly'  => false,
        'samesite' => 'Lax',
    ]);
}

/**
 * Supprime les cookies de session.
 */
function clear_session_cookies(): void {
    $jwtName = IS_PRODUCTION ? '__Host-session' : 'session';
    $csrfName = IS_PRODUCTION ? '__Host-csrf-token' : 'csrf-token';
    
    setcookie($jwtName, '', ['expires' => time() - 3600, 'path' => '/']);
    setcookie($csrfName, '', ['expires' => time() - 3600, 'path' => '/']);
}
