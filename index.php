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

// Strip /index.php/ prefix for PHP built-in server testing
$route = preg_replace('#^/?index\.php/?#', '', $route);

$route = trim($route, '/');
$method = $_SERVER['REQUEST_METHOD'];

// Déterminer le premier segment de la route
$segments = explode('/', $route);
$resource = $segments[0] ?? '';
$subRoute = implode('/', array_slice($segments, 1));

try {
    require_once __DIR__ . '/services/Router.php';
    $router = new Router();

    require_once __DIR__ . '/controllers/AuthController.php';
    $authController = new AuthController();
    $router->post('/auth/login', [$authController, 'login']);
    $router->post('/auth/logout', [$authController, 'logout']);
    $router->post('/auth/forgot-password', [$authController, 'forgotPassword']);
    $router->post('/auth/reset-password', [$authController, 'resetPassword']);

    require_once __DIR__ . '/controllers/WeekController.php';
    $weekController = new WeekController();
    $router->get('/weeks', [$weekController, 'list']);
    $router->post('/weeks', [$weekController, 'create']);
    $router->patch('/weeks/{id}/status', [$weekController, 'updateStatus']);
    $router->put('/weeks/{id}/assignments', [$weekController, 'updateAssignments']);
    $router->delete('/weeks/{id}', [$weekController, 'delete']);

    require_once __DIR__ . '/controllers/ChildController.php';
    $childController = new ChildController();
    $router->get('/children', [$childController, 'list']);
    $router->post('/children', [$childController, 'create']);
    $router->get('/children/{id}', [$childController, 'get']);
    $router->put('/children/{id}', [$childController, 'update']);
    $router->delete('/children/{id}', [$childController, 'delete']);
    $router->patch('/children/{id}/status', [$childController, 'updateStatus']);
    $router->put('/children/{id}/defaults', [$childController, 'updateDefaults']);
    $router->get('/children/{id}/absences', [$childController, 'listAbsences']);
    $router->post('/children/{id}/absences', [$childController, 'createAbsence']);
    $router->put('/children/{id}/absences/{absenceId}', [$childController, 'updateAbsence']);
    $router->delete('/children/{id}/absences/{absenceId}', [$childController, 'deleteAbsence']);
    $router->get('/children/{id}/history', [$childController, 'history']);

    require_once __DIR__ . '/controllers/AssignmentController.php';
    $assignmentController = new AssignmentController();
    $router->get('/assignments/my/{id}', [$assignmentController, 'myAssignments']);

    require_once __DIR__ . '/controllers/SlotController.php';
    $slotController = new SlotController();
    $router->patch('/slots/{id}', [$slotController, 'update']);

    require_once __DIR__ . '/controllers/AvailabilityController.php';
    $availabilityController = new AvailabilityController();
    $router->put('/availabilities/week/{id}', [$availabilityController, 'submit']);

    require_once __DIR__ . '/controllers/UserController.php';
    $userController = new UserController();
    $router->get('/users', [$userController, 'list']);
    $router->post('/users', [$userController, 'create']);
    $router->get('/users/parents', [$userController, 'parents']);
    $router->put('/users/{id}', [$userController, 'update']);
    $router->post('/users/{id}/notify', [$userController, 'notify']);
    $router->delete('/users/{id}', [$userController, 'delete']);

    require_once __DIR__ . '/controllers/ScoreAdjustmentController.php';
    $scoreAdjustmentController = new ScoreAdjustmentController();
    $router->get('/score-adjustments/matrix', [$scoreAdjustmentController, 'getScoreMatrix']);
    $router->patch('/score-adjustments', [$scoreAdjustmentController, 'patchScoreAdjustment']);

    require_once __DIR__ . '/controllers/ExchangeController.php';
    $exchangeController = new ExchangeController();
    $router->get('/exchange/offers', [$exchangeController, 'getOffers']);
    $router->post('/exchange/offers', [$exchangeController, 'createOffer']);
    $router->post('/exchange/offers/{id}/take', [$exchangeController, 'takeOffer']);
    $router->delete('/exchange/offers/{id}', [$exchangeController, 'cancelOffer']);
    $router->post('/exchange/proposals/{id}/validate', [$exchangeController, 'validateProposal']);

    require_once __DIR__ . '/controllers/ProfileController.php';
    $profileController = new ProfileController();
    $router->get('/profile', [$profileController, 'get']);
    $router->put('/profile', [$profileController, 'update']);

    // --- NOUVEAU ROUTAGE EXPLICITE (Architecture 3-Tiers) ---
    require_once __DIR__ . '/controllers/PlanningController.php';
    $planningController = new PlanningController();
    $router->get('/planning/{id}', [$planningController, 'get']);
    $router->post('/planning/generate/{id}', [$planningController, 'generate']);

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
