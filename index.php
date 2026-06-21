<?php
/**
 * Point d'entrée / Routeur principal de l'API Crèche Planning.
 * 
 * Toutes les requêtes sont redirigées ici par le .htaccess.
 * Ce fichier parse l'URL, gère les headers CORS, et dispatche
 * vers le bon fichier de route.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

// ─── CORS Headers ────────────────────────────────────────────
$allowedOrigins = array_map('trim', explode(',', CORS_ORIGINS));
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: $origin");
} elseif (!IS_PRODUCTION && !empty($origin)) {
    // En dev, on est plus permissif
    header("Access-Control-Allow-Origin: $origin");
}

header('Access-Control-Allow-Credentials: true');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token');

// Répondre aux preflight OPTIONS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ─── Routage ─────────────────────────────────────────────────
// Extraire le chemin de la requête (relatif à l'API)
$requestUri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

// Nettoyer le préfixe /api si présent (dépend de la conf du serveur)
$route = preg_replace('#^/api/?#', '', $requestUri);

// On peut aussi enlever le chemin de base du script au cas où il soit dans un sous-dossier
$basePath = dirname($_SERVER['SCRIPT_NAME']);
if ($basePath !== '/' && $basePath !== '\\') {
    $route = preg_replace('#^' . preg_quote($basePath, '#') . '/?#', '', $route);
}

$route = trim($route, '/');
$method = $_SERVER['REQUEST_METHOD'];

// Déterminer le premier segment de la route
$segments = explode('/', $route);
$resource = $segments[0] ?? '';
$subRoute = implode('/', array_slice($segments, 1));

try {
    switch ($resource) {
        case 'auth':
            require __DIR__ . '/routes/auth.php';
            handle_auth($subRoute, $method);
            break;

        case 'weeks':
            require __DIR__ . '/routes/weeks.php';
            handle_weeks($subRoute, $method);
            break;

        case 'children':
            require __DIR__ . '/routes/children.php';
            handle_children($subRoute, $method);
            break;

        case 'slots':
            require __DIR__ . '/routes/slots.php';
            handle_slots($subRoute, $method);
            break;

        case 'availabilities':
            require __DIR__ . '/routes/availabilities.php';
            handle_availabilities($subRoute, $method);
            break;

        case 'planning':
            require __DIR__ . '/routes/planning.php';
            handle_planning($subRoute, $method);
            break;

        case 'users':
            require __DIR__ . '/routes/users.php';
            handle_users($subRoute, $method);
            break;

        case 'score-adjustments':
            require __DIR__ . '/routes/score_adjustments.php';
            handle_score_adjustments($subRoute, $method);
            break;

        case 'repair-scores':
            require __DIR__ . '/repair_scores.php';
            break;

        case 'profile':
            require __DIR__ . '/routes/profile.php';
            handle_profile($subRoute, $method);
            break;

        default:
            json_response(['error' => 'Route non trouvée', 'route' => $route], 404);
    }
} catch (PDOException $e) {
    error_log('Database error: ' . $e->getMessage());
    json_response(['error' => 'Erreur base de données: ' . $e->getMessage()], 500);
} catch (Throwable $e) {
    error_log('Server error: ' . $e->getMessage());
    json_response(['error' => 'Erreur serveur: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()], 500);
}
