<?php
/**
 * Script de test — À SUPPRIMER APRÈS UTILISATION
 * Accès : https://www.oravie.tn/testmail.php?t=oravie_test_2026
 * Test vers autre adresse : ...?t=oravie_test_2026&to=votre@gmail.com
 */
define('TOKEN', 'oravie_test_2026');
if (($_GET['t'] ?? '') !== TOKEN) {
    http_response_code(403);
    die('Accès refusé. Ajoutez ?t=oravie_test_2026 à l\'URL.');
}

$env = parse_ini_file(__DIR__ . '/envprod');
$testTo = filter_var($_GET['to'] ?? '', FILTER_VALIDATE_EMAIL) ?: $env['MAIL_TO'];

echo '<pre>';
echo "=== TEST DB ===\n";
try {
    $dsn = 'mysql:host=' . $env['DB_HOST'] . ';dbname=' . $env['DB_NAME'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASS'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $cnt = $pdo->query("SELECT COUNT(*) FROM commandes")->fetchColumn();
    echo "✅ DB OK — {$cnt} commande(s)\n\n";
} catch (PDOException $e) {
    echo "❌ DB ERREUR : " . $e->getMessage() . "\n\n";
}

echo "=== TEST 1 : mail() natif → {$testTo} ===\n";
$subject1 = '=?UTF-8?B?' . base64_encode('Test mail() ORAVIE - ' . date('H:i:s')) . '?=';
$body1    = '<p>Test mail() natif PHP — ' . date('d/m/Y H:i:s') . '</p>';
$headers1 = implode("\r\n", [
    'MIME-Version: 1.0',
    'Content-Type: text/html; charset=UTF-8',
    'From: "ORAVIE" <' . $env['MAIL_FROM'] . '>',
]);
$r1 = mail($testTo, $subject1, $body1, $headers1);
echo $r1 ? "✅ mail() accepté par PHP\n" : "❌ mail() retourné false\n";
if ($err = error_get_last()) echo "   Dernière erreur PHP : " . $err['message'] . "\n";

echo "\n=== TEST 2 : PHPMailer SMTP → {$testTo} ===\n";
try {
    require_once __DIR__ . '/vendor/phpmailer/Exception.php';
    require_once __DIR__ . '/vendor/phpmailer/PHPMailer.php';
    require_once __DIR__ . '/vendor/phpmailer/SMTP.php';
    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    $mail->isSMTP();
    $mail->Timeout    = 15;
    $mail->Host       = $env['MAIL_HOST'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $env['MAIL_USER'];
    $mail->Password   = $env['MAIL_PASS'];
    $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port       = (int) $env['MAIL_PORT'];
    $mail->CharSet    = 'UTF-8';
    $mail->setFrom($env['MAIL_FROM'], $env['MAIL_FROM_NAME']);
    $mail->addAddress($testTo);
    $mail->Subject = 'Test PHPMailer SMTP ORAVIE - ' . date('H:i:s');
    $mail->isHTML(true);
    $mail->Body    = '<p>Test PHPMailer SMTP — ' . date('d/m/Y H:i:s') . '</p><p>Host: ' . $env['MAIL_HOST'] . ':' . $env['MAIL_PORT'] . '</p>';
    $mail->send();
    echo "✅ PHPMailer SMTP OK — email envoyé à {$testTo}\n";
} catch (Exception $e) {
    echo "❌ PHPMailer ERREUR : " . $e->getMessage() . "\n";
}

echo "\nPHP " . phpversion() . "\n";
echo '</pre>';
echo '<br><strong style="color:red">SUPPRIMEZ CE FICHIER DU SERVEUR !</strong>';
