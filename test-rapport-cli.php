#!/usr/bin/env php
<?php
/**
 * Test manuel du rapport hebdomadaire
 * Commande: php test-rapport-cli.php
 */

echo "\n🧪 TEST RAPPORT HEBDOMADAIRE ORAVIE\n";
echo "=====================================\n\n";

$script_dir = __DIR__;

// === Vérifications ===
echo "📋 Vérifications...\n";

// 1. PHP version
echo "  • PHP version: " . PHP_VERSION . "\n";

// 2. Fichiers
$files = [
    'envprod' => $script_dir . '/envprod',
    'config_rapport.php' => $script_dir . '/config_rapport.php',
    'vendor/phpmailer/PHPMailer.php' => $script_dir . '/vendor/phpmailer/PHPMailer.php',
];

foreach ($files as $name => $path) {
    $exists = file_exists($path) ? '✅' : '❌';
    echo "  $exists $name: " . (file_exists($path) ? 'OK' : 'MANQUANT') . "\n";
}

// 3. Dossiers
$dirs = [
    'vendor' => $script_dir . '/vendor',
    'logs' => $script_dir . '/logs',
];

foreach ($dirs as $name => $path) {
    $exists = is_dir($path) ? '✅' : '❌';
    $writable = is_writable($path) ? '(R/W)' : '(RO)';
    echo "  $exists $name: " . (is_dir($path) ? 'OK ' . $writable : 'MANQUANT') . "\n";
}

// 4. Extensions PHP
echo "\n📦 Extensions PHP:\n";
$extensions = ['pdo', 'pdo_mysql', 'curl', 'json'];
foreach ($extensions as $ext) {
    $has = extension_loaded($ext) ? '✅' : '❌';
    echo "  $has $ext\n";
}

// 5. Charger la config
echo "\n⚙️  Configuration:\n";
try {
    $env = parse_ini_file($script_dir . '/envprod');
    if ($env) {
        echo "  ✅ envprod chargé\n";
        echo "    - BD: {$env['DB_HOST']}/{$env['DB_NAME']}\n";
        echo "    - Mail: {$env['MAIL_USER']} via {$env['MAIL_HOST']}:{$env['MAIL_PORT']}\n";
    }
} catch (Exception $e) {
    echo "  ❌ Erreur envprod: " . $e->getMessage() . "\n";
}

$config = require $script_dir . '/config_rapport.php';
if ($config) {
    echo "  ✅ config_rapport.php chargé\n";
    echo "    - Email: {$config['email_to']}\n";
}

// 6. Test connexion BD
echo "\n🔌 Test connexion BD:\n";
try {
    $dsn = 'mysql:host=' . $env['DB_HOST'] . ';dbname=' . $env['DB_NAME'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASS'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $result = $pdo->query('SELECT COUNT(*) as count FROM commandes');
    $count = $result->fetch()['count'];
    echo "  ✅ Connexion OK\n";
    echo "    - Commandes en BD: $count\n";
} catch (Exception $e) {
    echo "  ❌ Erreur BD: " . $e->getMessage() . "\n";
}

// 7. Test email (simulation)
echo "\n📧 Configuration SMTP:\n";
echo "  • Host: {$env['MAIL_HOST']}\n";
echo "  • Port: {$env['MAIL_PORT']}\n";
echo "  • User: {$env['MAIL_USER']}\n";
echo "  • From: {$env['MAIL_FROM']}\n";
echo "  ⚠️  Test réel non effectué (ne change pas d'email)\n";

echo "\n✅ TEST TERMINÉ\n";
echo "=====================================\n";
echo "\nPour lancer le rapport réel:\n";
echo "  php /var/www/oravie/rapport_hebdo.php\n\n";
