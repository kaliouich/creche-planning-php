<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';

$pdo = get_db();
$stmt = $pdo->query("SELECT * FROM children LIMIT 3");
while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
}
