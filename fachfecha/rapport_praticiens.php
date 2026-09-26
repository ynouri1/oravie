<?php
require_once 'auth.php';
requireAuth();

$pdo = getDB();

// Toutes les commandes rattachées à un praticien (toutes statuts = "commandé")
$commandes = [];
try {
    $commandes = $pdo->query("
        SELECT c.praticien_id, c.statut, c.donnees->>'$.lignes' AS lignes_json
        FROM commandes c
        WHERE c.praticien_id IS NOT NULL
    ")->fetchAll();
} catch (Exception $e) {
    $commandes = [];
}

// Agréger par praticien : nb de commandes + nb de sprays commandés
$agg = []; // praticien_id => ['cmd'=>int, 'sprays'=>int, 'sprays_livres'=>int, 'cmd_livrees'=>int]
foreach ($commandes as $c) {
    $pid = (int)$c['praticien_id'];
    if (!isset($agg[$pid])) $agg[$pid] = ['cmd' => 0, 'sprays' => 0, 'sprays_livres' => 0, 'cmd_livrees' => 0];
    $lignes = json_decode($c['lignes_json'] ?? '[]', true) ?: [];
    $sprays = 0;
    foreach ($lignes as $l) $sprays += (int)($l['quantite'] ?? 0);
    $agg[$pid]['cmd']    += 1;
    $agg[$pid]['sprays'] += $sprays;
    if ($c['statut'] === 'livrée') {
        $agg[$pid]['cmd_livrees']  += 1;
        $agg[$pid]['sprays_livres'] += $sprays;
    }
}

// Tous les praticiens (y compris ceux sans commande)
$praticiens = [];
try {
    $praticiens = $pdo->query("SELECT id, prenom, nom, specialite, ville, actif FROM praticiens ORDER BY nom, prenom")->fetchAll();
} catch (Exception $e) {
    $praticiens = [];
}

// Construire les lignes du rapport
$rows = [];
$totSprays = 0; $totCmd = 0; $totSpraysLivres = 0;
foreach ($praticiens as $p) {
    $a = $agg[(int)$p['id']] ?? ['cmd' => 0, 'sprays' => 0, 'sprays_livres' => 0, 'cmd_livrees' => 0];
    $rows[] = [
        'nom'          => trim($p['prenom'] . ' ' . $p['nom']),
        'specialite'   => $p['specialite'] ?: '',
        'ville'        => $p['ville'] ?: '',
        'actif'        => (int)$p['actif'],
        'cmd'          => $a['cmd'],
        'sprays'       => $a['sprays'],
        'sprays_livres' => $a['sprays_livres'],
    ];
    $totSprays += $a['sprays'];
    $totSpraysLivres += $a['sprays_livres'];
    $totCmd += $a['cmd'];
}
// Tri par sprays commandés décroissant
usort($rows, fn($x, $y) => $y['sprays'] <=> $x['sprays']);

$nbPraticiensAvecCmd = count(array_filter($rows, fn($r) => $r['cmd'] > 0));
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Rapport praticiens · ORAVIE Admin</title>
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

    .main { max-width:1000px; margin:0 auto; padding:2rem 1.5rem; }
    .page-title { font-size:1.4rem; font-weight:700; margin-bottom:0.4rem; }
    .subtitle { color:#7D8F76; font-size:0.85rem; margin-bottom:1.5rem; }

    .stats { display:flex; gap:1rem; margin-bottom:1.5rem; flex-wrap:wrap; }
    .stat-card { background:#fff; border-radius:1rem; padding:1rem 1.4rem; flex:1; min-width:150px; box-shadow:0 2px 10px rgba(0,0,0,0.05); border-left:4px solid #4A735C; }
    .stat-val { font-size:1.6rem; font-weight:800; color:#2F4B3C; line-height:1; }
    .stat-label { font-size:0.72rem; color:#92A389; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; margin-top:4px; }

    .card { background:#fff; border-radius:1.2rem; box-shadow:0 2px 10px rgba(0,0,0,0.05); overflow:hidden; }
    table { width:100%; border-collapse:collapse; }
    thead th { background:#F4F7F1; padding:12px 16px; text-align:left; font-size:0.7rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#7D8F76; white-space:nowrap; }
    th.num, td.num { text-align:right; }
    td { padding:11px 16px; font-size:0.88rem; border-top:1px solid #F0F4EC; vertical-align:middle; }
    tbody tr:hover { background:#FAFCF8; }
    .rank { color:#92A389; font-weight:700; width:34px; }
    .sprays { font-weight:800; color:#2F4B3C; }
    .muted { color:#B0BFA8; }
    .badge-off { background:#FEE2E2; color:#DC2626; font-size:0.66rem; font-weight:700; padding:2px 7px; border-radius:1rem; margin-left:6px; }
    tfoot td { padding:12px 16px; font-weight:800; background:#F4F7F1; border-top:2px solid #E2E9DA; }
    .empty { text-align:center; padding:3rem; color:#92A389; }
    @media (max-width:768px){ nav{flex-wrap:wrap;height:auto;padding:0.5rem 1rem;} .nav-links{width:100%;} table{display:block;overflow-x:auto;} }
    @media print { nav, .stats { display:none; } .main { padding:0; } .card { box-shadow:none; } }
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
    <a href="stats.php"><i class="fas fa-chart-bar"></i> Statistiques</a>
    <a href="depenses.php"><i class="fas fa-receipt"></i> Dépenses</a>
    <a href="mouvements.php"><i class="fas fa-boxes"></i> Stock lots</a>
    <a href="feedback_avis.php"><i class="fas fa-comments"></i> Avis clients</a>
    <a href="commerciaux.php"><i class="fas fa-user-tie"></i> Commerciaux</a>
    <a href="visites.php"><i class="fas fa-map-marked-alt"></i> Visites</a>
    <a href="rapport_praticiens.php" class="active"><i class="fas fa-file-medical"></i> Rapport praticiens</a>
    <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Déconnexion</a>
  </div>
</nav>

<div class="main">
  <div class="page-title"><i class="fas fa-file-medical"></i> Rapport praticiens — sprays commandés</div>
  <div class="subtitle">Toutes les commandes rattachées à un praticien (tous statuts confondus). Trié par sprays commandés.</div>

  <div class="stats">
    <div class="stat-card">
      <div class="stat-val"><?= count($rows) ?></div>
      <div class="stat-label">Praticiens</div>
    </div>
    <div class="stat-card">
      <div class="stat-val"><?= $nbPraticiensAvecCmd ?></div>
      <div class="stat-label">Avec ≥ 1 commande</div>
    </div>
    <div class="stat-card">
      <div class="stat-val"><?= $totSprays ?></div>
      <div class="stat-label">Sprays commandés</div>
    </div>
    <div class="stat-card">
      <div class="stat-val"><?= $totSpraysLivres ?></div>
      <div class="stat-label">Dont livrés</div>
    </div>
  </div>

  <div class="card">
    <?php if (empty($rows)): ?>
      <div class="empty"><i class="fas fa-inbox"></i><br>Aucun praticien enregistré.</div>
    <?php else: ?>
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Praticien</th>
          <th>Spécialité</th>
          <th>Ville</th>
          <th class="num">Commandes</th>
          <th class="num">Sprays commandés</th>
          <th class="num">Dont livrés</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($rows as $i => $r): ?>
        <tr>
          <td class="rank"><?= $i + 1 ?></td>
          <td>
            <strong><?= htmlspecialchars($r['nom']) ?></strong>
            <?php if (!$r['actif']): ?><span class="badge-off">inactif</span><?php endif; ?>
          </td>
          <td><?= $r['specialite'] !== '' ? htmlspecialchars($r['specialite']) : '<span class="muted">—</span>' ?></td>
          <td><?= $r['ville'] !== '' ? htmlspecialchars($r['ville']) : '<span class="muted">—</span>' ?></td>
          <td class="num"><?= $r['cmd'] ?: '<span class="muted">0</span>' ?></td>
          <td class="num sprays"><?= $r['sprays'] ?: '<span class="muted" style="font-weight:400;">0</span>' ?></td>
          <td class="num"><?= $r['sprays_livres'] ?: '<span class="muted">0</span>' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot>
        <tr>
          <td colspan="4">TOTAL</td>
          <td class="num"><?= $totCmd ?></td>
          <td class="num"><?= $totSprays ?></td>
          <td class="num"><?= $totSpraysLivres ?></td>
        </tr>
      </tfoot>
    </table>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
