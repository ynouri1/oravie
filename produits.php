<?php
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: same-origin');

$env = parse_ini_file(__DIR__ . '/envprod');
if (!$env) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur de configuration serveur']);
    exit;
}

try {
    $dsn = 'mysql:host=' . $env['DB_HOST'] . ';dbname=' . $env['DB_NAME'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASS'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    $stmt = $pdo->query("
        SELECT id, nom, description, volume_ml, prix, stock
        FROM produits
        WHERE actif = 1
        ORDER BY volume_ml ASC
    ");

    echo json_encode(['success' => true, 'produits' => $stmt->fetchAll()]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur serveur']);
}
