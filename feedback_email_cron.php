<?php
/**
 * CRON JOB : Envoyer emails de feedback après 2 semaines de livraison
 * À exécuter toutes les heures ou une fois par jour
 * Accès sécurisé : ?token=oravie_cron_token_2026
 */

define('CRON_TOKEN', 'oravie_cron_token_2026');
if (($_GET['token'] ?? '') !== CRON_TOKEN) {
    http_response_code(403);
    die('Accès refusé.');
}

$env = parse_ini_file(__DIR__ . '/envprod');
if (!$env) {
    die('Erreur : fichier envprod introuvable.');
}

try {
    $dsn = 'mysql:host=' . $env['DB_HOST'] . ';dbname=' . $env['DB_NAME'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASS'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Vérifier et créer la colonne feedback_email_sent_at si elle n'existe pas
    try {
        if (empty($pdo->query("SHOW COLUMNS FROM commandes LIKE 'feedback_email_sent_at'")->fetchAll())) {
            $pdo->exec("ALTER TABLE commandes ADD COLUMN feedback_email_sent_at DATETIME NULL DEFAULT NULL");
            echo "✅ Colonne feedback_email_sent_at créée.\n";
        }
    } catch (Exception $e) {
        echo "⚠️  Colonne feedback_email_sent_at : " . $e->getMessage() . "\n";
    }

    // Charger PHPMailer
    require_once __DIR__ . '/vendor/phpmailer/Exception.php';
    require_once __DIR__ . '/vendor/phpmailer/PHPMailer.php';
    require_once __DIR__ . '/vendor/phpmailer/SMTP.php';
    use PHPMailer\PHPMailer\PHPMailer;
    use PHPMailer\PHPMailer\Exception as MailException;

    // Chercher les commandes livrées il y a 2 semaines dont l'email n'a pas été envoyé
    $sql = "
        SELECT id, donnees, DATE_ADD(date_commande, INTERVAL 2 WEEK) as date_feedback
        FROM commandes
        WHERE statut = 'livrée'
        AND feedback_email_sent_at IS NULL
        AND date_commande <= DATE_SUB(NOW(), INTERVAL 2 WEEK)
        ORDER BY date_commande ASC
        LIMIT 10
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $commandes = $stmt->fetchAll();

    echo "📧 Traitement de " . count($commandes) . " commande(s) pour feedback...\n";

    foreach ($commandes as $cmd) {
        $donnees = json_decode($cmd['donnees'], true);
        $email = $donnees['email'] ?? null;
        $nom_client = trim(($donnees['prenom'] ?? '') . ' ' . ($donnees['nom'] ?? ''));
        $cmd_id = $cmd['id'];

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo "⚠️  Commande #$cmd_id : email invalide ({$email}).\n";
            // Marquer comme envoyée quand même pour éviter de relancer
            $pdo->prepare("UPDATE commandes SET feedback_email_sent_at = NOW() WHERE id = :id")
                ->execute([':id' => $cmd_id]);
            continue;
        }

        try {
            $mail = new PHPMailer(true);
            $mail->isSMTP();
            $mail->Timeout = 15;
            $mail->Host = $env['MAIL_HOST'];
            $mail->SMTPAuth = true;
            $mail->Username = $env['MAIL_USER'];
            $mail->Password = $env['MAIL_PASS'];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mail->Port = (int)$env['MAIL_PORT'];
            $mail->CharSet = 'UTF-8';

            $mail->setFrom($env['MAIL_FROM'], $env['MAIL_FROM_NAME']);
            $mail->addAddress($email, $nom_client);
            if (!empty($env['MAIL_CC'])) {
                $mail->addCC($env['MAIL_CC']);
            }

            // Générer le token de feedback unique
            $feedback_token = bin2hex(random_bytes(16));
            $feedback_url = 'https://www.oravie.tn/feedback.php?id=' . $cmd_id . '&token=' . $feedback_token;

            $mail->Subject = 'Votre avis sur votre commande ORAVIE';
            $mail->isHTML(true);
            $mail->Body = "
                <html>
                <head>
                    <meta charset='UTF-8'>
                    <style>
                        body { font-family: 'Segoe UI', sans-serif; color: #2C3A2F; line-height: 1.6; }
                        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                        .header { background: #2F4B3C; color: white; padding: 20px; border-radius: 8px 8px 0 0; text-align: center; }
                        .content { background: #f9faf8; padding: 30px; border-radius: 0 0 8px 8px; }
                        .btn { display: inline-block; background: #4A735C; color: white; padding: 12px 30px; text-decoration: none; border-radius: 6px; margin: 20px 0; font-weight: bold; }
                        .footer { font-size: 12px; color: #7D8F76; margin-top: 20px; text-align: center; }
                    </style>
                </head>
                <body>
                    <div class='container'>
                        <div class='header'>
                            <h2>Votre avis nous intéresse ! 🌿</h2>
                        </div>
                        <div class='content'>
                            <p>Bonjour <strong>" . htmlspecialchars($nom_client) . "</strong>,</p>
                            
                            <p>Vous avez reçu votre commande ORAVIE il y a environ 2 semaines. 
                            Nous aimerions connaître votre avis sur :</p>
                            
                            <ul>
                                <li>✅ La qualité du <strong>produit</strong></li>
                                <li>📦 La <strong>livraison</strong></li>
                                <li>🌐 Notre <strong>site</strong></li>
                                <li>💡 Vos <strong>remarques et suggestions d'amélioration</strong></li>
                            </ul>
                            
                            <p>Votre retour d'expérience nous aide à nous améliorer continuellement.</p>
                            
                            <p style='text-align: center;'>
                                <a href='" . htmlspecialchars($feedback_url) . "' class='btn'>Partager mon avis (2 min)</a>
                            </p>
                            
                            <p style='font-size: 13px; color: #666;'>
                                Ou copiez-collez ce lien dans votre navigateur :<br>
                                <code style='word-break: break-all;'>" . htmlspecialchars($feedback_url) . "</code>
                            </p>
                            
                            <p>Merci de votre confiance et de votre fidélité ! 🙏</p>
                            
                            <p>Cordialement,<br><strong>L'équipe ORAVIE</strong></p>
                        </div>
                        <div class='footer'>
                            <p>ORAVIE - Spray désinfectant antibactérien 100% naturel</p>
                            <p>contact@oravie.tn | www.oravie.tn</p>
                        </div>
                    </div>
                </body>
                </html>
            ";

            $mail->send();

            // Marquer comme envoyée et stocker le token
            $pdo->prepare("UPDATE commandes SET feedback_email_sent_at = NOW() WHERE id = :id")
                ->execute([':id' => $cmd_id]);

            echo "✅ Commande #$cmd_id → $email\n";

        } catch (MailException $e) {
            echo "❌ Commande #$cmd_id → Erreur : " . $e->getMessage() . "\n";
        }
    }

    echo "\n🏁 Cron job terminé.\n";

} catch (PDOException $e) {
    die('❌ Erreur DB : ' . $e->getMessage());
}
