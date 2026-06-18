<?php
/**
 * Script d'initialisation de la base de données ORAVIE.
 * À exécuter UNE SEULE FOIS via le navigateur ou en CLI, puis supprimer.
 */

$env = parse_ini_file(__DIR__ . '/envprod');
if (!$env) {
    die('Erreur : fichier envprod introuvable.');
}

try {
    $dsn = 'mysql:host=' . $env['DB_HOST'] . ';dbname=' . $env['DB_NAME'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASS'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    // Table produits
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS produits (
            id          INT AUTO_INCREMENT PRIMARY KEY,
            nom         VARCHAR(150)   NOT NULL,
            description TEXT,
            volume_ml   INT            NOT NULL UNIQUE,
            prix        DECIMAL(8,2)   NOT NULL,
            stock       INT            NOT NULL DEFAULT 0,
            actif       TINYINT(1)     NOT NULL DEFAULT 1
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Insérer les 2 produits seulement s'ils n'existent pas déjà
    $stmt = $pdo->prepare("
        INSERT IGNORE INTO produits (nom, description, volume_ml, prix, stock, actif)
        VALUES (:nom, :description, :volume_ml, :prix, :stock, 1)
    ");

    $stmt->execute([
        ':nom'         => 'Spray ORAVIE 50 ml',
        ':description' => 'Spray désinfectant antibactérien 100% naturel — 50 ml',
        ':volume_ml'   => 50,
        ':prix'        => 18.00,
        ':stock'       => 100,
    ]);

    $stmt->execute([
        ':nom'         => 'Spray ORAVIE 100 ml',
        ':description' => 'Spray désinfectant antibactérien 100% naturel — 100 ml',
        ':volume_ml'   => 100,
        ':prix'        => 30.00,
        ':stock'       => 100,
    ]);

    // Table commandes
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS commandes (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            date_commande DATETIME DEFAULT CURRENT_TIMESTAMP,
            donnees       JSON NOT NULL,
            statut        VARCHAR(50) DEFAULT 'nouvelle'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Table admins
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS admins (
            id       INT AUTO_INCREMENT PRIMARY KEY,
            username VARCHAR(100) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Compte admin par défaut (identifiant: admin / mot de passe: Oravie2026!)
    $hash = password_hash('    https://www.oravie.tn/testmail.php?t=oravie_test_2026&to=tonmail@gmail.com', PASSWORD_DEFAULT);
    $pdo->prepare("INSERT IGNORE INTO admins (username, password) VALUES (:u, :p)")
        ->execute([':u' => 'admin', ':p' => $hash]);

    echo '✅ Base de données initialisée avec succès. Supprimez ce fichier.';

} catch (PDOException $e) {
    die('Erreur DB : ' . $e->getMessage());
}
