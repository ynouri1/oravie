<?php
/**
 * Créer une commande de TEST pour démonstration du formulaire feedback
 * Accès : https://www.oravie.tn/create_test_commande.php
 */

header('Content-Type: text/html; charset=UTF-8');

// Charger configuration
$env = parse_ini_file(__DIR__ . '/envprod');
if (!$env) {
    die('❌ Erreur : envprod manquant');
}

try {
    $dsn = 'mysql:host=' . $env['DB_HOST'] . ';dbname=' . $env['DB_NAME'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASS'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Créer une commande de test
    $donnees = [
        'prenom' => 'Jean',
        'nom' => 'Dupont',
        'email' => 'jean.dupont@example.com',
        'telephone' => '+216 92 123 456',
        'adresse' => '123 Rue du Test',
        'ville' => 'Tunis',
        'lignes' => [
            ['produit_nom' => 'Spray ORAVIE 50 ml', 'quantite' => 1, 'prix_unitaire' => 18.00],
            ['produit_nom' => 'Spray ORAVIE 100 ml', 'quantite' => 1, 'prix_unitaire' => 30.00]
        ],
        'prix_total' => 48.00
    ];

    // Date de livraison : aujourd'hui - 15 jours (pour que le cron job la détecte)
    $date_livraison = date('Y-m-d H:i:s', strtotime('-15 days'));

    $stmt = $pdo->prepare("
        INSERT INTO commandes (date_commande, donnees, statut, prix_total, feedback_email_sent_at)
        VALUES (:date, :donnees, 'livrée', :prix, NULL)
    ");

    $stmt->execute([
        ':date' => $date_livraison,
        ':donnees' => json_encode($donnees),
        ':prix' => 48.00
    ]);

    $cmd_id = $pdo->lastInsertId();

    // Générer un token de feedback
    $feedback_token = bin2hex(random_bytes(16));
    $feedback_url = 'https://www.oravie.tn/feedback.php?id=' . $cmd_id . '&token=' . $feedback_token;

?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Commande Test Créée</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', sans-serif; background: #f0f4ed; padding: 20px; }
        .container { max-width: 600px; margin: 50px auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
        .success { background: #D1FAE5; border: 2px solid #10B981; padding: 15px; border-radius: 8px; margin-bottom: 20px; color: #065F46; }
        h1 { color: #2F4B3C; margin-bottom: 20px; }
        .info-box { background: #f9faf8; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #4A735C; }
        .info-box p { margin: 10px 0; font-size: 0.95rem; }
        .label { font-weight: 700; color: #2F4B3C; }
        .value { color: #4A735C; word-break: break-all; }
        .link-box { background: #fff3cd; padding: 15px; border-radius: 8px; margin-bottom: 20px; border-left: 4px solid #ffc107; }
        .link-box a { display: inline-block; background: #4A735C; color: white; padding: 12px 24px; text-decoration: none; border-radius: 6px; margin-top: 10px; font-weight: 700; }
        .link-box a:hover { background: #2F4B3C; }
        .link-text { 
            background: #f5f5f5; 
            padding: 10px; 
            border-radius: 4px; 
            font-size: 12px; 
            margin-top: 10px;
            word-break: break-all;
            font-family: Courier, monospace;
        }
        code { background: #f5f5f5; padding: 2px 6px; border-radius: 3px; font-family: Courier; }
    </style>
</head>
<body>
    <div class="container">
        <div class="success">
            <h2>✅ Commande de test créée avec succès !</h2>
        </div>

        <h1>🔗 Lien de Feedback pour Tester</h1>

        <div class="info-box">
            <p><span class="label">Commande ID :</span> <span class="value"><?= $cmd_id ?></span></p>
            <p><span class="label">Statut :</span> <span class="value">Livrée</span></p>
            <p><span class="label">Date de livraison :</span> <span class="value"><?= $date_livraison ?></span></p>
            <p><span class="label">Client :</span> <span class="value">Jean Dupont (jean.dupont@example.com)</span></p>
            <p><span class="label">Montant :</span> <span class="value">48.00 DT</span></p>
        </div>

        <div class="link-box">
            <h3>📧 Lien de Feedback Fonctionnel :</h3>
            
            <a href="<?= htmlspecialchars($feedback_url) ?>" target="_blank">
                ➜ Ouvrir le formulaire de feedback
            </a>

            <div class="link-text">
                <?= htmlspecialchars($feedback_url) ?>
            </div>
        </div>

        <div class="info-box" style="border-left-color: #7C3AED; background: #f5f3ff;">
            <h3 style="color: #7C3AED; margin-bottom: 10px;">💡 Points importants :</h3>
            <p>✅ La commande de test est marquée comme "livrée" il y a 15 jours</p>
            <p>✅ Elle sera détectée par le cron job pour envoi d'emails</p>
            <p>✅ Vous pouvez tester le formulaire de feedback dès maintenant</p>
            <p>✅ Les données seront enregistrées dans <code>feedback_avis</code></p>
            <p>✅ Visible sur <code>fachfecha/feedback_avis.php</code></p>
        </div>

        <div class="info-box" style="border-left-color: #DC2626; background: #fef2f2;">
            <p><strong>⚠️ Note :</strong> Ceci est une commande de test. N'oubliez pas de la supprimer après test :</p>
            <p><code>DELETE FROM commandes WHERE id = <?= $cmd_id ?></code></p>
        </div>
    </div>
</body>
</html>
<?php
} catch (Exception $e) {
    echo "❌ Erreur : " . htmlspecialchars($e->getMessage());
}
