<?php
/**
 * Test du rapport hebdomadaire via navigateur
 * Accès : http://oravie.tn/test-rapport.php
 * Clé de sécurité : test123
 */

// Clé de sécurité simple
define('TEST_SECRET', 'test123');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['secret'])) {
    if ($_POST['secret'] !== TEST_SECRET) {
        die('<h1 style="color:red;">❌ Clé d\'accès invalide</h1>');
    }
    
    echo '<h1 style="color:#2F4B3C; text-align:center;">📊 Exécution du rapport...</h1>';
    echo '<pre style="background:#f4f4f4; padding:20px; font-family:monospace; border-radius:5px; overflow-x:auto;">';
    
    // Incluire et exécuter le rapport
    ob_start();
    include __DIR__ . '/rapport_hebdo.php';
    $output = ob_get_clean();
    
    echo htmlspecialchars($output);
    echo '</pre>';
    
    echo '<div style="text-align:center; margin-top:30px; padding:20px; background:#E8F4E6; border-radius:5px;">';
    echo '<strong>✅ Test terminé</strong><br>';
    echo 'Vérifiez votre email pour confirmer la réception du rapport.';
    echo '</div>';
    
    exit;
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Rapport ORAVIE</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: Arial, sans-serif; background: #F4F7F1; padding: 40px 20px; min-height: 100vh; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 40px; border-radius: 10px; box-shadow: 0 4px 15px rgba(0,0,0,0.1); }
        h1 { color: #2F4B3C; text-align: center; margin-bottom: 30px; font-size: 28px; }
        .info { background: #E8F4E6; border-left: 4px solid #4A735C; padding: 15px; margin-bottom: 30px; border-radius: 5px; color: #2C3A2F; }
        .info strong { display: block; margin-bottom: 10px; }
        .form-group { margin: 20px 0; }
        label { display: block; margin-bottom: 8px; font-weight: bold; color: #2C3A2F; font-size: 14px; }
        input { width: 100%; padding: 12px; border: 2px solid #E2E9DA; border-radius: 5px; box-sizing: border-box; font-size: 16px; transition: border-color 0.3s; }
        input:focus { outline: none; border-color: #4A735C; }
        button { width: 100%; padding: 14px; background: #4A735C; color: white; border: none; border-radius: 5px; font-size: 16px; font-weight: bold; cursor: pointer; transition: background 0.3s; margin-top: 10px; }
        button:hover { background: #2F4B3C; }
        button:active { transform: scale(0.98); }
        .footer { text-align: center; margin-top: 30px; font-size: 12px; color: #92A389; }
    </style>
</head>
<body>
    <div class="container">
        <h1>📊 Test du Rapport Hebdomadaire</h1>
        
        <div class="info">
            <strong>🔒 Zone sécurisée</strong>
            Entrez la clé de sécurité pour déclencher l'envoi du rapport par email.
        </div>
        
        <form method="POST">
            <div class="form-group">
                <label for="secret">Clé de sécurité :</label>
                <input type="password" name="secret" id="secret" placeholder="Entrez la clé" required autofocus>
            </div>
            <button type="submit">🚀 Tester l'envoi du rapport</button>
        </form>
        
        <div class="footer">
            <p>💡 <strong>Astuce :</strong> Après avoir cliqué, attendez quelques secondes et vérifiez votre email.</p>
        </div>
    </div>
</body>
</html>
