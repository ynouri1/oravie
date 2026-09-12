<?php
/**
 * Diagnostic - Vérifier la configuration du système feedback
 * Accès : https://www.oravie.tn/feedback_diagnostic.php
 */

header('Content-Type: text/html; charset=UTF-8');
?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Diagnostic Feedback - ORAVIE</title>
    <style>
        body { font-family: Courier, monospace; background: #f0f0f0; padding: 20px; }
        .container { max-width: 800px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
        h1 { color: #2F4B3C; }
        .check { margin: 15px 0; padding: 10px; border-left: 4px solid #ddd; }
        .ok { border-color: #10B981; background: #f0fdf4; }
        .error { border-color: #EF4444; background: #fef2f2; }
        .code { background: #f5f5f5; padding: 10px; border-radius: 4px; font-size: 12px; overflow-x: auto; }
        h2 { font-size: 14px; margin: 0 0 8px 0; }
        p { margin: 0; font-size: 13px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>🔍 Diagnostic Feedback System</h1>

        <?php
        $checks = [];

        // 1. Vérifier envprod
        $envprod_path = __DIR__ . '/envprod';
        $check_env = file_exists($envprod_path);
        $checks[] = [
            'name' => 'Fichier envprod',
            'ok' => $check_env,
            'message' => $check_env ? "✅ $envprod_path trouvé" : "❌ $envprod_path manquant"
        ];

        if ($check_env) {
            $env = parse_ini_file($envprod_path);
            if ($env) {
                $checks[] = [
                    'name' => 'Configuration SMTP',
                    'ok' => !empty($env['MAIL_HOST']) && !empty($env['MAIL_USER']),
                    'message' => "MAIL_HOST: " . ($env['MAIL_HOST'] ?? 'manquant') . " | MAIL_USER: " . ($env['MAIL_USER'] ?? 'manquant')
                ];
            }
        }

        // 2. Vérifier PHPMailer
        $phpmailer_path = __DIR__ . '/vendor/phpmailer/PHPMailer.php';
        $checks[] = [
            'name' => 'PHPMailer installé',
            'ok' => file_exists($phpmailer_path),
            'message' => file_exists($phpmailer_path) ? "✅ PHPMailer trouvé" : "❌ PHPMailer manquant à $phpmailer_path"
        ];

        // 3. Vérifier base de données
        if ($env) {
            try {
                $dsn = 'mysql:host=' . $env['DB_HOST'] . ';dbname=' . $env['DB_NAME'] . ';charset=utf8mb4';
                $pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASS']);
                $checks[] = [
                    'name' => 'Connexion Base de Données',
                    'ok' => true,
                    'message' => '✅ Connecté à ' . $env['DB_NAME'] . '@' . $env['DB_HOST']
                ];

                // 4. Vérifier table commandes
                try {
                    $count = $pdo->query("SELECT COUNT(*) FROM commandes")->fetchColumn();
                    $checks[] = [
                        'name' => 'Table commandes',
                        'ok' => true,
                        'message' => "✅ Table existe ($count commandes)"
                    ];

                    // 5. Vérifier colonne feedback
                    $cols = $pdo->query("SHOW COLUMNS FROM commandes LIKE 'feedback_email_sent_at'")->fetchAll();
                    $checks[] = [
                        'name' => 'Colonne feedback_email_sent_at',
                        'ok' => !empty($cols),
                        'message' => !empty($cols) ? '✅ Colonne existe' : '⚠️  Colonne n\'existe pas (sera créée au 1er appel)'
                    ];
                } catch (Exception $e) {
                    $checks[] = [
                        'name' => 'Table commandes',
                        'ok' => false,
                        'message' => '❌ Erreur : ' . $e->getMessage()
                    ];
                }

            } catch (PDOException $e) {
                $checks[] = [
                    'name' => 'Connexion Base de Données',
                    'ok' => false,
                    'message' => '❌ Erreur : ' . $e->getMessage()
                ];
            }
        }

        // 6. Vérifier fichiers
        $files_to_check = [
            '/feedback.php' => 'Formulaire feedback client',
            '/feedback_email_cron.php' => 'Script cron job',
            '/fachfecha/feedback_avis.php' => 'Dashboard admin'
        ];

        foreach ($files_to_check as $path => $name) {
            $full_path = __DIR__ . $path;
            $checks[] = [
                'name' => $name,
                'ok' => file_exists($full_path),
                'message' => file_exists($full_path) ? "✅ $path existe" : "❌ $path manquant"
            ];
        }

        // Afficher les résultats
        foreach ($checks as $check) {
            $class = $check['ok'] ? 'ok' : 'error';
            echo "<div class='check $class'>";
            echo "<h2>" . ($check['ok'] ? '✅' : '❌') . " " . htmlspecialchars($check['name']) . "</h2>";
            echo "<p>" . htmlspecialchars($check['message']) . "</p>";
            echo "</div>";
        }

        // Résumé
        $total = count($checks);
        $ok_count = array_sum(array_map(fn($c) => $c['ok'] ? 1 : 0, $checks));
        echo "<hr>";
        echo "<p><strong>Résultat :</strong> $ok_count/$total vérifications réussies</p>";

        if ($ok_count === $total) {
            echo "<p style='color: #10B981;'><strong>✅ Tout est OK ! Le système est prêt.</strong></p>";
        } else {
            echo "<p style='color: #EF4444;'><strong>❌ Des problèmes ont été détectés.</strong></p>";
        }
        ?>
    </div>
</body>
</html>
