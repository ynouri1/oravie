<?php
/**
 * Page admin : Visualiser les retours d'avis clients
 * Accès sécurisé par authentification
 */

require_once 'auth.php';
requireAuth();

$pdo = getDB();

// Créer la table feedback_avis si elle n'existe pas (au cas où)
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

// Stats sur les avis
$stats = $pdo->query("
    SELECT
        COUNT(*) AS total,
        AVG(CASE WHEN avis_produit = 'Excellent' THEN 5 
                 WHEN avis_produit = 'Bon' THEN 4 
                 WHEN avis_produit = 'Moyen' THEN 3 
                 WHEN avis_produit = 'Mauvais' THEN 2 
                 WHEN avis_produit = 'Très mauvais' THEN 1 END) AS note_produit,
        AVG(CASE WHEN avis_livraison = 'Excellent' THEN 5 
                 WHEN avis_livraison = 'Bon' THEN 4 
                 WHEN avis_livraison = 'Moyen' THEN 3 
                 WHEN avis_livraison = 'Mauvais' THEN 2 
                 WHEN avis_livraison = 'Très mauvais' THEN 1 END) AS note_livraison,
        AVG(CASE WHEN avis_site = 'Excellent' THEN 5 
                 WHEN avis_site = 'Bon' THEN 4 
                 WHEN avis_site = 'Moyen' THEN 3 
                 WHEN avis_site = 'Mauvais' THEN 2 
                 WHEN avis_site = 'Très mauvais' THEN 1 END) AS note_site,
        AVG(CASE WHEN avis_general = 'Excellent' THEN 5 
                 WHEN avis_general = 'Bon' THEN 4 
                 WHEN avis_general = 'Moyen' THEN 3 
                 WHEN avis_general = 'Mauvais' THEN 2 
                 WHEN avis_general = 'Très mauvais' THEN 1 END) AS note_general
    FROM feedback_avis
")->fetch();

// Distribution des avis
$distribution = $pdo->query("
    SELECT 
        avis_produit,
        COUNT(*) as cnt
    FROM feedback_avis
    GROUP BY avis_produit
    ORDER BY FIELD(avis_produit, 'Excellent', 'Bon', 'Moyen', 'Mauvais', 'Très mauvais')
")->fetchAll();

// Tous les avis
$allAvis = $pdo->query("
    SELECT fa.*, c.id as cmd_id
    FROM feedback_avis fa
    LEFT JOIN commandes c ON fa.commande_id = c.id
    ORDER BY fa.date_submission DESC
")->fetchAll();

?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Retours d'avis · ORAVIE Admin</title>
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
        .back-link { color:#4A735C; text-decoration:none; font-size:0.85rem; display:flex; align-items:center; gap:5px; padding:6px 12px; background:#fff; border-radius:0.8rem; }
        .back-link:hover { background:#EFF3EA; }

        .cards-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(250px, 1fr)); gap:1.5rem; margin-bottom:2rem; }
        .card { background:#fff; border-radius:1.2rem; padding:1.5rem; box-shadow:0 2px 10px rgba(0,0,0,0.05); text-align:center; }
        .card-title { font-size:0.75rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#7D8F76; margin-bottom:0.8rem; display:flex; align-items:center; justify-content:center; gap:8px; }
        .card-value { font-size:2.5rem; font-weight:800; color:#2F4B3C; }
        .card-subtext { font-size:0.85rem; color:#92A389; margin-top:0.5rem; }

        .distribution-card { background:#fff; border-radius:1.2rem; padding:1.5rem; box-shadow:0 2px 10px rgba(0,0,0,0.05); margin-bottom:2rem; }
        .distribution-card h3 { font-size:1rem; font-weight:700; color:#2F4B3C; margin-bottom:1rem; }
        .dist-row { display:flex; align-items:center; gap:1rem; padding:0.8rem 0; border-bottom:1px solid #F0F4EC; }
        .dist-row:last-child { border-bottom:none; }
        .dist-label { width:120px; font-weight:600; font-size:0.9rem; }
        .dist-bar { flex:1; height:30px; background:linear-gradient(90deg, #4A735C, #2F4B3C); border-radius:4px; position:relative; }
        .dist-count { width:50px; text-align:right; font-weight:700; font-size:0.9rem; }

        .avis-list { background:#fff; border-radius:1.2rem; padding:1.5rem; box-shadow:0 2px 10px rgba(0,0,0,0.05); }
        .avis-list h3 { font-size:1rem; font-weight:700; color:#2F4B3C; margin-bottom:1.5rem; }
        .avis-item { border:1px solid #E2E9DA; border-radius:0.8rem; padding:1.2rem; margin-bottom:1rem; }
        .avis-item:last-child { margin-bottom:0; }
        .avis-header { display:flex; justify-content:space-between; align-items:flex-start; margin-bottom:1rem; }
        .avis-name { font-weight:700; color:#2F4B3C; }
        .avis-date { font-size:0.8rem; color:#92A389; }
        .avis-ratings { display:grid; grid-template-columns:repeat(auto-fit, minmax(150px, 1fr)); gap:0.8rem; margin-bottom:1rem; }
        .rating-item { padding:0.8rem; background:#f9faf8; border-radius:0.6rem; font-size:0.85rem; }
        .rating-label { color:#7D8F76; font-weight:600; }
        .rating-value { color:#2F4B3C; font-weight:700; margin-top:0.3rem; }
        .avis-text { font-size:0.9rem; color:#4A735C; line-height:1.5; }
        .avis-text p { margin-bottom:0.8rem; }
        .avis-text p:last-child { margin-bottom:0; }

        .empty-state { text-align:center; padding:3rem 1rem; color:#92A389; }
        .empty-state i { font-size:3rem; margin-bottom:1rem; opacity:0.5; }

        @media (max-width: 768px) {
            nav { padding:0 1rem; }
            .main { padding:1rem 0.75rem; }
            .cards-grid { grid-template-columns:1fr; }
            .card-value { font-size:1.8rem; }
            .avis-ratings { grid-template-columns:1fr; }
        }
    </style>
</head>
<body>
    <nav>
        <div class="nav-brand">
            <i class="fas fa-leaf"></i>
            ORAVIE Admin
            <span>Feedback</span>
        </div>
        <div class="nav-links">
            <a href="dashboard.php"><i class="fas fa-chart-line"></i> Dashboard</a>
            <a href="feedback_emails.php"><i class="fas fa-envelope"></i> Historique Emails</a>
            <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Déconnexion</a>
        </div>
    </nav>

    <div class="main">
        <div class="page-header">
            <a href="dashboard.php" class="back-link"><i class="fas fa-arrow-left"></i> Retour</a>
            <h1><i class="fas fa-comments"></i> Retours d'avis clients</h1>
        </div>

        <!-- Stats en cartes -->
        <div class="cards-grid">
            <div class="card">
                <div class="card-title"><i class="fas fa-star"></i> Total avis</div>
                <div class="card-value"><?= $stats['total'] ?? 0 ?></div>
                <div class="card-subtext">retours reçus</div>
            </div>
            <div class="card">
                <div class="card-title"><i class="fas fa-box"></i> Produit</div>
                <div class="card-value"><?= $stats['note_produit'] ? number_format($stats['note_produit'], 1, ',', '') : '-' ?></div>
                <div class="card-subtext">/ 5.0</div>
            </div>
            <div class="card">
                <div class="card-title"><i class="fas fa-truck"></i> Livraison</div>
                <div class="card-value"><?= $stats['note_livraison'] ? number_format($stats['note_livraison'], 1, ',', '') : '-' ?></div>
                <div class="card-subtext">/ 5.0</div>
            </div>
            <div class="card">
                <div class="card-title"><i class="fas fa-globe"></i> Site</div>
                <div class="card-value"><?= $stats['note_site'] ? number_format($stats['note_site'], 1, ',', '') : '-' ?></div>
                <div class="card-subtext">/ 5.0</div>
            </div>
        </div>

        <!-- Distribution des avis produit -->
        <?php if (!empty($distribution)): ?>
        <div class="distribution-card">
            <h3><i class="fas fa-chart-bar"></i> Distribution des avis sur le produit</h3>
            <?php
            $max_count = max(array_column($distribution, 'cnt'));
            foreach ($distribution as $d):
                $pct = ($max_count > 0) ? ($d['cnt'] / $max_count * 100) : 0;
            ?>
                <div class="dist-row">
                    <div class="dist-label"><?= htmlspecialchars($d['avis_produit']) ?></div>
                    <div class="dist-bar" style="width:<?= $pct ?>%"></div>
                    <div class="dist-count"><?= $d['cnt'] ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Liste détaillée des avis -->
        <div class="avis-list">
            <h3><i class="fas fa-list"></i> Tous les avis (<?= count($allAvis) ?>)</h3>
            
            <?php if (empty($allAvis)): ?>
                <div class="empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>Aucun avis reçu pour le moment.</p>
                    <p style="font-size:0.85rem; margin-top:0.5rem;">Les retours apparaîtront ici après que les clients aient répondu aux emails de feedback.</p>
                </div>
            <?php else: ?>
                <?php foreach ($allAvis as $avis): ?>
                    <div class="avis-item">
                        <div class="avis-header">
                            <div>
                                <div class="avis-name"><?= htmlspecialchars($avis['nom_client']) ?></div>
                                <a href="mailto:<?= htmlspecialchars($avis['email_client']) ?>" style="color:#4A735C; text-decoration:none; font-size:0.9rem;">
                                    <?= htmlspecialchars($avis['email_client']) ?>
                                </a>
                                <div style="font-size:0.8rem; color:#92A389; margin-top:0.3rem;">
                                    Commande #<?= htmlspecialchars($avis['commande_id']) ?> • <?= date('d/m/Y H:i', strtotime($avis['date_submission'])) ?>
                                </div>
                            </div>
                        </div>

                        <div class="avis-ratings">
                            <div class="rating-item">
                                <div class="rating-label">Produit</div>
                                <div class="rating-value"><?= htmlspecialchars($avis['avis_produit']) ?></div>
                            </div>
                            <div class="rating-item">
                                <div class="rating-label">Livraison</div>
                                <div class="rating-value"><?= htmlspecialchars($avis['avis_livraison']) ?></div>
                            </div>
                            <div class="rating-item">
                                <div class="rating-label">Site</div>
                                <div class="rating-value"><?= htmlspecialchars($avis['avis_site']) ?></div>
                            </div>
                            <div class="rating-item">
                                <div class="rating-label">Avis général</div>
                                <div class="rating-value"><?= htmlspecialchars($avis['avis_general']) ?></div>
                            </div>
                        </div>

                        <?php if ($avis['remarques'] || $avis['ameliorations']): ?>
                            <div class="avis-text">
                                <?php if ($avis['remarques']): ?>
                                    <p><strong>💬 Remarques :</strong> <?= nl2br(htmlspecialchars($avis['remarques'])) ?></p>
                                <?php endif; ?>
                                <?php if ($avis['ameliorations']): ?>
                                    <p><strong>💡 Améliorations suggérées :</strong> <?= nl2br(htmlspecialchars($avis['ameliorations'])) ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
