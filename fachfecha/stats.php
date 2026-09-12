<?php
require_once 'auth.php';
requireAuth();

$pdo = getDB();

// Vérifier si les praticiens existent
$hasPraticiens = false;
try {
    $pdo->query("SELECT 1 FROM praticiens LIMIT 1");
    $hasPraticiens = true;
} catch (Exception $e) {}

// Stats globales
$stats_global = $pdo->query("
    SELECT
        COUNT(*) AS total_commandes,
        SUM(CASE WHEN statut = 'livrée' THEN 1 ELSE 0 END) AS commandes_livrees,
        SUM(CASE WHEN statut = 'nouvelle' THEN 1 ELSE 0 END) AS commandes_nouvelles,
        SUM(CASE WHEN statut = 'annulée' THEN 1 ELSE 0 END) AS commandes_annulees,
        COALESCE(SUM(CASE WHEN statut = 'livrée' THEN prix_total ELSE 0 END), 0) AS ca_total,
        COALESCE(SUM(CASE WHEN statut = 'livrée' THEN prix_total ELSE 0 END), 0) AS ca_livree
    FROM commandes
")->fetch();

// Top produits
$top_produits = $pdo->query("
    SELECT p.id, p.nom, p.volume_ml, p.prix, p.stock
    FROM produits p
    WHERE p.actif = 1
    ORDER BY p.volume_ml ASC
")->fetchAll();

// Stats par statut
$stats_statut = $pdo->query("
    SELECT
        statut,
        COUNT(*) AS count,
        COALESCE(SUM(CASE WHEN statut = 'livrée' THEN prix_total ELSE 0 END), 0) AS ca
    FROM commandes
    GROUP BY statut
")->fetchAll();

// Top praticiens (si table existe)
$top_praticiens = [];
if ($hasPraticiens) {
    // Récupérer toutes les commandes livrées avec les données JSON
    $commandes_praticiens = $pdo->query("
        SELECT
            COALESCE(CONCAT(p.prenom, ' ', p.nom), 'Sans praticien') AS praticien,
            c.prix_total,
            c.donnees
        FROM commandes c
        LEFT JOIN praticiens p ON c.praticien_id = p.id
        WHERE c.statut = 'livrée'
        ORDER BY c.praticien_id DESC
    ")->fetchAll();
    
    // Traiter et regrouper par praticien
    $praticiens_data = [];
    foreach ($commandes_praticiens as $cmd) {
        $praticien = $cmd['praticien'];
        if (!isset($praticiens_data[$praticien])) {
            $praticiens_data[$praticien] = [
                'praticien' => $praticien,
                'nb_commandes' => 0,
                'ca' => 0,
                'nb_sprays' => 0
            ];
        }
        
        $praticiens_data[$praticien]['nb_commandes']++;
        $praticiens_data[$praticien]['ca'] += (float)$cmd['prix_total'];
        
        // Compter les sprays depuis le JSON
        $donnees = json_decode($cmd['donnees'], true);
        if (isset($donnees['lignes']) && is_array($donnees['lignes'])) {
            foreach ($donnees['lignes'] as $ligne) {
                $praticiens_data[$praticien]['nb_sprays'] += (int)($ligne['quantite'] ?? 0);
            }
        }
    }
    
    // Trier par CA décroissant et limiter aux 5 premiers
    uasort($praticiens_data, function($a, $b) {
        return $b['ca'] - $a['ca'];
    });
    $top_praticiens = array_slice($praticiens_data, 0, 5);
}

// Évolution CA par mois (6 derniers mois)
$evolution_data = $pdo->query("
    SELECT
        DATE_FORMAT(date_commande, '%Y-%m') AS mois,
        id,
        donnees
    FROM commandes
    WHERE statut = 'livrée' AND date_commande >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    ORDER BY mois ASC
")->fetchAll();

// Calculer les stats par mois
$evolution_ca = [];
foreach ($evolution_data as $cmd) {
    $mois = $cmd['mois'];
    $donnees = json_decode($cmd['donnees'], true);
    
    if (!isset($evolution_ca[$mois])) {
        $evolution_ca[$mois] = [
            'mois' => $mois,
            'nb_commandes' => 0,
            'nb_sprays' => 0,
            'ca' => 0
        ];
    }
    
    $evolution_ca[$mois]['nb_commandes']++;
    $evolution_ca[$mois]['ca'] += (float)($donnees['prix_total'] ?? 0);
    
    // Compter les sprays
    if (isset($donnees['lignes']) && is_array($donnees['lignes'])) {
        foreach ($donnees['lignes'] as $ligne) {
            $evolution_ca[$mois]['nb_sprays'] += (int)($ligne['quantite'] ?? 0);
        }
    }
}

// Convertir en array indexé et trier
$evolution_ca = array_values($evolution_ca);

// Stock total
$stock_info = $pdo->query("
    SELECT
        SUM(stock) AS total_stock,
        COUNT(*) AS total_produits,
        SUM(CASE WHEN stock = 0 THEN 1 ELSE 0 END) AS produits_epuises
    FROM produits
    WHERE actif = 1
")->fetch();
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Statistiques Globales · ORAVIE Admin</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body { background:#F4F7F1; font-family:'Segoe UI',sans-serif; color:#2C3A2F; min-height:100vh; }

    nav { background:#2F4B3C; padding:0 2rem; display:flex; align-items:center; justify-content:space-between; height:58px; box-shadow:0 2px 8px rgba(0,0,0,0.15); }
    .nav-brand { color:#fff; font-size:1.1rem; font-weight:700; display:flex; align-items:center; gap:8px; }
    .nav-brand span { font-size:0.68rem; letter-spacing:2px; color:#A0C4A8; text-transform:uppercase; }
    .nav-links { display:flex; gap:0.3rem; align-items:center; flex-wrap:wrap; }
    .nav-links a { color:#A0C4A8; text-decoration:none; font-size:0.85rem; padding:6px 14px; border-radius:1rem; transition:0.2s; }
    .nav-links a:hover, .nav-links a.active { background:rgba(255,255,255,0.15); color:#fff; }
    .nav-links a.logout { color:#F87171; }

    .main { max-width:1400px; margin:0 auto; padding:2rem 1.5rem; }
    .page-title { font-size:1.4rem; font-weight:700; margin-bottom:1.5rem; }

    .grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:1rem; margin-bottom:2rem; }
    .stat-card { background:#fff; border-radius:1rem; padding:1.2rem 1.5rem; box-shadow:0 2px 10px rgba(0,0,0,0.05); border-left:4px solid #4A735C; }
    .stat-card.yellow { border-color:#F59E0B; }
    .stat-card.red { border-color:#EF4444; }
    .stat-card.blue { border-color:#2563EB; }
    .stat-card.green { border-color:#10B981; }
    .stat-val { font-size:2rem; font-weight:800; color:#2F4B3C; line-height:1; }
    .stat-label { font-size:0.72rem; color:#92A389; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; margin-top:4px; }

    .grid2 { display:grid; grid-template-columns:repeat(auto-fit, minmax(350px, 1fr)); gap:2rem; margin-bottom:2rem; }
    .card { background:#fff; border-radius:1.2rem; box-shadow:0 2px 10px rgba(0,0,0,0.05); overflow:hidden; }
    .card-title { font-size:1rem; font-weight:700; padding:1.2rem 1.5rem; background:#F4F7F1; border-bottom:1px solid #E2E9DA; color:#2F4B3C; }
    
    table { width:100%; border-collapse:collapse; }
    thead th { background:#F4F7F1; padding:10px 12px; text-align:left; font-size:0.7rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#7D8F76; }
    tbody tr { border-top:1px solid #F0F4EC; transition:background 0.15s; }
    tbody tr:hover { background:#FAFCF8; }
    td { padding:10px 12px; font-size:0.85rem; vertical-align:middle; }
    .td-num { font-weight:700; color:#4A735C; }
    .td-ca { font-weight:700; color:#2F4B3C; }
    .badge { display:inline-block; padding:3px 8px; border-radius:0.6rem; font-size:0.7rem; font-weight:700; }
    .badge-success { background:#D1FAE5; color:#059669; }
    .badge-warning { background:#FEF3C7; color:#D97706; }
    .badge-danger { background:#FEE2E2; color:#DC2626; }

    .chart-container { padding:1.5rem; height:200px; display:flex; align-items:flex-end; gap:0.5rem; }
    .chart-bar { flex:1; background:#4A735C; border-radius:0.5rem 0.5rem 0 0; min-height:10px; position:relative; }
    .chart-bar:hover { background:#2F4B3C; }
    .chart-label { position:absolute; bottom:-20px; left:50%; transform:translateX(-50%); font-size:0.7rem; color:#92A389; white-space:nowrap; }

    @media (max-width: 768px) {
      body { padding: 0; }
      nav { padding: 0 1rem; flex-wrap: wrap; height: auto; }
      .nav-brand { flex: 1; min-width: 200px; padding: 0.75rem 0; }
      .nav-links { width: 100%; flex-wrap: wrap; gap: 0.25rem; margin-top: 0.5rem; }
      .nav-links a { flex: 1; min-width: 100px; padding: 0.5rem; font-size: 0.7rem; text-align: center; }
      .main { max-width: 100%; padding: 1.5rem 1rem; }
      .page-title { font-size: 1.2rem; margin-bottom: 1rem; }
      .grid { grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 0.75rem; margin-bottom: 1.5rem; }
      .stat-card { padding: 0.9rem 1rem; }
      .stat-val { font-size: 1.5rem; }
      .stat-label { font-size: 0.65rem; }
      .grid2 { grid-template-columns: 1fr; gap: 1.5rem; }
      .chart-container { height: 150px; padding: 1rem; }
      
      /* Table - keep single line with horizontal scroll */
      table { display: block; overflow-x: auto; }
      thead { display: table-header-group; }
      thead th { padding: 10px 12px; font-size: 0.65rem; }
      tbody { display: table-row-group; }
      tbody tr { display: table-row; width: 100%; }
      td { padding: 10px 12px; font-size: 0.75rem; display: table-cell; }
    }
    @media (max-width: 480px) {
      nav { padding: 0 0.75rem; }
      .main { padding: 1rem 0.75rem; }
      .page-title { font-size: 1rem; }
      .grid { grid-template-columns: repeat(2, 1fr); }
      .stat-card { padding: 0.8rem; }
      .stat-val { font-size: 1.3rem; }
      
      table { font-size: 0.7rem; }
      thead th { padding: 8px 10px; font-size: 0.6rem; }
      td { padding: 8px 10px; font-size: 0.65rem; }
    }
  </style>
</head>
<body>
<nav>
  <div class="nav-brand"><i class="fas fa-leaf"></i> ORAVIE <span>Admin</span></div>
  <div class="nav-links">
    <a href="dashboard.php"><i class="fas fa-list-alt"></i> Commandes</a>
    <a href="commande_ajouter.php"><i class="fas fa-plus-circle"></i> Nouvelle commande</a>
    <a href="produits.php"><i class="fas fa-box"></i> Produits</a>
    <a href="praticiens.php"><i class="fas fa-stethoscope"></i> Praticiens</a>
    <a href="stats.php" class="active"><i class="fas fa-chart-bar"></i> Statistiques</a>
    <a href="depenses.php"><i class="fas fa-receipt"></i> Dépenses</a>
    <a href="mouvements.php"><i class="fas fa-boxes"></i> Stock lots</a>
    <a href="feedback_avis.php"><i class="fas fa-comments"></i> Avis clients</a>
    <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Déconnexion</a>
  </div>
</nav>

<div class="main">
  <div class="page-title"><i class="fas fa-chart-bar"></i> Statistiques globales</div>

  <!-- Cartes KPI -->
  <div class="grid">
    <div class="stat-card">
      <div class="stat-val"><?= (int)$stats_global['total_commandes'] ?></div>
      <div class="stat-label">Commandes totales</div>
    </div>
    <div class="stat-card green">
      <div class="stat-val"><?= (int)$stats_global['commandes_livrees'] ?></div>
      <div class="stat-label">Commandes livrées</div>
    </div>
    <div class="stat-card yellow">
      <div class="stat-val"><?= (int)$stats_global['commandes_nouvelles'] ?></div>
      <div class="stat-label">Commandes en attente</div>
    </div>
    <div class="stat-card red">
      <div class="stat-val"><?= (int)$stats_global['commandes_annulees'] ?></div>
      <div class="stat-label">Commandes annulées</div>
    </div>
    <div class="stat-card blue">
      <div class="stat-val"><?= number_format($stats_global['ca_total'], 0) ?></div>
      <div class="stat-label">CA Total (DT)</div>
    </div>
    <div class="stat-card green">
      <div class="stat-val"><?= number_format($stats_global['ca_livree'], 0) ?></div>
      <div class="stat-label">CA Livré (DT)</div>
    </div>
    <div class="stat-card">
      <div class="stat-val"><?= (int)$stock_info['total_stock'] ?></div>
      <div class="stat-label">Stock total</div>
    </div>
    <div class="stat-card yellow">
      <div class="stat-val"><?= (int)$stock_info['produits_epuises'] ?></div>
      <div class="stat-label">Produits épuisés</div>
    </div>
  </div>

  <div class="grid2">
    <!-- Statuts -->
    <div class="card">
      <div class="card-title"><i class="fas fa-pie-chart"></i> Commandes par statut</div>
      <table>
        <thead>
          <tr><th>Statut</th><th style="text-align:center;">Nombre</th><th style="text-align:right;">CA (DT)</th></tr>
        </thead>
        <tbody>
          <?php foreach ($stats_statut as $s): ?>
            <tr>
              <td><span class="badge badge-<?= $s['statut'] === 'livrée' ? 'success' : ($s['statut'] === 'nouvelle' ? 'warning' : 'danger') ?>"><?= ucfirst($s['statut']) ?></span></td>
              <td style="text-align:center;" class="td-num"><?= (int)$s['count'] ?></td>
              <td style="text-align:right;" class="td-ca"><?= number_format($s['ca'], 2) ?> DT</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Top Produits -->
    <div class="card">
      <div class="card-title"><i class="fas fa-fire"></i> Produits</div>
      <table>
        <thead>
          <tr><th>Produit</th><th style="text-align:center;">Stock</th><th style="text-align:right;">Prix</th></tr>
        </thead>
        <tbody>
          <?php if (empty($top_produits)): ?>
            <tr><td colspan="3" style="text-align:center; padding:1rem; color:#92A389;">Aucun produit</td></tr>
          <?php else: ?>
            <?php foreach ($top_produits as $p): ?>
            <tr>
              <td><?= htmlspecialchars($p['nom']) ?><br><small style="color:#92A389;"><?= $p['volume_ml'] ?>ml</small></td>
              <td style="text-align:center;" class="td-num"><?= (int)$p['stock'] ?></td>
              <td style="text-align:right;" class="td-ca"><?= number_format($p['prix'], 2) ?> DT</td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Top Praticiens -->
    <?php if (!empty($top_praticiens)): ?>
    <div class="card">
      <div class="card-title"><i class="fas fa-stethoscope"></i> Top praticiens</div>
      <table>
        <thead>
          <tr><th>Praticien</th><th style="text-align:center;">Cmd</th><th style="text-align:center;">Sprays Livrés</th><th style="text-align:right;">CA (DT)</th></tr>
        </thead>
        <tbody>
          <?php foreach ($top_praticiens as $p): ?>
            <tr>
              <td><?= htmlspecialchars($p['praticien']) ?></td>
              <td style="text-align:center;" class="td-num"><?= (int)$p['nb_commandes'] ?></td>
              <td style="text-align:center;" class="td-num"><?= (int)$p['nb_sprays'] ?></td>
              <td style="text-align:right;" class="td-ca"><?= number_format($p['ca'], 2) ?> DT</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>

  <!-- Évolution CA -->
  <div class="card">
    <div class="card-title"><i class="fas fa-chart-line"></i> Évolution mensuelle (6 derniers mois)</div>
    <table>
      <thead>
        <tr><th>Mois</th><th style="text-align:center;">Cmd Livrées</th><th style="text-align:center;">Sprays Livrés</th><th style="text-align:right;">CA (DT)</th></tr>
      </thead>
      <tbody>
        <?php 
        $total_cmd = 0;
        $total_sprays = 0;
        $total_ca = 0;
        foreach ($evolution_ca as $evo): 
          $total_cmd += (int)$evo['nb_commandes'];
          $total_sprays += (int)$evo['nb_sprays'];
          $total_ca += (float)$evo['ca'];
        ?>
          <tr>
            <td><strong><?= htmlspecialchars($evo['mois']) ?></strong></td>
            <td style="text-align:center;" class="td-num"><?= (int)$evo['nb_commandes'] ?></td>
            <td style="text-align:center;" class="td-num"><?= (int)$evo['nb_sprays'] ?></td>
            <td style="text-align:right;" class="td-ca"><?= number_format((float)$evo['ca'], 2) ?> DT</td>
          </tr>
        <?php endforeach; ?>
        <tr style="background:#F4F7F1; font-weight:700;">
          <td style="text-align:right;">TOTAL</td>
          <td style="text-align:center;" class="td-num"><?= (int)$total_cmd ?></td>
          <td style="text-align:center;" class="td-num"><?= (int)$total_sprays ?></td>
          <td style="text-align:right;" class="td-ca"><?= number_format($total_ca, 2) ?> DT</td>
        </tr>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
