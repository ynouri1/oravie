<?php
/**
 * Page admin : Historique d'envoi des emails de feedback
 * Accès sécurisé par authentification admin
 */

require_once 'auth.php';
requireAuth();

$pdo = getDB();

// Traiter l'action d'envoi des emails
$send_message = '';
$send_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_single_email') {
    try {
        // Vérifier le token CSRF
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token'] ?? null) {
            throw new Exception('Token de sécurité invalide');
        }

        $cmd_id = (int)($_POST['cmd_id'] ?? 0);
        if ($cmd_id <= 0) {
            throw new Exception('ID commande invalide');
        }

        // Charger PHPMailer
        if (!file_exists('../vendor/phpmailer/PHPMailer.php')) {
            throw new Exception('PHPMailer non trouvé');
        }
        require_once '../vendor/phpmailer/PHPMailer.php';
        require_once '../vendor/phpmailer/SMTP.php';
        require_once '../vendor/phpmailer/Exception.php';

        $mailer = new \PHPMailer\PHPMailer\PHPMailer(true);

        // Récupérer la commande
        $stmt = $pdo->prepare("
            SELECT 
                c.id,
                c.donnees,
                c.date_commande,
                c.feedback_email_sent_at
            FROM commandes c
            WHERE c.id = :id AND c.statut = 'livrée'
        ");
        $stmt->execute([':id' => $cmd_id]);
        $cmd = $stmt->fetch();

        if (!$cmd) {
            throw new Exception('Commande introuvable');
        }

        if ($cmd['feedback_email_sent_at']) {
            throw new Exception('Email déjà envoyé pour cette commande');
        }

        // Charger config SMTP depuis envprod
        $env_config = parse_ini_file('../envprod');
        if (!$env_config || !isset($env_config['SMTP_HOST'])) {
            throw new Exception('Configuration SMTP non trouvée dans envprod');
        }

        // Config SMTP
        $mailer->isSMTP();
        $mailer->Host = $env_config['SMTP_HOST'];
        $mailer->SMTPAuth = true;
        $mailer->Username = $env_config['SMTP_USER'];
        $mailer->Password = $env_config['SMTP_PASS'];
        $mailer->SMTPSecure = 'ssl';
        $mailer->Port = (int)$env_config['SMTP_PORT'];
        $mailer->SetFrom($env_config['SMTP_FROM'], 'ORAVIE');
        $mailer->isHTML(true);

        try {
            $donnees = json_decode($cmd['donnees'], true);
            $nom_client = trim(($donnees['prenom'] ?? '') . ' ' . ($donnees['nom'] ?? ''));
            $email = $donnees['email'] ?? '';

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Email invalide pour cette commande');
            }

            // Générer un token unique pour ce client
            $token = bin2hex(random_bytes(16));
            $feedback_url = "https://oravie.tn/feedback.php?id={$cmd['id']}&token={$token}";

            // Email HTML
            $html = "
            <!DOCTYPE html>
            <html>
            <head>
                <meta charset='UTF-8'>
                <style>
                    body { font-family: Arial, sans-serif; background: #f4f7f1; margin: 0; padding: 0; }
                    .email-wrapper { max-width: 600px; margin: 20px auto; background: #ffffff; border-radius: 10px; overflow: hidden; box-shadow: 0 4px 12px rgba(0,0,0,0.1); }
                    .header { background: #2F4B3C; color: #ffffff; padding: 30px; text-align: center; }
                    .header h1 { margin: 0; font-size: 24px; }
                    .content { padding: 30px; }
                    .content p { margin: 0 0 15px 0; line-height: 1.6; color: #333; }
                    .section { margin: 25px 0; padding: 20px; background: #f9faf8; border-left: 4px solid #4A735C; }
                    .section h3 { margin: 0 0 10px 0; color: #2F4B3C; }
                    .cta-button { display: inline-block; background: #4A735C; color: #ffffff; padding: 14px 30px; text-decoration: none; border-radius: 6px; font-weight: bold; margin: 20px 0; }
                    .cta-button:hover { background: #2F4B3C; }
                    .footer { background: #f4f7f1; padding: 20px; text-align: center; font-size: 12px; color: #7D8F76; }
                    .link-text { color: #4A735C; }
                </style>
            </head>
            <body>
                <div class='email-wrapper'>
                    <div class='header'>
                        <h1>🌿 Votre avis nous intéresse !</h1>
                    </div>
                    <div class='content'>
                        <p>Bonjour <strong>{$nom_client}</strong>,</p>
                        <p>Nous espérons que votre récente commande ORAVIE vous a satisfait(e) ! Pour nous aider à améliorer nos services, nous aimerions connaître votre avis sur :</p>
                        <div class='section'>
                            <h3>✨ Votre retour concerne :</h3>
                            <ul>
                                <li>La qualité du produit</li>
                                <li>La qualité de la livraison</li>
                                <li>Votre expérience sur notre site</li>
                                <li>Vos remarques et suggestions</li>
                            </ul>
                        </div>
                        <p style='text-align: center;'>
                            <a href='{$feedback_url}' class='cta-button'>Partager mon avis (2 min)</a>
                        </p>
                        <p>Ou cliquez sur ce lien : <span class='link-text'><a href='{$feedback_url}'>{$feedback_url}</a></span></p>
                        <p style='margin-top: 30px; color: #7D8F76; font-size: 12px;'>Merci de votre confiance ! 🙏</p>
                    </div>
                    <div class='footer'>
                        <p>© 2026 ORAVIE - Tous droits réservés</p>
                    </div>
                </div>
            </body>
            </html>";

            $mailer->clearAddresses();
            $mailer->addAddress($email, $nom_client);
            $mailer->Subject = "ORAVIE - Partagez votre avis sur votre commande";
            $mailer->Body = $html;
            $mailer->AltBody = "Veuillez consulter ce message en HTML";

            if ($mailer->send()) {
                // Mettre à jour le timestamp d'envoi
                $upd = $pdo->prepare("UPDATE commandes SET feedback_email_sent_at = NOW() WHERE id = :id");
                $upd->execute([':id' => $cmd['id']]);
                $send_message = "✅ Email envoyé avec succès à <strong>" . htmlspecialchars($email) . "</strong>";
            } else {
                $send_error = "❌ Erreur lors de l'envoi : " . $mailer->ErrorInfo;
            }
        } catch (Exception $e) {
            $send_error = "❌ Erreur : " . htmlspecialchars($e->getMessage());
        }
    } catch (Exception $e) {
        $send_error = "❌ Erreur : " . htmlspecialchars($e->getMessage());
    }
}

// Générer CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Filtres
$filter_status = $_GET['status'] ?? 'all'; // all, sent, pending
$filter_month = $_GET['month'] ?? date('Y-m');
$search = $_GET['search'] ?? '';

// Construire la requête
$sql = "
    SELECT 
        c.id,
        c.date_commande,
        c.statut,
        c.feedback_email_sent_at,
        c.donnees,
        COUNT(fa.id) as nb_avis
    FROM commandes c
    LEFT JOIN feedback_avis fa ON fa.commande_id = c.id
    WHERE c.statut = 'livrée'
";

if ($filter_status === 'sent') {
    $sql .= " AND c.feedback_email_sent_at IS NOT NULL";
} elseif ($filter_status === 'pending') {
    $sql .= " AND c.feedback_email_sent_at IS NULL AND c.date_commande <= DATE_SUB(NOW(), INTERVAL 2 WEEK)";
}

if ($filter_month) {
    $sql .= " AND DATE_FORMAT(c.date_commande, '%Y-%m') = :month";
}

if ($search) {
    $sql .= " AND (c.id LIKE :search OR c.donnees LIKE :search)";
}

$sql .= " GROUP BY c.id ORDER BY c.date_commande DESC LIMIT 100";

$stmt = $pdo->prepare($sql);

$params = [];
if ($filter_month) $params[':month'] = $filter_month;
if ($search) $params[':search'] = '%' . $search . '%';

$stmt->execute($params);
$commandes = $stmt->fetchAll();

// Stats
$stats = $pdo->query("
    SELECT
        COUNT(*) as total_livrees,
        SUM(CASE WHEN feedback_email_sent_at IS NOT NULL THEN 1 ELSE 0 END) as emails_envoyes,
        SUM(CASE WHEN feedback_email_sent_at IS NULL AND date_commande <= DATE_SUB(NOW(), INTERVAL 2 WEEK) THEN 1 ELSE 0 END) as en_attente,
        SUM(CASE WHEN feedback_email_sent_at IS NULL AND date_commande > DATE_SUB(NOW(), INTERVAL 2 WEEK) THEN 1 ELSE 0 END) as trop_recentes
    FROM commandes
    WHERE statut = 'livrée'
")->fetch();
?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Historique Feedback · ORAVIE Admin</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    <style>
        * { margin:0; padding:0; box-sizing:border-box; }
        body { background:#F4F7F1; font-family:'Segoe UI',sans-serif; color:#2C3A2F; min-height:100vh; }

        nav { background:#2F4B3C; padding:0 2rem; display:flex; align-items:center; justify-content:space-between; height:58px; box-shadow:0 2px 8px rgba(0,0,0,0.15); }
        .nav-brand { color:#fff; font-size:1.1rem; font-weight:700; display:flex; align-items:center; gap:8px; }
        .nav-brand span { font-size:0.68rem; letter-spacing:2px; color:#A0C4A8; text-transform:uppercase; }
        .nav-links { display:flex; gap:0.3rem; align-items:center; }
        .nav-links a { color:#A0C4A8; text-decoration:none; font-size:0.85rem; padding:6px 14px; border-radius:1rem; transition:0.2s; }
        .nav-links a:hover { background:rgba(255,255,255,0.15); color:#fff; }
        .nav-links a.logout { color:#F87171; }

        .main { max-width:1200px; margin:0 auto; padding:2rem 1.5rem; }
        .page-header { display:flex; align-items:center; gap:1rem; margin-bottom:2rem; }
        .page-header h1 { font-size:1.4rem; font-weight:700; }

        .stats-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:1rem; margin-bottom:2rem; }
        .stat-card { background:#fff; border-radius:1rem; padding:1.5rem; box-shadow:0 2px 10px rgba(0,0,0,0.05); border-left:4px solid #4A735C; }
        .stat-card.green { border-color:#10B981; }
        .stat-card.blue { border-color:#2563EB; }
        .stat-card.orange { border-color:#F59E0B; }
        .stat-card.gray { border-color:#6B7280; }
        .stat-val { font-size:2rem; font-weight:800; color:#2F4B3C; }
        .stat-label { font-size:0.75rem; color:#92A389; text-transform:uppercase; font-weight:700; margin-top:4px; }

        .filters { background:#fff; border-radius:1rem; padding:1.5rem; margin-bottom:2rem; box-shadow:0 2px 10px rgba(0,0,0,0.05); display:flex; gap:1rem; flex-wrap:wrap; align-items:flex-end; }
        .filter-group { display:flex; flex-direction:column; gap:0.5rem; }
        .filter-group label { font-size:0.8rem; font-weight:700; color:#7D8F76; text-transform:uppercase; }
        .filter-group input,
        .filter-group select { padding:8px 12px; border:1.5px solid #D4D8CF; border-radius:0.6rem; font-size:0.9rem; }
        .filter-group input:focus,
        .filter-group select:focus { outline:none; border-color:#4A735C; }
        .btn-filter { background:#2F4B3C; color:#fff; border:none; padding:8px 16px; border-radius:0.6rem; cursor:pointer; font-weight:700; font-size:0.9rem; }
        .btn-filter:hover { background:#4A735C; }

        table { width:100%; border-collapse:collapse; background:#fff; border-radius:1rem; overflow:hidden; box-shadow:0 2px 10px rgba(0,0,0,0.05); }
        thead th { background:#F4F7F1; padding:12px; text-align:left; font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#7D8F76; }
        tbody tr { border-top:1px solid #F0F4EC; }
        tbody tr:hover { background:#FAFCF8; }
        td { padding:12px; font-size:0.9rem; }
        .badge { display:inline-block; padding:4px 10px; border-radius:0.6rem; font-size:0.75rem; font-weight:700; }
        .badge-success { background:#D1FAE5; color:#059669; }
        .badge-warning { background:#FEF3C7; color:#D97706; }
        .badge-info { background:#DBEAFE; color:#0284C7; }
        .badge-gray { background:#F3F4F6; color:#6B7280; }

        .empty { text-align:center; padding:3rem; color:#92A389; }

        @media (max-width: 768px) {
            nav { padding:0 1rem; }
            .main { padding:1rem 0.75rem; }
            .filters { flex-direction:column; }
            .filter-group { width:100%; }
            .stats-grid { grid-template-columns:1fr; }
            table { font-size:0.8rem; }
            td, th { padding:8px; }
        }
    </style>
</head>
<body>
    <nav>
        <div class="nav-brand">
            <i class="fas fa-leaf"></i>
            ORAVIE Admin
            <span>Feedback Emails</span>
        </div>
        <div class="nav-links">
            <a href="dashboard.php"><i class="fas fa-list-alt"></i> Commandes</a>
            <a href="feedback_avis.php"><i class="fas fa-comments"></i> Avis clients</a>
            <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Déconnexion</a>
        </div>
    </nav>

    <div class="main">
        <div class="page-header">
            <h1><i class="fas fa-envelope"></i> Historique Emails Feedback</h1>
        </div>

        <!-- Messages -->
        <?php if ($send_message): ?>
            <div style="background:#D1FAE5; border:2px solid #10B981; color:#059669; padding:1rem; border-radius:0.6rem; margin-bottom:1.5rem;">
                <i class="fas fa-check-circle"></i> <?= $send_message ?>
            </div>
        <?php endif; ?>
        <?php if ($send_error): ?>
            <div style="background:#FEE2E2; border:2px solid #EF4444; color:#DC2626; padding:1rem; border-radius:0.6rem; margin-bottom:1.5rem;">
                <i class="fas fa-exclamation-circle"></i> <?= $send_error ?>
            </div>
        <?php endif; ?>

        <!-- Stats -->
        <div class="stats-grid">
            <div class="stat-card green">
                <div class="stat-val"><?= $stats['emails_envoyes'] ?? 0 ?></div>
                <div class="stat-label">Emails envoyés</div>
            </div>
            <div class="stat-card orange">
                <div class="stat-val"><?= $stats['en_attente'] ?? 0 ?></div>
                <div class="stat-label">En attente (> 2 sem.)</div>
            </div>
            <div class="stat-card blue">
                <div class="stat-val"><?= $stats['trop_recentes'] ?? 0 ?></div>
                <div class="stat-label">Trop récentes (< 2 sem.)</div>
            </div>
            <div class="stat-card gray">
                <div class="stat-val"><?= $stats['total_livrees'] ?? 0 ?></div>
                <div class="stat-label">Total livrées</div>
            </div>
        </div>

        <!-- Filtres -->
        <div class="filters">
            <form method="GET" style="display:flex; gap:1rem; flex-wrap:wrap; align-items:flex-end; width:100%;">
                <div class="filter-group">
                    <label>Statut Email</label>
                    <select name="status">
                        <option value="all" <?= $filter_status === 'all' ? 'selected' : '' ?>>Tous</option>
                        <option value="sent" <?= $filter_status === 'sent' ? 'selected' : '' ?>>✅ Envoyés</option>
                        <option value="pending" <?= $filter_status === 'pending' ? 'selected' : '' ?>>⏳ En attente</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Mois</label>
                    <input type="month" name="month" value="<?= htmlspecialchars($filter_month) ?>">
                </div>
                <div class="filter-group" style="flex:1; min-width:200px;">
                    <label>Rechercher (ID ou email)</label>
                    <input type="text" name="search" placeholder="Commande ID ou email client" value="<?= htmlspecialchars($search) ?>">
                </div>
                <button type="submit" class="btn-filter"><i class="fas fa-search"></i> Filtrer</button>
                <a href="?" class="btn-filter" style="background:#92A389;"><i class="fas fa-redo"></i> Réinitialiser</a>
            </form>
        </div>

        <!-- Tableau -->
        <?php if (empty($commandes)): ?>
            <div class="empty">
                <i class="fas fa-inbox" style="font-size:3rem; margin-bottom:1rem; opacity:0.5;"></i>
                <p>Aucune commande trouvée avec les filtres appliqués.</p>
            </div>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Commande ID</th>
                        <th>Date Livraison</th>
                        <th>Client</th>
                        <th>Email</th>
                        <th>Statut Email</th>
                        <th>Date Envoi</th>
                        <th>Avis Reçus</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($commandes as $cmd): 
                        $donnees = json_decode($cmd['donnees'], true);
                        $nom = trim(($donnees['prenom'] ?? '') . ' ' . ($donnees['nom'] ?? ''));
                        $email = $donnees['email'] ?? '';
                        $sent_at = $cmd['feedback_email_sent_at'];
                    ?>
                        <tr>
                            <td><strong>#<?= $cmd['id'] ?></strong></td>
                            <td><?= date('d/m/Y', strtotime($cmd['date_commande'])) ?></td>
                            <td><?= htmlspecialchars($nom) ?></td>
                            <td><?= htmlspecialchars($email) ?></td>
                            <td>
                                <?php if ($sent_at): ?>
                                    <span class="badge badge-success">✅ Envoyé</span>
                                <?php elseif (strtotime($cmd['date_commande']) <= strtotime('-2 weeks')): ?>
                                    <span class="badge badge-warning">⏳ En attente</span>
                                <?php else: ?>
                                    <span class="badge badge-info">⏱️ Pas encore 2 sem.</span>
                                <?php endif; ?>
                            </td>
                            <td><?= $sent_at ? date('d/m/Y H:i', strtotime($sent_at)) : '-' ?></td>
                            <td>
                                <?php if ($cmd['nb_avis'] > 0): ?>
                                    <span class="badge badge-success">✅ <?= $cmd['nb_avis'] ?></span>
                                <?php else: ?>
                                    <span class="badge badge-gray">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!$sent_at): ?>
                                    <form method="POST" style="display:inline;">
                                        <input type="hidden" name="action" value="send_single_email">
                                        <input type="hidden" name="cmd_id" value="<?= $cmd['id'] ?>">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <button type="submit" style="background:#10B981; color:white; border:none; padding:6px 12px; border-radius:0.4rem; cursor:pointer; font-size:0.85rem; font-weight:600;" onclick="return confirm('Envoyer un email à <?= htmlspecialchars($email) ?> ?')">
                                            <i class="fas fa-paper-plane"></i> Envoyer
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span style="color:#92A389; font-size:0.85rem;">Déjà envoyé</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</body>
</html>
