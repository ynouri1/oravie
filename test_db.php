<?php
header('Content-Type: application/json; charset=UTF-8');

$env = parse_ini_file(__DIR__ . '/envprod');
if (!$env) {
    echo json_encode(['success' => false, 'message' => 'envprod not found']);
    exit;
}

try {
    $dsn = 'mysql:host=' . $env['DB_HOST'] . ';dbname=' . $env['DB_NAME'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASS'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    
    echo json_encode(['success' => true, 'message' => 'Connection OK']);
    
    // Test CREATE TABLE
    $pdo->exec("CREATE TABLE IF NOT EXISTS commandes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        date_commande DATETIME DEFAULT CURRENT_TIMESTAMP,
        donnees JSON NOT NULL,
        statut VARCHAR(50) DEFAULT 'nouvelle',
        praticien_id INT NULL DEFAULT NULL,
        lot_id INT NULL DEFAULT NULL,
        prix_total DECIMAL(10,2) NULL DEFAULT NULL,
        feedback_email_sent_at DATETIME NULL DEFAULT NULL,
        feedback_token VARCHAR(64) NULL DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    echo json_encode(['success' => true, 'message' => 'Table creation OK']);
    
    // Test table structure
    $cols = $pdo->query("SHOW COLUMNS FROM commandes")->fetchAll();
    echo json_encode(['success' => true, 'columns' => count($cols), 'message' => 'Table structure OK']);
    
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
