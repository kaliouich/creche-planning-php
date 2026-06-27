<?php
/**
 * Point d'entrée / Routeur principal de l'API Crèche Planning.
 * 
 * Toutes les requêtes sont redirigées ici par le .htaccess.
 * Ce fichier parse l'URL, gère les headers CORS, et dispatche
 * vers le bon fichier de route.
 */

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/helpers.php';

// ─── Security Headers ────────────────────────────────────────
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
if (IS_PRODUCTION) {
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'");
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// ─── CORS Headers ────────────────────────────────────────────
$allowedOrigins = array_map('trim', explode(',', CORS_ORIGINS));
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowedOrigins)) {
    header("Access-Control-Allow-Origin: $origin");
} elseif (!IS_PRODUCTION && !empty($origin)) {
    // En dev, n'autoriser que les origines localhost connues
    $devOrigins = ['http://localhost:5173', 'http://127.0.0.1:5173', 'http://localhost:3000'];
    if (in_array($origin, $devOrigins)) {
        header("Access-Control-Allow-Origin: $origin");
    }
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
    $router = new Router();

    // Mapping des routes dynamiques vers les contrôleurs existants
    $controllers = [
        'auth' => 'AuthController',
        'weeks' => 'WeekController',
        'children' => 'ChildController',
        'slots' => 'SlotController',
        'availabilities' => 'AvailabilityController',
        'planning' => 'PlanningController',
        'users' => 'UserController',
        'score-adjustments' => 'ScoreAdjustmentController',
        'exchange' => 'ExchangeController',
        'profile' => 'ProfileController'
    ];

    foreach ($controllers as $res => $controllerClass) {
        $callback = function(...$args) use ($controllerClass, $method) {
            require_once __DIR__ . '/controllers/' . $controllerClass . '.php';
            $c = new $controllerClass();
            $subRoute = implode('/', $args);
            $c->handle($subRoute, $method);
        };
        // Enregistrer la route principale et les sous-routes
        $router->get("/$res", $callback);
        $router->post("/$res", $callback);
        $router->put("/$res", $callback);
        $router->delete("/$res", $callback);
        $router->patch("/$res", $callback);
        
        $router->get("/$res/{id}", $callback);
        $router->post("/$res/{id}", $callback);
        $router->put("/$res/{id}", $callback);
        $router->delete("/$res/{id}", $callback);
        $router->patch("/$res/{id}", $callback);
        
        $router->get("/$res/{id}/{sub}", $callback);
        $router->post("/$res/{id}/{sub}", $callback);
        $router->put("/$res/{id}/{sub}", $callback);
        $router->delete("/$res/{id}/{sub}", $callback);
        $router->patch("/$res/{id}/{sub}", $callback);

        $router->get("/$res/{id}/{sub}/{action}", $callback);
        $router->post("/$res/{id}/{sub}/{action}", $callback);
        $router->put("/$res/{id}/{sub}/{action}", $callback);
        $router->delete("/$res/{id}/{sub}/{action}", $callback);
        $router->patch("/$res/{id}/{sub}/{action}", $callback);
    }

    $router->post('/repair-scores', function() {
        require __DIR__ . '/repair_scores.php';
    });

    $router->run($method, '/' . $route);

} catch (PDOException $e) {
    Logger::error('Database error: ' . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
    $msg = IS_PRODUCTION ? 'Erreur base de données' : 'Erreur base de données: ' . $e->getMessage();
    json_response(['error' => $msg], 500);
} catch (Throwable $e) {
    Logger::error('Server error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), ['trace' => $e->getTraceAsString()]);
    $msg = IS_PRODUCTION ? 'Erreur serveur interne' : 'Erreur serveur: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
    json_response(['error' => $msg], 500);
}
