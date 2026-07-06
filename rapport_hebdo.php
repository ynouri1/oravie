<?php
/**
 * Rapport hebdomadaire ORAVIE — À envoyer par mail chaque dimanche
 * 
 * CONFIGURATION REQUISE :
 * 1. Éditer config_rapport.php et ajouter votre email
 * 2. Configurer CRON (voir instructions ci-dessous)
 * 
 * CRON Job sur Linux/OVH :
 * crontab -e
 * Puis ajouter la ligne :
 * 0 9 * * 0 /usr/bin/php /var/www/oravie/rapport_hebdo.php > /var/log/oravie-rapport.log 2>&1
 * 
 * Cela exécutera le script chaque dimanche à 9h du matin
 */

$env = parse_ini_file(__DIR__ . '/envprod');
$config = require __DIR__ . '/config_rapport.php';

if (!$env) {
    die('❌ Erreur : fichier envprod introuvable.');
}

if (!$config || !$config['email_to']) {
    die('❌ Erreur : config_rapport.php introuvable ou email_to vide. Veuillez éditer config_rapport.php');
}

try {
    $dsn = 'mysql:host=' . $env['DB_HOST'] . ';dbname=' . $env['DB_NAME'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASS'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);

    // Dates de la semaine précédente
    $today = new DateTime();
    $dimanche_dernier = clone $today;
    $dimanche_dernier->modify('last Sunday')->setTime(0, 0, 0);
    
    $samedi = clone $dimanche_dernier;
    $samedi->modify('next Saturday')->setTime(23, 59, 59);

    $debut = $dimanche_dernier->format('Y-m-d H:i:s');
    $fin = $samedi->format('Y-m-d H:i:s');
    $semaine = $dimanche_dernier->format('d/m/Y') . ' - ' . $samedi->format('d/m/Y');

    // Stats de la semaine
    $stats = $pdo->prepare("
        SELECT
            COUNT(*) AS total_cmd,
            SUM(CASE WHEN statut = 'livrée' THEN 1 ELSE 0 END) AS cmd_livrees,
            SUM(CASE WHEN statut = 'nouvelle' THEN 1 ELSE 0 END) AS cmd_nouvelles,
            SUM(CASE WHEN statut = 'annulée' THEN 1 ELSE 0 END) AS cmd_annulees,
            COALESCE(SUM(CASE WHEN statut = 'livrée' THEN prix_total ELSE 0 END), 0) AS ca
        FROM commandes
        WHERE date_commande BETWEEN :debut AND :fin
    ");
    $stats->execute([':debut' => $debut, ':fin' => $fin]);
    $stats_data = $stats->fetch(PDO::FETCH_ASSOC);

    // Sprays vendus
    $sprays_data = $pdo->prepare("
        SELECT
            c.donnees
        FROM commandes c
        WHERE c.statut = 'livrée' AND c.date_commande BETWEEN :debut AND :fin
    ");
    $sprays_data->execute([':debut' => $debut, ':fin' => $fin]);
    $sprays_livres = 0;
    foreach ($sprays_data->fetchAll() as $cmd) {
        $donnees = json_decode($cmd['donnees'], true);
        if (isset($donnees['lignes']) && is_array($donnees['lignes'])) {
            foreach ($donnees['lignes'] as $ligne) {
                $sprays_livres += (int)($ligne['quantite'] ?? 0);
            }
        }
    }

    // Top praticiens
    $praticiens = $pdo->prepare("
        SELECT
            COALESCE(CONCAT(p.prenom, ' ', p.nom), 'Sans praticien') AS praticien,
            COUNT(c.id) AS nb_cmd,
            COALESCE(SUM(c.prix_total), 0) AS ca
        FROM commandes c
        LEFT JOIN praticiens p ON c.praticien_id = p.id
        WHERE c.statut = 'livrée' AND c.date_commande BETWEEN :debut AND :fin
        GROUP BY c.praticien_id
        ORDER BY ca DESC
        LIMIT 5
    ");
    $praticiens->execute([':debut' => $debut, ':fin' => $fin]);
    $praticiens_data = $praticiens->fetchAll(PDO::FETCH_ASSOC);

    // Top produits
    $produits_html = '';
    $total_prod = $pdo->prepare("
        SELECT COUNT(*) AS total FROM produits WHERE actif = 1
    ");
    $produits_html .= '<tr><td colspan="3" style="text-align:center; padding:1rem; color:#92A389;">📦 Stock total : ';
    $total_prod->execute();
    $stock_total = $total_prod->fetch()['total'] ?? 0;
    $produits_html .= "$stock_total produits</td></tr>";

    // Email HTML
    $email_html = "
    <html>
    <head>
        <meta charset='utf-8'>
        <style>
            body { font-family: Arial, sans-serif; color: #2C3A2F; background: #F4F7F1; padding: 20px; }
            .container { max-width: 700px; margin: 0 auto; background: white; border-radius: 10px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
            .header { background: #2F4B3C; color: white; padding: 30px; text-align: center; }
            .header h1 { margin: 0; font-size: 24px; }
            .header p { margin: 5px 0 0 0; opacity: 0.9; font-size: 14px; }
            .content { padding: 30px; }
            .kpi { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 30px; }
            .kpi-card { background: #F4F7F1; padding: 15px; border-radius: 8px; border-left: 4px solid #4A735C; }
            .kpi-val { font-size: 28px; font-weight: bold; color: #2F4B3C; }
            .kpi-label { font-size: 12px; color: #92A389; text-transform: uppercase; margin-top: 5px; }
            table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            table th { background: #F4F7F1; padding: 12px; text-align: left; font-size: 12px; color: #7D8F76; font-weight: bold; text-transform: uppercase; }
            table td { padding: 12px; border-top: 1px solid #E2E9DA; }
            table tr:hover { background: #FAFCF8; }
            .footer { background: #F4F7F1; padding: 20px; text-align: center; font-size: 12px; color: #92A389; border-top: 1px solid #E2E9DA; }
            .section-title { font-size: 16px; font-weight: bold; color: #2F4B3C; margin-top: 20px; margin-bottom: 15px; border-bottom: 2px solid #E2E9DA; padding-bottom: 10px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>📊 Rapport Hebdomadaire ORAVIE</h1>
                <p>Semaine du $semaine</p>
            </div>
            <div class='content'>
                <div class='kpi'>
                    <div class='kpi-card'>
                        <div class='kpi-val'>" . ($stats_data['total_cmd'] ?? 0) . "</div>
                        <div class='kpi-label'>Commandes Total</div>
                    </div>
                    <div class='kpi-card'>
                        <div class='kpi-val'>" . ($stats_data['cmd_livrees'] ?? 0) . "</div>
                        <div class='kpi-label'>Commandes Livrées</div>
                    </div>
                    <div class='kpi-card'>
                        <div class='kpi-val'>$sprays_livres</div>
                        <div class='kpi-label'>Sprays Livrés</div>
                    </div>
                    <div class='kpi-card'>
                        <div class='kpi-val'>" . number_format((float)($stats_data['ca'] ?? 0), 2) . " DT</div>
                        <div class='kpi-label'>Chiffre d'Affaires</div>
                    </div>
                </div>

                <div class='section-title'>🏥 Top Praticiens</div>
                <table>
                    <tr>
                        <th>Praticien</th>
                        <th style='text-align:center;'>Cmd</th>
                        <th style='text-align:right;'>CA (DT)</th>
                    </tr>";
                    
                    if (empty($praticiens_data)) {
                        $email_html .= "<tr><td colspan='3' style='text-align:center; color:#92A389;'>Aucune commande livrée cette semaine</td></tr>";
                    } else {
                        foreach ($praticiens_data as $p) {
                            $email_html .= "<tr>
                                <td>" . htmlspecialchars($p['praticien']) . "</td>
                                <td style='text-align:center;'>" . (int)$p['nb_cmd'] . "</td>
                                <td style='text-align:right;'>" . number_format((float)$p['ca'], 2) . " DT</td>
                            </tr>";
                        }
                    }

                $email_html .= "</table>

                <div class='section-title'>📦 Résumé Produits</div>
                <table>
                    <tr><th colspan='3'>Stock & Activité</th></tr>
                    $produits_html
                </table>

                <div class='section-title'>📈 Détails Commandes</div>
                <table>
                    <tr>
                        <th>Statut</th>
                        <th style='text-align:center;'>Nombre</th>
                    </tr>
                    <tr>
                        <td>✅ Livrées</td>
                        <td style='text-align:center;'>" . ($stats_data['cmd_livrees'] ?? 0) . "</td>
                    </tr>
                    <tr>
                        <td>🔄 Nouvelles</td>
                        <td style='text-align:center;'>" . ($stats_data['cmd_nouvelles'] ?? 0) . "</td>
                    </tr>
                    <tr>
                        <td>❌ Annulées</td>
                        <td style='text-align:center;'>" . ($stats_data['cmd_annulees'] ?? 0) . "</td>
                    </tr>
                </table>
            </div>
            <div class='footer'>
                <p>Rapport automatique généré le " . date('d/m/Y à H:i') . "</p>
                <p><a href='https://www.oravie.tn/fachfecha/stats.php' style='color:#4A735C; text-decoration:none;'>Voir le détail sur le dashboard →</a></p>
            </div>
        </div>
    </body>
    </html>";

    // Email (utilise la config)
    $to = $config['email_to'];
    $subject = '📊 Rapport Hebdomadaire ORAVIE — ' . $semaine;
    
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=utf-8\r\n";
    $headers .= "From: noreply@oravie.tn\r\n";

    if (mail($to, $subject, $email_html, $headers)) {
        echo "✅ Rapport envoyé avec succès à $to\n";
        echo "📊 Statistiques semaine $semaine :\n";
        echo "   • Commandes : " . ($stats_data['total_cmd'] ?? 0) . "\n";
        echo "   • Livrées : " . ($stats_data['cmd_livrees'] ?? 0) . "\n";
        echo "   • Sprays : $sprays_livres\n";
        echo "   • CA : " . number_format((float)($stats_data['ca'] ?? 0), 2) . " DT\n";
    } else {
        echo "❌ Erreur lors de l'envoi du mail à $to\n";
    }

} catch (Exception $e) {
    echo "❌ Erreur : " . $e->getMessage();
}
