<?php
/**
 * Page admin : Historique d'envoi des emails de feedback
 * Accès sécurisé par authentification admin
 */

require_once 'auth.php';
requireAuth();

$pdo = getDB();

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
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</body>
</html>
