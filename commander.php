<?php
ob_start();
header('Content-Type: application/json; charset=UTF-8');

// Bloquer tout accès non-POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Méthode non autorisée']);
    exit;
}

// Charger les variables d'environnement
$env = parse_ini_file(__DIR__ . '/envprod');
if (!$env) {
    http_response_code(500);
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Erreur de configuration serveur']);
    exit;
}

// Validation et nettoyage des entrées — coordonnées
$civilite     = in_array($_POST['civilite'] ?? '', ['Madame', 'Monsieur']) ? $_POST['civilite'] : '';
$prenom       = trim(strip_tags($_POST['prenom'] ?? ''));
$nom          = trim(strip_tags($_POST['nom'] ?? ''));
$email        = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
$telephone    = trim(strip_tags($_POST['telephone'] ?? ''));
$adresse      = trim(strip_tags($_POST['adresse'] ?? ''));
$code_postal  = trim(strip_tags($_POST['code_postal'] ?? ''));
$ville        = trim(strip_tags($_POST['ville'] ?? ''));
$instructions = trim(strip_tags($_POST['instructions'] ?? ''));

// Vérification des champs obligatoires
if (!$prenom || !$nom || !$email || !$telephone || !$adresse || !$code_postal || !$ville) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Veuillez remplir tous les champs obligatoires.']);
    exit;
}

// Validation et nettoyage des entrées — produits (tableau JSON)
$produitsRaw = $_POST['produits'] ?? '';
$produitsInput = json_decode($produitsRaw, true);

if (!is_array($produitsInput) || empty($produitsInput)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Veuillez sélectionner au moins un produit.']);
    exit;
}

// Nettoyer et valider chaque ligne
$produitsValides = [];
foreach ($produitsInput as $ligne) {
    $pid = filter_var($ligne['produit_id'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $qte = filter_var($ligne['quantite']   ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10]]);
    if (!$pid || !$qte) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Données produit invalides.']);
        exit;
    }
    $produitsValides[] = ['produit_id' => $pid, 'quantite' => $qte];
}

try {
    $dsn = 'mysql:host=' . $env['DB_HOST'] . ';dbname=' . $env['DB_NAME'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASS'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // Création automatique de la table si elle n'existe pas
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS commandes (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            date_commande DATETIME DEFAULT CURRENT_TIMESTAMP,
            donnees       JSON NOT NULL,
            statut        VARCHAR(50) DEFAULT 'nouvelle'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    // Vérifier chaque produit côté serveur (stock + prix officiels)
    $stmtP = $pdo->prepare("SELECT id, nom, volume_ml, prix, stock FROM produits WHERE id = :id AND actif = 1");
    $lignesCommande = [];
    $prix_total = 0;

    foreach ($produitsValides as $ligne) {
        $stmtP->execute([':id' => $ligne['produit_id']]);
        $produit = $stmtP->fetch();

        if (!$produit) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Produit #' . $ligne['produit_id'] . ' introuvable.']);
            exit;
        }
        if ($produit['stock'] <= 0) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => $produit['nom'] . ' est actuellement épuisé.']);
            exit;
        }
        if ($produit['stock'] < $ligne['quantite']) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Stock insuffisant pour ' . $produit['nom'] . '. Il reste ' . $produit['stock'] . ' unité(s).']);
            exit;
        }

        $pu = (float) $produit['prix'];
        $lignesCommande[] = [
            'produit_id'    => $produit['id'],
            'produit_nom'   => $produit['nom'],
            'volume_ml'     => $produit['volume_ml'],
            'quantite'      => $ligne['quantite'],
            'prix_unitaire' => $pu,
            'sous_total'    => round($pu * $ligne['quantite'], 2),
        ];
        $prix_total += $pu * $ligne['quantite'];
    }
    $prix_total = round($prix_total, 2);

    // Regroupement de toutes les données en JSON
    $donnees = json_encode([
        'civilite'     => $civilite,
        'prenom'       => $prenom,
        'nom'          => $nom,
        'email'        => $email,
        'telephone'    => $telephone,
        'adresse'      => $adresse,
        'code_postal'  => $code_postal,
        'ville'        => $ville,
        'instructions' => $instructions,
        'lignes'       => $lignesCommande,
        'prix_total'   => $prix_total,
    ], JSON_UNESCAPED_UNICODE);

    // Insertion de la commande
    $stmt = $pdo->prepare("
        INSERT INTO commandes (donnees) VALUES (:donnees)
    ");

    $stmt->execute([
        ':donnees' => $donnees,
    ]);

    $newId = $pdo->lastInsertId();

    // Décrémenter le stock pour chaque produit
    $stmtStock = $pdo->prepare("UPDATE produits SET stock = stock - :qte WHERE id = :id");
    foreach ($lignesCommande as $ligne) {
        $stmtStock->execute([':qte' => $ligne['quantite'], ':id' => $ligne['produit_id']]);
    }

    // Envoi email via mail() natif PHP (pas de connexion SMTP externe, fonctionne sur OVH)
    $lignesHtml = '';
    foreach ($lignesCommande as $l) {
        $lignesHtml .= '<tr>
            <td style="padding:8px 12px;border-bottom:1px solid #ECF0E5;">' . htmlspecialchars($l['produit_nom']) . '</td>
            <td style="padding:8px 12px;border-bottom:1px solid #ECF0E5;text-align:center;">' . (int)$l['quantite'] . '</td>
            <td style="padding:8px 12px;border-bottom:1px solid #ECF0E5;text-align:right;">' . number_format($l['prix_unitaire'], 2) . ' DT</td>
            <td style="padding:8px 12px;border-bottom:1px solid #ECF0E5;text-align:right;font-weight:bold;">' . number_format($l['sous_total'], 2) . ' DT</td>
        </tr>';
    }
    $mailBody = '
    <div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;color:#2C3A2F;">
      <div style="background:#2F4B3C;padding:20px 24px;border-radius:8px 8px 0 0;">
        <h1 style="color:#fff;margin:0;font-size:1.3rem;">Nouvelle commande #' . $newId . '</h1>
      </div>
      <div style="background:#fff;padding:24px;border:1px solid #E2E9DA;">
        <h2 style="font-size:1rem;color:#4A735C;margin-bottom:12px;">Client</h2>
        <table style="width:100%;border-collapse:collapse;margin-bottom:20px;">
          <tr><td style="padding:5px 0;color:#7D8F76;width:130px;">Nom</td><td><strong>' . htmlspecialchars($civilite . ' ' . $prenom . ' ' . $nom) . '</strong></td></tr>
          <tr><td style="padding:5px 0;color:#7D8F76;">Telephone</td><td>' . htmlspecialchars($telephone) . '</td></tr>
          <tr><td style="padding:5px 0;color:#7D8F76;">Email</td><td>' . htmlspecialchars($email) . '</td></tr>
          <tr><td style="padding:5px 0;color:#7D8F76;">Adresse</td><td>' . htmlspecialchars($adresse . ', ' . $code_postal . ' ' . $ville) . '</td></tr>
          ' . ($instructions ? '<tr><td style="padding:5px 0;color:#7D8F76;">Instructions</td><td>' . htmlspecialchars($instructions) . '</td></tr>' : '') . '
        </table>
        <h2 style="font-size:1rem;color:#4A735C;margin-bottom:12px;">Produits commandes</h2>
        <table style="width:100%;border-collapse:collapse;">
          <thead><tr style="background:#F4F7F1;">
            <th style="padding:8px 12px;text-align:left;font-size:0.8rem;color:#7D8F76;">Produit</th>
            <th style="padding:8px 12px;text-align:center;font-size:0.8rem;color:#7D8F76;">Qte</th>
            <th style="padding:8px 12px;text-align:right;font-size:0.8rem;color:#7D8F76;">P.U.</th>
            <th style="padding:8px 12px;text-align:right;font-size:0.8rem;color:#7D8F76;">Sous-total</th>
          </tr></thead>
          <tbody>' . $lignesHtml . '</tbody>
          <tfoot><tr style="background:#FFFBF0;">
            <td colspan="3" style="padding:12px;font-weight:bold;font-size:1rem;">TOTAL TTC</td>
            <td style="padding:12px;font-weight:bold;font-size:1.1rem;text-align:right;color:#2F4B3C;">' . number_format($prix_total, 2) . ' DT</td>
          </tr></tfoot>
        </table>
      </div>
      <div style="background:#F4F7F1;padding:12px 24px;border-radius:0 0 8px 8px;font-size:0.75rem;color:#92A389;text-align:center;">
        ORAVIE - oravie.tn
      </div>
    </div>';

    $mailSubject = '=?UTF-8?B?' . base64_encode('Nouvelle commande #' . $newId . ' - ' . $prenom . ' ' . $nom) . '?=';

    // Envoi PHPMailer SMTP (confirmé fonctionnel sur OVH)
    try {
        require_once __DIR__ . '/vendor/phpmailer/Exception.php';
        require_once __DIR__ . '/vendor/phpmailer/PHPMailer.php';
        require_once __DIR__ . '/vendor/phpmailer/SMTP.php';
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Timeout    = 5;
        $mail->Host       = $env['MAIL_HOST'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $env['MAIL_USER'];
        $mail->Password   = $env['MAIL_PASS'];
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = (int) $env['MAIL_PORT'];
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom($env['MAIL_FROM'], $env['MAIL_FROM_NAME']);
        $mail->addAddress($env['MAIL_TO']);
        if (!empty($env['MAIL_CC'])) {
            $mail->addCC($env['MAIL_CC']);
        }
        $mail->Subject = 'Nouvelle commande #' . $newId . ' - ' . $prenom . ' ' . $nom;
        $mail->isHTML(true);
        $mail->Body    = $mailBody;
        $mail->AltBody = "Nouvelle commande #$newId\nClient : $civilite $prenom $nom\nTel : $telephone\nAdresse : $adresse, $code_postal $ville\nTotal : " . number_format($prix_total, 2) . " DT";
        $mail->send();
    } catch (Exception $e) {
        // Silencieux — la commande est déjà enregistrée
    }

    // ── EMAIL CONFIRMATION CLIENT ────────────────────────────────────────────
    if ($email) {
        $clientBody = '
        <div style="font-family:Arial,sans-serif;max-width:600px;margin:0 auto;color:#2C3A2F;">
          <div style="background:#2F4B3C;padding:28px 24px;border-radius:8px 8px 0 0;text-align:center;">
            <h1 style="color:#fff;margin:0;font-size:1.4rem;letter-spacing:1px;">ORAVIE</h1>
            <p style="color:#A0C4A8;margin:6px 0 0;font-size:0.85rem;letter-spacing:2px;">SOIN NATUREL</p>
          </div>
          <div style="background:#fff;padding:28px 24px;border:1px solid #E2E9DA;">
            <p style="font-size:1rem;margin-bottom:20px;">Bonjour <strong>' . htmlspecialchars($civilite . ' ' . $prenom . ' ' . $nom) . '</strong>,</p>
            <p style="color:#4A735C;font-size:0.95rem;margin-bottom:20px;">
              Merci pour votre commande ! Nous avons bien reçu votre demande et nous vous contacterons très prochainement pour confirmer la livraison.
            </p>

            <div style="background:#F4F7F1;border-radius:8px;padding:16px 20px;margin-bottom:20px;">
              <p style="margin:0 0 12px;font-weight:700;color:#2F4B3C;">Récapitulatif de votre commande du ' . date('d/m/Y') . ' &mdash; #' . $newId . '</p>
              <table style="width:100%;border-collapse:collapse;">
                <thead><tr style="background:#E2EDD9;">
                  <th style="padding:7px 10px;text-align:left;font-size:0.78rem;color:#7D8F76;">Produit</th>
                  <th style="padding:7px 10px;text-align:center;font-size:0.78rem;color:#7D8F76;">Qté</th>
                  <th style="padding:7px 10px;text-align:right;font-size:0.78rem;color:#7D8F76;">Prix</th>
                </tr></thead>
                <tbody>' . implode('', array_map(fn($l) =>
                  '<tr>
                    <td style="padding:8px 10px;border-bottom:1px solid #ECF0E5;">' . htmlspecialchars($l['produit_nom']) . '</td>
                    <td style="padding:8px 10px;border-bottom:1px solid #ECF0E5;text-align:center;">' . (int)$l['quantite'] . '</td>
                    <td style="padding:8px 10px;border-bottom:1px solid #ECF0E5;text-align:right;">' . number_format($l['sous_total'], 2) . ' DT</td>
                  </tr>', $lignesCommande)) . '
                </tbody>
                <tfoot><tr style="background:#FFFBF0;">
                  <td colspan="2" style="padding:10px;font-weight:700;">TOTAL</td>
                  <td style="padding:10px;font-weight:800;text-align:right;color:#2F4B3C;">' . number_format($prix_total, 2) . ' DT</td>
                </tr></tfoot>
              </table>
            </div>

            <div style="background:#F4F7F1;border-radius:8px;padding:14px 20px;margin-bottom:20px;">
              <p style="margin:0 0 6px;font-weight:700;font-size:0.9rem;color:#2F4B3C;">Adresse de livraison</p>
              <p style="margin:0;font-size:0.88rem;color:#4A735C;">
                ' . htmlspecialchars($adresse) . '<br>
                ' . htmlspecialchars($code_postal . ' ' . $ville) . '
              </p>
            </div>

            <p style="font-size:0.85rem;color:#7D8F76;margin-bottom:4px;">Pour toute question, contactez-nous :</p>
            <p style="font-size:0.9rem;margin:0;"><a href="mailto:contact@oravie.tn" style="color:#4A735C;font-weight:700;">contact@oravie.tn</a></p>
          </div>
          <div style="background:#F4F7F1;padding:12px 24px;border-radius:0 0 8px 8px;font-size:0.75rem;color:#92A389;text-align:center;">
            ORAVIE · oravie.tn
          </div>
        </div>';

        try {
            require_once __DIR__ . '/vendor/phpmailer/Exception.php';
            require_once __DIR__ . '/vendor/phpmailer/PHPMailer.php';
            require_once __DIR__ . '/vendor/phpmailer/SMTP.php';
            $mailClient = new PHPMailer\PHPMailer\PHPMailer(true);
            $mailClient->isSMTP();
            $mailClient->Timeout    = 5;
            $mailClient->Host       = $env['MAIL_HOST'];
            $mailClient->SMTPAuth   = true;
            $mailClient->Username   = $env['MAIL_USER'];
            $mailClient->Password   = $env['MAIL_PASS'];
            $mailClient->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            $mailClient->Port       = (int) $env['MAIL_PORT'];
            $mailClient->CharSet    = 'UTF-8';
            $mailClient->setFrom('contact@oravie.tn', 'ORAVIE');
            $mailClient->addAddress($email, $prenom . ' ' . $nom);
            $mailClient->Subject = 'Votre commande ORAVIE #' . $newId . ' — Confirmation';
            $mailClient->isHTML(true);
            $mailClient->Body    = $clientBody;
            $mailClient->AltBody = "Bonjour $prenom $nom,\n\nMerci pour votre commande #$newId.\nTotal : " . number_format($prix_total, 2) . " DT\n\nNous vous contacterons prochainement.\n\nORAVIE\ncontact@oravie.tn";
            $mailClient->send();
        } catch (Exception $e) {
            // Silencieux — la commande est déjà enregistrée
        }
    }

    ob_clean();
    echo json_encode([
        'success'    => true,
        'message'    => 'Commande enregistr\u00e9e avec succ\u00e8s !',
        'id'         => $newId,
        'prix_total' => $prix_total,
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    ob_clean();
    echo json_encode(['success' => false, 'message' => 'Erreur lors de l\'enregistrement. Veuillez réessayer.']);
}
