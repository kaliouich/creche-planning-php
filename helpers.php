<?php
/**
 * Fonctions utilitaires partagées.
 */

/**
 * Envoie une réponse JSON avec le code HTTP spécifié.
 */
function json_response($data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
}

/**
 * Lit et décode le corps JSON de la requête.
 */
function get_json_body(): array {
    $raw = file_get_contents('php://input');
    if (empty($raw)) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Valide qu'une chaîne est un UUID v4 valide.
 */
function validate_uuid(string $str): bool {
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $str);
}

/**
 * Génère un UUID v4.
 */
function generate_uuid(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // Version 4
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // Variant RFC 4122
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Valide un email.
 */
function validate_email(string $str): bool {
    return filter_var($str, FILTER_VALIDATE_EMAIL) !== false && strlen($str) <= 255;
}

/**
 * Valide une chaîne non vide avec longueur max.
 */
function validate_string(string $str, int $min = 1, int $max = 255): bool {
    $len = mb_strlen(trim($str));
    return $len >= $min && $len <= $max;
}

/**
 * Récupère un segment de l'URL de la route.
 * Ex: pour la route "weeks/abc-123/status", get_route_param(1) retourne "abc-123".
 */
function get_route_param(string $route, int $index): ?string {
    $parts = explode('/', trim($route, '/'));
    return $parts[$index] ?? null;
}

/**
 * Vérifie qu'une semaine est dans un statut autorisé.
 */
function require_week_status(string $weekId, array $allowedStatuses): ?array {
    $pdo = get_db();
    $stmt = $pdo->prepare('SELECT * FROM planning_weeks WHERE id = ?');
    $stmt->execute([$weekId]);
    $week = $stmt->fetch();

    if (!$week) {
        json_response(['error' => 'Semaine introuvable'], 404);
        exit;
    }

    if (!in_array($week['status'], $allowedStatuses)) {
        json_response([
            'error' => 'Action non autorisée pour ce statut',
            'current' => $week['status'],
            'allowed' => $allowedStatuses
        ], 400);
        exit;
    }

    return $week;
}
