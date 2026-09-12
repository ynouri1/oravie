<?php
/**
 * Page de collecte d'avis clients
 * Accédée via lien dans l'email de feedback
 * URL : feedback.php?id=COMMANDE_ID&token=TOKEN
 */

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

    $cmd_id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
    $token = $_GET['token'] ?? '';

    $success = false;
    $error = '';
    $commande = null;

    if ($cmd_id && $token) {
        // Charger la commande
        $stmt = $pdo->prepare("SELECT id, donnees, date_commande FROM commandes WHERE id = :id");
        $stmt->execute([':id' => $cmd_id]);
        $commande = $stmt->fetch();

        if (!$commande) {
            $error = 'Commande introuvable.';
        }
    } else {
        $error = 'Paramètres manquants ou invalides.';
    }

    // Traiter la soumission du formulaire
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $commande && !$error) {
        $avis_produit = $_POST['avis_produit'] ?? '';
        $avis_livraison = $_POST['avis_livraison'] ?? '';
        $avis_site = $_POST['avis_site'] ?? '';
        $avis_general = $_POST['avis_general'] ?? '';
        $remarques = trim($_POST['remarques'] ?? '');
        $ameliorations = trim($_POST['ameliorations'] ?? '');
        $nom_client = trim($_POST['nom_client'] ?? '');
        $email_client = trim($_POST['email_client'] ?? '');

        if (!filter_var($email_client, FILTER_VALIDATE_EMAIL)) {
            $error = 'Email invalide.';
        } else {
            // Valider les champs requis
            if (!$avis_produit || !$avis_livraison || !$avis_site || !$avis_general) {
                $error = 'Veuillez évaluer tous les domaines.';
            } else {
                // Créer la table feedback_avis si elle n'existe pas
                try {
                    $pdo->exec("
                        CREATE TABLE IF NOT EXISTS feedback_avis (
                            id INT AUTO_INCREMENT PRIMARY KEY,
                            commande_id INT NOT NULL,
                            nom_client VARCHAR(150),
                            email_client VARCHAR(150),
                            avis_produit VARCHAR(50),
                            avis_livraison VARCHAR(50),
                            avis_site VARCHAR(50),
                            avis_general VARCHAR(50),
                            remarques TEXT,
                            ameliorations TEXT,
                            date_submission DATETIME DEFAULT CURRENT_TIMESTAMP,
                            FOREIGN KEY (commande_id) REFERENCES commandes(id) ON DELETE CASCADE
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
                    ");
                } catch (Exception $e) {
                    // Table existe déjà
                }

                // Insérer l'avis
                $stmt = $pdo->prepare("
                    INSERT INTO feedback_avis (commande_id, nom_client, email_client, avis_produit, avis_livraison, avis_site, avis_general, remarques, ameliorations)
                    VALUES (:cmd_id, :nom, :email, :prod, :liv, :site, :gen, :rem, :amel)
                ");

                $stmt->execute([
                    ':cmd_id' => $cmd_id,
                    ':nom' => $nom_client,
                    ':email' => $email_client,
                    ':prod' => $avis_produit,
                    ':liv' => $avis_livraison,
                    ':site' => $avis_site,
                    ':gen' => $avis_general,
                    ':rem' => $remarques,
                    ':amel' => $ameliorations,
                ]);

                $success = true;
            }
        }
    }

    if ($commande) {
        $donnees = json_decode($commande['donnees'], true);
        $nom_client = trim(($donnees['prenom'] ?? '') . ' ' . ($donnees['nom'] ?? ''));
        $email_client = $donnees['email'] ?? '';
    }

} catch (PDOException $e) {
    $error = 'Erreur base de données : ' . $e->getMessage();
}
?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Votre avis ORAVIE</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', sans-serif;
            background: linear-gradient(135deg, #2F4B3C 0%, #4A735C 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #2C3A2F;
        }

        .container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.15);
            max-width: 700px;
            width: 90%;
            padding: 40px;
            animation: slideIn 0.3s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .header {
            text-align: center;
            margin-bottom: 30px;
        }

        .header h1 {
            font-size: 1.8rem;
            color: #2F4B3C;
            margin-bottom: 10px;
        }

        .header p {
            color: #7D8F76;
            font-size: 0.95rem;
        }

        .alert {
            margin-bottom: 20px;
            padding: 15px;
            border-radius: 8px;
            font-size: 0.95rem;
        }

        .alert-error {
            background: #FEE2E2;
            color: #DC2626;
            border: 1px solid #FECACA;
        }

        .alert-success {
            background: #D1FAE5;
            color: #065F46;
            border: 1px solid #A7F3D0;
        }

        .success-message {
            text-align: center;
        }

        .success-message h2 {
            color: #065F46;
            margin-bottom: 15px;
        }

        .success-message p {
            color: #047857;
            line-height: 1.6;
            margin-bottom: 10px;
        }

        .btn-home {
            display: inline-block;
            background: #2F4B3C;
            color: white;
            padding: 12px 30px;
            border-radius: 6px;
            text-decoration: none;
            margin-top: 20px;
            font-weight: 600;
            transition: 0.2s;
        }

        .btn-home:hover {
            background: #4A735C;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2F4B3C;
            font-size: 0.95rem;
        }

        .form-group input[type="text"],
        .form-group input[type="email"],
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 10px 12px;
            border: 1.5px solid #D4D8CF;
            border-radius: 6px;
            font-family: inherit;
            font-size: 0.95rem;
            transition: 0.2s;
        }

        .form-group input[type="text"]:focus,
        .form-group input[type="email"]:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: #4A735C;
            box-shadow: 0 0 0 3px rgba(74, 115, 92, 0.1);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }

        @media (max-width: 600px) {
            .form-row {
                grid-template-columns: 1fr;
            }
            .container {
                padding: 25px;
            }
            .header h1 {
                font-size: 1.4rem;
            }
        }

        .rating-group {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .rating-btn {
            flex: 1;
            min-width: 60px;
            padding: 10px;
            border: 2px solid #D4D8CF;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 600;
            transition: 0.2s;
            text-align: center;
            font-size: 0.9rem;
        }

        .rating-btn:hover {
            border-color: #4A735C;
            background: #f0f4ed;
        }

        .rating-btn input {
            display: none;
        }

        .rating-btn input:checked + label {
            background: #4A735C;
            color: white;
            cursor: pointer;
        }

        .rating-wrapper {
            position: relative;
        }

        .rating-wrapper input[type="radio"] {
            position: absolute;
            opacity: 0;
        }

        .rating-labels {
            display: flex;
            gap: 8px;
        }

        .rating-label {
            flex: 1;
            padding: 8px;
            text-align: center;
            border: 2px solid #D4D8CF;
            border-radius: 6px;
            cursor: pointer;
            transition: 0.2s;
            font-size: 0.85rem;
        }

        input[type="radio"]:checked + .rating-label {
            background: #4A735C;
            color: white;
            border-color: #4A735C;
        }

        .rating-label:hover {
            border-color: #4A735C;
        }

        .btn-submit {
            width: 100%;
            padding: 12px;
            background: #2F4B3C;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 1rem;
            font-weight: 700;
            cursor: pointer;
            transition: 0.2s;
        }

        .btn-submit:hover {
            background: #4A735C;
        }

        .required {
            color: #DC2626;
        }

        .section-title {
            font-size: 1.1rem;
            color: #2F4B3C;
            font-weight: 700;
            margin: 25px 0 15px 0;
            padding-top: 15px;
            border-top: 1px solid #E2E9DA;
        }

        .info-box {
            background: #f9faf8;
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 0.9rem;
            color: #4A735C;
        }

        .info-box strong {
            color: #2F4B3C;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($success): ?>
            <div class="alert alert-success success-message">
                <h2>✅ Merci pour votre avis !</h2>
                <p>Votre feedback a été enregistré avec succès.</p>
                <p>Nous lisons attentivement chaque retour d'expérience pour nous améliorer.</p>
                <a href="https://www.oravie.tn" class="btn-home">Retour au site ORAVIE</a>
            </div>

        <?php elseif ($error): ?>
            <div class="alert alert-error">
                <strong>❌ Erreur :</strong> <?= htmlspecialchars($error) ?>
            </div>
            <p style="text-align: center; margin-top: 20px;">
                <a href="https://www.oravie.tn" style="color: #4A735C; text-decoration: underline;">Retour au site</a>
            </p>

        <?php elseif ($commande): ?>
            <div class="header">
                <h1>Votre avis nous importe ! 🌿</h1>
                <p>Partagez votre expérience avec ORAVIE</p>
            </div>

            <div class="info-box">
                <strong>Commande #<?= htmlspecialchars($cmd_id) ?></strong><br>
                Passée le <?= date('d/m/Y', strtotime($commande['date_commande'])) ?>
            </div>

            <form method="POST">
                <!-- Informations client -->
                <div class="section-title">📝 Vos informations</div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="nom_client">Nom complet <span class="required">*</span></label>
                        <input type="text" id="nom_client" name="nom_client" value="<?= htmlspecialchars($nom_client) ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="email_client">Email <span class="required">*</span></label>
                        <input type="email" id="email_client" name="email_client" value="<?= htmlspecialchars($email_client) ?>" required>
                    </div>
                </div>

                <!-- Évaluations -->
                <div class="section-title">⭐ Évaluations</div>

                <!-- Produit -->
                <div class="form-group">
                    <label for="avis_produit">Qualité du produit <span class="required">*</span></label>
                    <div class="rating-labels">
                        <input type="radio" id="prod_1" name="avis_produit" value="Très mauvais" required>
                        <label for="prod_1" class="rating-label">😞<br>Très mauvais</label>

                        <input type="radio" id="prod_2" name="avis_produit" value="Mauvais">
                        <label for="prod_2" class="rating-label">😕<br>Mauvais</label>

                        <input type="radio" id="prod_3" name="avis_produit" value="Moyen">
                        <label for="prod_3" class="rating-label">😐<br>Moyen</label>

                        <input type="radio" id="prod_4" name="avis_produit" value="Bon">
                        <label for="prod_4" class="rating-label">😊<br>Bon</label>

                        <input type="radio" id="prod_5" name="avis_produit" value="Excellent">
                        <label for="prod_5" class="rating-label">😍<br>Excellent</label>
                    </div>
                </div>

                <!-- Livraison -->
                <div class="form-group">
                    <label for="avis_livraison">Qualité de la livraison <span class="required">*</span></label>
                    <div class="rating-labels">
                        <input type="radio" id="liv_1" name="avis_livraison" value="Très mauvais" required>
                        <label for="liv_1" class="rating-label">😞<br>Très mauvais</label>

                        <input type="radio" id="liv_2" name="avis_livraison" value="Mauvais">
                        <label for="liv_2" class="rating-label">😕<br>Mauvais</label>

                        <input type="radio" id="liv_3" name="avis_livraison" value="Moyen">
                        <label for="liv_3" class="rating-label">😐<br>Moyen</label>

                        <input type="radio" id="liv_4" name="avis_livraison" value="Bon">
                        <label for="liv_4" class="rating-label">😊<br>Bon</label>

                        <input type="radio" id="liv_5" name="avis_livraison" value="Excellent">
                        <label for="liv_5" class="rating-label">😍<br>Excellent</label>
                    </div>
                </div>

                <!-- Site -->
                <div class="form-group">
                    <label for="avis_site">Facilité d'utilisation du site <span class="required">*</span></label>
                    <div class="rating-labels">
                        <input type="radio" id="site_1" name="avis_site" value="Très mauvais" required>
                        <label for="site_1" class="rating-label">😞<br>Très mauvais</label>

                        <input type="radio" id="site_2" name="avis_site" value="Mauvais">
                        <label for="site_2" class="rating-label">😕<br>Mauvais</label>

                        <input type="radio" id="site_3" name="avis_site" value="Moyen">
                        <label for="site_3" class="rating-label">😐<br>Moyen</label>

                        <input type="radio" id="site_4" name="avis_site" value="Bon">
                        <label for="site_4" class="rating-label">😊<br>Bon</label>

                        <input type="radio" id="site_5" name="avis_site" value="Excellent">
                        <label for="site_5" class="rating-label">😍<br>Excellent</label>
                    </div>
                </div>

                <!-- Avis général -->
                <div class="form-group">
                    <label for="avis_general">Avis général <span class="required">*</span></label>
                    <div class="rating-labels">
                        <input type="radio" id="gen_1" name="avis_general" value="Très mauvais" required>
                        <label for="gen_1" class="rating-label">😞<br>Très mauvais</label>

                        <input type="radio" id="gen_2" name="avis_general" value="Mauvais">
                        <label for="gen_2" class="rating-label">😕<br>Mauvais</label>

                        <input type="radio" id="gen_3" name="avis_general" value="Moyen">
                        <label for="gen_3" class="rating-label">😐<br>Moyen</label>

                        <input type="radio" id="gen_4" name="avis_general" value="Bon">
                        <label for="gen_4" class="rating-label">😊<br>Bon</label>

                        <input type="radio" id="gen_5" name="avis_general" value="Excellent">
                        <label for="gen_5" class="rating-label">😍<br>Excellent</label>
                    </div>
                </div>

                <!-- Commentaires -->
                <div class="section-title">💬 Commentaires libres</div>

                <div class="form-group">
                    <label for="remarques">Avez-vous des remarques à nous faire ?</label>
                    <textarea id="remarques" name="remarques" placeholder="Vos observations..."></textarea>
                </div>

                <div class="form-group">
                    <label for="ameliorations">Comment pourrions-nous nous améliorer ?</label>
                    <textarea id="ameliorations" name="ameliorations" placeholder="Vos suggestions..."></textarea>
                </div>

                <button type="submit" class="btn-submit">📧 Envoyer mon avis</button>
            </form>

        <?php endif; ?>
    </div>
</body>
</html>
