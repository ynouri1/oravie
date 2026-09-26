<?php
require_once 'auth.php';
requireAuth();

$pdo = getDB();

// Handle quick status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['id'], $_POST['statut'])) {
    csrfCheck();
    $validStatuts = ['nouvelle', 'confirmée', 'expédiée', 'livrée', 'annulée'];
    $id = filter_var($_POST['id'], FILTER_VALIDATE_INT);
    if ($id && in_array($_POST['statut'], $validStatuts)) {
        $pdo->prepare("UPDATE commandes SET statut = :s WHERE id = :id")
            ->execute([':s' => $_POST['statut'], ':id' => $id]);
    }
    $qs = isset($_GET['statut']) ? '?statut=' . urlencode($_GET['statut']) : '';
    header('Location: dashboard.php' . $qs); exit;
}

$filterStatut = $_GET['statut'] ?? '';
$validStatuts = ['nouvelle', 'confirmée', 'expédiée', 'livrée', 'annulée'];

// Stats globales
$stats = $pdo->query("
    SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN statut = 'nouvelle' THEN 1 ELSE 0 END) AS nouvelles,
        COALESCE(SUM(CASE WHEN statut = 'livrée' THEN prix_total ELSE 0 END), 0) AS ca_total
    FROM commandes
")->fetch();

// Comptage par statut
$statusCounts = [];
foreach ($pdo->query("SELECT statut, COUNT(*) AS n FROM commandes GROUP BY statut")->fetchAll() as $r) {
    $statusCounts[$r['statut']] = $r['n'];
}

// Vérifier si la colonne praticien_id existe dans commandes
$hasPraticienColumn = false;
try {
    $pdo->query("SELECT praticien_id FROM commandes LIMIT 1");
    $hasPraticienColumn = true;
} catch (Exception $e) {
    // Colonne n'existe pas
}

// Vérifier si la table praticiens existe
$hasPraticiens = false;
try {
    $pdo->query("SELECT 1 FROM praticiens LIMIT 1");
    $hasPraticiens = true;
} catch (Exception $e) {
    // Table doesn't exist
}

// Liste des commandes
$sql = "
    SELECT
        c.id, c.date_commande, c.statut,
        c.donnees->>'$.prenom'     AS prenom,
        c.donnees->>'$.nom'        AS nom,
        c.donnees->>'$.telephone'  AS telephone,
        c.prix_total               AS prix_total,
        c.donnees->>'$.lignes'     AS lignes_json
        " . ($hasPraticienColumn && $hasPraticiens ? ", COALESCE(CONCAT(p.prenom, ' ', p.nom), '-') AS praticien_nom" : ", '-' AS praticien_nom") . "
    FROM commandes c
    " . ($hasPraticienColumn && $hasPraticiens ? "LEFT JOIN praticiens p ON c.praticien_id = p.id" : "") . "
";
if ($filterStatut && in_array($filterStatut, $validStatuts)) {
    $stmt = $pdo->prepare($sql . " WHERE statut = :s ORDER BY date_commande DESC");
    $stmt->execute([':s' => $filterStatut]);
} else {
    $stmt = $pdo->query($sql . " ORDER BY date_commande DESC");
}
$commandes = $stmt->fetchAll();
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta http-equiv="refresh" content="30">
  <title>Dashboard · ORAVIE Admin</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body { background:#F4F7F1; font-family:'Segoe UI',sans-serif; color:#2C3A2F; min-height:100vh; }

    nav { background:#2F4B3C; padding:0 2rem; display:flex; align-items:center; justify-content:space-between; height:58px; box-shadow:0 2px 8px rgba(0,0,0,0.15); }
    .nav-brand { color:#fff; font-size:1.1rem; font-weight:700; display:flex; align-items:center; gap:8px; }
    .nav-brand span { font-size:0.68rem; letter-spacing:2px; color:#A0C4A8; text-transform:uppercase; }
    .nav-links { display:flex; gap:0.3rem; align-items:center; }
    .nav-links a { color:#A0C4A8; text-decoration:none; font-size:0.85rem; padding:6px 14px; border-radius:1rem; transition:0.2s; }
    .nav-links a:hover, .nav-links a.active { background:rgba(255,255,255,0.15); color:#fff; }
    .nav-links a.logout { color:#F87171; }

    .main { max-width:1200px; margin:0 auto; padding:2rem 1.5rem; }
    .page-title { font-size:1.4rem; font-weight:700; margin-bottom:1.5rem; }

    /* Stats */
    .stats { display:flex; gap:1rem; margin-bottom:2rem; flex-wrap:wrap; }
    .stat-card { background:#fff; border-radius:1rem; padding:1.2rem 1.5rem; flex:1; min-width:150px; box-shadow:0 2px 10px rgba(0,0,0,0.05); border-left:4px solid #4A735C; }
    .stat-card.yellow { border-color:#F59E0B; }
    .stat-card.blue   { border-color:#2563EB; }
    .stat-val   { font-size:1.8rem; font-weight:800; color:#2F4B3C; line-height:1; }
    .stat-label { font-size:0.72rem; color:#92A389; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; margin-top:4px; }

    /* Filters */
    .filters { display:flex; gap:0.5rem; flex-wrap:wrap; margin-bottom:1.5rem; align-items:center; }
    .filters-label { font-size:0.8rem; color:#7D8F76; font-weight:600; }
    .filter-btn { padding:6px 14px; border-radius:1rem; font-size:0.8rem; font-weight:600; text-decoration:none; background:#fff; color:#7D8F76; border:1.5px solid #E2E9DA; transition:0.2s; }
    .filter-btn:hover { border-color:#4A735C; color:#4A735C; }
    .filter-btn.active { background:#2F4B3C; color:#fff; border-color:#2F4B3C; }

    /* Table */
    .card { background:#fff; border-radius:1.2rem; box-shadow:0 2px 10px rgba(0,0,0,0.05); overflow:hidden; }
    table { width:100%; border-collapse:collapse; display:block; overflow-x:auto; }
    thead th { background:#F4F7F1; padding:12px 16px; text-align:left; font-size:0.7rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#7D8F76; }
    tbody tr { border-top:1px solid #F0F4EC; transition:background 0.15s; display:table-row; width:100%; }
    tbody tr:hover { background:#FAFCF8; }
    td { padding:12px 16px; font-size:0.88rem; vertical-align:middle; display:table-cell; }
    .td-num { font-weight:700; color:#4A735C; font-size:0.95rem; }
    .td-client strong { display:block; }
    .td-client small { color:#92A389; font-size:0.78rem; }
    .td-produits { font-size:0.8rem; color:#7D8F76; max-width:200px; }
    .td-total { font-weight:700; white-space:nowrap; }
    .btn-view { background:#EFF3EA; color:#2F4B3C; border:none; border-radius:0.6rem; padding:6px 14px; font-size:0.8rem; font-weight:600; cursor:pointer; text-decoration:none; transition:0.2s; display:inline-block; }
    .btn-view:hover { background:#DCE9D4; }
    .empty { text-align:center; padding:3rem; color:#92A389; }
    .empty i { font-size:2rem; display:block; margin-bottom:0.5rem; }

    /* ===== RESPONSIVE MOBILE ===== */
    @media (max-width: 768px) {
      body { padding: 0; }
      nav { padding: 0 1rem; flex-wrap: wrap; height: auto; }
      .nav-brand { flex: 1; min-width: 200px; padding: 0.75rem 0; }
      .nav-links { 
        width: 100%; 
        flex-wrap: wrap;
        gap: 0.25rem;
        margin-top: 0.5rem;
      }
      .nav-links a { 
        flex: 1;
        min-width: 120px;
        padding: 0.5rem;
        font-size: 0.75rem;
        text-align: center;
      }

      .main { max-width: 100%; padding: 1.5rem 1rem; }
      .page-title { font-size: 1.2rem; margin-bottom: 1rem; }

      /* Stats */
      .stats { gap: 0.75rem; margin-bottom: 1.5rem; }
      .stat-card { 
        min-width: calc(50% - 0.375rem);
        padding: 1rem;
      }
      .stat-val { font-size: 1.5rem; }
      .stat-label { font-size: 0.65rem; margin-top: 0.3rem; }

      /* Filters */
      .filters { 
        gap: 0.4rem;
        margin-bottom: 1rem;
      }
      .filters-label { font-size: 0.75rem; }
      .filter-btn { 
        padding: 5px 10px;
        font-size: 0.7rem;
      }

      /* Table - keep single line with horizontal scroll */
      table { font-size: 0.8rem; }
      thead th { padding: 10px 12px; font-size: 0.65rem; }
      td { padding: 10px 12px; font-size: 0.75rem; }
      
      .td-produits { max-width: 150px; }
      .btn-view { padding: 5px 10px; font-size: 0.75rem; }

      #refresh-bar {
        font-size: 0.7rem;
        padding: 0.4rem;
      }
    }

    @media (max-width: 480px) {
      nav { padding: 0 0.75rem; }
      .main { padding: 1rem 0.75rem; }
      
      .page-title { font-size: 1rem; }

      .stat-card { 
        min-width: 100%;
        padding: 0.8rem;
      }
      .stat-val { font-size: 1.3rem; }

      .filter-btn { 
        padding: 4px 8px;
        font-size: 0.65rem;
      }

      table { font-size: 0.7rem; }
      thead th { padding: 8px 10px; font-size: 0.6rem; }
      td { padding: 8px 10px; font-size: 0.7rem; }
      .td-produits { max-width: 120px; }
      .btn-view { padding: 4px 8px; font-size: 0.7rem; }
    }
  </style>
</head>
<body>
<nav>
  <div class="nav-brand"><i class="fas fa-leaf"></i> ORAVIE <span>Admin</span></div>
  <div class="nav-links">
    <a href="dashboard.php" class="active"><i class="fas fa-list-alt"></i> Commandes</a>
    <a href="commande_ajouter.php"><i class="fas fa-plus-circle"></i> Nouvelle commande</a>
    <a href="produits.php"><i class="fas fa-box"></i> Produits</a>
    <a href="praticiens.php"><i class="fas fa-stethoscope"></i> Praticiens</a>
    <a href="stats.php"><i class="fas fa-chart-bar"></i> Statistiques</a>
    <a href="depenses.php"><i class="fas fa-receipt"></i> Dépenses</a>
    <a href="mouvements.php"><i class="fas fa-boxes"></i> Stock lots</a>
    <a href="feedback_avis.php"><i class="fas fa-comments"></i> Avis clients</a>
    <a href="commerciaux.php"><i class="fas fa-user-tie"></i> Commerciaux</a>
    <a href="visites.php"><i class="fas fa-map-marked-alt"></i> Visites</a>
    <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Déconnexion</a>
  </div>
</nav>

<div class="main">
  <div class="page-title">Commandes</div>

  <div class="stats">
    <div class="stat-card">
      <div class="stat-val"><?= (int)$stats['total'] ?></div>
      <div class="stat-label">Total commandes</div>
    </div>
    <div class="stat-card yellow">
      <div class="stat-val"><?= (int)$stats['nouvelles'] ?></div>
      <div class="stat-label">Nouvelles</div>
    </div>
    <div class="stat-card blue">
      <div class="stat-val"><?= number_format((float)$stats['ca_total'], 2) ?> DT</div>
      <div class="stat-label">Chiffre d'affaires</div>
    </div>
  </div>

  <div class="filters">
    <span class="filters-label">Filtrer :</span>
    <a href="dashboard.php" class="filter-btn <?= !$filterStatut ? 'active' : '' ?>">Toutes (<?= (int)$stats['total'] ?>)</a>
    <?php foreach (['nouvelle' => 'Nouvelles', 'confirmée' => 'Confirmées', 'expédiée' => 'Expédiées', 'livrée' => 'Livrées', 'annulée' => 'Annulées'] as $s => $l): ?>
    <a href="?statut=<?= urlencode($s) ?>" class="filter-btn <?= $filterStatut === $s ? 'active' : '' ?>"><?= $l ?> (<?= $statusCounts[$s] ?? 0 ?>)</a>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <?php if (empty($commandes)): ?>
      <div class="empty"><i class="fas fa-inbox"></i> Aucune commande trouvée.</div>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Date</th>
          <th>Client</th>
          <th>Praticien</th>
          <th>Produits</th>
          <th>Total</th>
          <th>Statut</th>
          <th>Action</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($commandes as $c):
        $lignes = json_decode($c['lignes_json'] ?? '[]', true);
        $prodSummary = implode(', ', array_map(fn($l) => $l['volume_ml'].'ml ×'.$l['quantite'], $lignes ?: []));
      ?>
        <tr>
          <td class="td-num">#<?= $c['id'] ?></td>
          <td><?= date('d/m/Y H:i', strtotime($c['date_commande'])) ?></td>
          <td class="td-client">
            <strong><?= htmlspecialchars($c['prenom'] . ' ' . $c['nom']) ?></strong>
            <small><?= htmlspecialchars($c['telephone']) ?></small>
          </td>
          <td><?= htmlspecialchars($c['praticien_nom']) ?></td>
          <td class="td-produits"><?= htmlspecialchars($prodSummary ?: '—') ?></td>
          <td class="td-total"><?= number_format((float)$c['prix_total'], 2) ?> DT</td>
          <td><?= statusBadge($c['statut']) ?></td>
          <td>
            <a href="commande.php?id=<?= $c['id'] ?>" class="btn-view"><i class="fas fa-eye"></i> Voir</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
<div id="refresh-bar" style="position:fixed;bottom:0;left:0;right:0;background:#2F4B3C;color:#A0C4A8;font-size:0.75rem;text-align:center;padding:5px;z-index:999;">
  Actualisation dans <span id="countdown">30</span>s &nbsp;·&nbsp; <a href="dashboard.php<?= isset($_GET['statut']) ? '?statut='.urlencode($_GET['statut']) : '' ?>" style="color:#fff;text-decoration:none;font-weight:700;">↻ Rafraîchir maintenant</a>
</div>
<script>
  var n = 30;
  var el = document.getElementById('countdown');
  setInterval(function() { n--; if (n >= 0) el.textContent = n; }, 1000);
</script>
</body>
</html>
