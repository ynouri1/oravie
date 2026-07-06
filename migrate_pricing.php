<?php
/**
 * Migration : Ajouter colonne prix_total et synchroniser les frais de livraison dans le JSON
 * CA = Prix des produits seulement (SANS frais de livraison 8 DT)
 * À exécuter UNE SEULE FOIS via navigateur, puis supprimer.
 */

$env = parse_ini_file(__DIR__ . '/envprod');
if (!$env) {
    die('❌ Erreur : fichier envprod introuvable.');
}

try {
    $dsn = 'mysql:host=' . $env['DB_HOST'] . ';dbname=' . $env['DB_NAME'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASS'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    $steps = [];

    // Étape 1 : Ajouter colonne prix_total (produits seulement, sans frais)
    try {
        $pdo->exec("ALTER TABLE commandes ADD COLUMN prix_total DECIMAL(10,2) DEFAULT 0");
        $steps[] = '✅ Colonne prix_total créée';
    } catch (Exception $e) {
        $steps[] = '⏩ Colonne prix_total existe déjà';
    }

    // Étape 2 : Remplir prix_total depuis le JSON pour les commandes qui l'ont
    $updated = $pdo->exec("
        UPDATE commandes 
        SET prix_total = CAST(donnees->>'$.prix_total' AS DECIMAL(10,2))
        WHERE donnees->>'$.prix_total' IS NOT NULL 
        AND donnees->>'$.prix_total' != ''
    ");
    $steps[] = "✅ {$updated} commandes mises à jour depuis le JSON (prix_total existant)";

    // Étape 3 : Pour les anciennes commandes sans prix_total dans JSON,
    // calculer depuis les lignes (SANS frais de livraison)
    // Première, identifier les commandes qui n'ont pas prix_total mais ont des lignes
    $commandes_old = $pdo->query("
        SELECT id, donnees
        FROM commandes
        WHERE prix_total = 0 
        AND donnees IS NOT NULL
        AND JSON_CONTAINS_PATH(donnees, 'one', '$.lignes')
    ")->fetchAll(PDO::FETCH_ASSOC);

    $updated_old = 0;
    foreach ($commandes_old as $cmd) {
        $donnees = json_decode($cmd['donnees'], true);
        
        // Calculer prix depuis les lignes (produits seulement)
        $prix_produits = 0;
        if (isset($donnees['lignes']) && is_array($donnees['lignes'])) {
            foreach ($donnees['lignes'] as $ligne) {
                $prix_produits += (float) ($ligne['sous_total'] ?? 0);
            }
        }
        
        // Ajouter frais_livraison au JSON s'absent (pour affichage)
        if (!isset($donnees['frais_livraison'])) {
            $donnees['frais_livraison'] = 8.00;
        }
        // S'assurer que prix_total est présent
        if (!isset($donnees['prix_total'])) {
            $donnees['prix_total'] = $prix_produits;
        }
        // S'assurer que prix_total_ttc est présent
        if (!isset($donnees['prix_total_ttc'])) {
            $donnees['prix_total_ttc'] = round($prix_produits + 8.00, 2);
        }
        
        $donnees_json = json_encode($donnees, JSON_UNESCAPED_UNICODE);
        
        // Mettre à jour commande - prix_total = produits seulement
        $stmt = $pdo->prepare("UPDATE commandes SET donnees = :d, prix_total = :p WHERE id = :id");
        $stmt->execute([':d' => $donnees_json, ':p' => $prix_produits, ':id' => $cmd['id']]);
        $updated_old++;
    }
    $steps[] = "✅ {$updated_old} anciennes commandes recalculées (CA = produits seulement, sans frais)";

    // Résumé
    echo '<html><head><meta charset="utf-8"><title>Migration Prix</title><style>
        body { font-family: Arial, sans-serif; background: #F4F7F1; padding: 2rem; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 2rem; border-radius: 1rem; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #2F4B3C; margin-bottom: 1.5rem; }
        .step { margin: 0.8rem 0; font-size: 1.1rem; }
        .warning { background: #FEF3C7; border: 1px solid #FDE68A; padding: 1rem; border-radius: 0.5rem; margin-top: 2rem; color: #92400E; }
        .done { background: #D1FAE5; border: 1px solid #A7F3D0; padding: 1rem; border-radius: 0.5rem; margin-top: 2rem; color: #065F46; font-weight: bold; }
        .note { background: #E0E7FF; border: 1px solid #C7D2FE; padding: 1rem; border-radius: 0.5rem; margin-top: 2rem; color: #3730A3; }
    </style></head><body>';
    echo '<div class="container">';
    echo '<h1><i class="fas fa-database"></i> Migration Prix & Frais</h1>';
    
    foreach ($steps as $step) {
        echo '<div class="step">' . htmlspecialchars($step) . '</div>';
    }
    
    echo '<div class="note"><strong>ℹ️ Logique CA :</strong><br>
    • CA = Prix des produits seulement (colonne prix_total)<br>
    • Frais livraison = 8 DT (toujours dans JSON, mais pas comptabilisés en CA)<br>
    • TTC = CA + Frais (ce que le client paie réellement)</div>';
    
    echo '<div class="done">✅ Migration terminée avec succès !</div>';
    echo '<div class="warning"><strong>⚠️ Important :</strong> Supprimez ce fichier (migrate_pricing.php) une fois la migration terminée.</div>';
    echo '</div></body></html>';

} catch (PDOException $e) {
    die('<html><head><meta charset="utf-8"><style>body{font-family:Arial;background:#FEE2E2;padding:2rem}h1{color:#991B1B}</style></head><body><h1>❌ Erreur Migration</h1><p>' . htmlspecialchars($e->getMessage()) . '</p></body></html>');
}
