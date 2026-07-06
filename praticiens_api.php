<?php
header('Content-Type: application/json; charset=UTF-8');

$env = parse_ini_file(__DIR__ . '/envprod');
if (!$env) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur de configuration']);
    exit;
}

try {
    $dsn = 'mysql:host=' . $env['DB_HOST'] . ';dbname=' . $env['DB_NAME'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASS'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    $praticiens = [];
    
    // Vérifier si la table existe
    try {
        $praticiens = $pdo->query("SELECT id, prenom, nom, specialite FROM praticiens WHERE actif = 1 ORDER BY nom, prenom ASC")->fetchAll();
    } catch (PDOException $e) {
        // Table n'existe pas encore, retourner un tableau vide
        $praticiens = [];
    }

    echo json_encode([
        'success' => true,
        'praticiens' => $praticiens,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Erreur base de données']);
}
