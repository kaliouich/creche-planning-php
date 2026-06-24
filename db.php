<?php
/**
 * Connexion PDO sécurisée à MySQL.
 */
require_once __DIR__ . '/config.php';

function get_db(bool $reset = false): PDO|null {
    static $pdo = null;
    if ($reset) {
        $pdo = null;
        return null;
    }
    if ($pdo === null) {
        $testDb = getenv('TEST_DB_SQLITE');
        if ($testDb) {
            $pdo = new PDO('sqlite:' . $testDb);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        } else {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        }
    }
    return $pdo;
}

// Legacy alias — kept for backward compatibility during migration
// TODO: Remove once all call sites use get_db() directly
function get_db_connection(): PDO {
    return get_db();
}
