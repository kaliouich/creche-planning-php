<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

try {
    $pdo = get_db();
    
    echo "Migration en cours...\n";

    // Vérifier si la colonne existe déjà
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'second_email'");
    if ($stmt->rowCount() > 0) {
        echo "La colonne 'second_email' existe déjà.\n";
    } else {
        $pdo->exec("ALTER TABLE users ADD COLUMN second_email VARCHAR(255) DEFAULT NULL AFTER email");
        echo "✅ Colonne 'second_email' ajoutée avec succès à la table 'users'.\n";
    }

} catch (Exception $e) {
    echo "❌ Erreur: " . $e->getMessage() . "\n";
}
