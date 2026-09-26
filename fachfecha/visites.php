<?php
require_once 'auth.php';
requireAuth();

$pdo = getDB();

// Table des visites (historique)
try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS visites (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            praticien_id  INT NOT NULL,
            commercial_id INT NULL,
            date_visite   DATE NOT NULL,
            compte_rendu  TEXT,
            date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_praticien (praticien_id),
            INDEX idx_commercial (commercial_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
} catch (Exception $e) {}

$filterPraticien  = filter_var($_GET['praticien'] ?? '', FILTER_VALIDATE_INT) ?: null;
$filterCommercial = filter_var($_GET['commercial'] ?? '', FILTER_VALIDATE_INT) ?: null;

// Listes pour les filtres
$praticiens = [];
$commerciaux = [];
try { $praticiens  = $pdo->query("SELECT id, nom, prenom FROM praticiens ORDER BY nom, prenom")->fetchAll(); } catch (Exception $e) {}
try { $commerciaux = $pdo->query("SELECT id, nom, username FROM admins WHERE role = 'commercial' ORDER BY nom, username")->fetchAll(); } catch (Exception $e) {}

// Requête des visites
$visites = [];
$where = [];
$params = [];
if ($filterPraticien)  { $where[] = 'v.praticien_id = :p';  $params[':p'] = $filterPraticien; }
if ($filterCommercial) { $where[] = 'v.commercial_id = :c'; $params[':c'] = $filterCommercial; }
$sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    $stmt = $pdo->prepare("
        SELECT v.id, v.date_visite, v.compte_rendu, v.date_creation,
               p.nom AS p_nom, p.prenom AS p_prenom, p.ville AS p_ville,
               COALESCE(NULLIF(a.nom, ''), a.username) AS commercial_nom
        FROM visites v
        LEFT JOIN praticiens p ON v.praticien_id = p.id
        LEFT JOIN admins a     ON v.commercial_id = a.id
        $sqlWhere
        ORDER BY v.date_visite DESC, v.id DESC
    ");
    $stmt->execute($params);
    $visites = $stmt->fetchAll();
} catch (Exception $e) {
    $visites = [];
}
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Visites · ORAVIE Admin</title>
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

    .main { max-width:1100px; margin:0 auto; padding:2rem 1.5rem; }
    .page-title { font-size:1.4rem; font-weight:700; margin-bottom:1.5rem; }
    .filters { display:flex; gap:0.6rem; flex-wrap:wrap; align-items:flex-end; margin-bottom:1.5rem; }
    .filters select { border:1.5px solid #E2E9DA; border-radius:0.7rem; padding:9px 12px; font-size:0.88rem; font-family:inherit; outline:none; background:#fff; }
    .filters .btn { background:#2F4B3C; color:#fff; border:none; border-radius:0.7rem; padding:9px 18px; font-weight:700; cursor:pointer; font-size:0.88rem; }
    .filters a.clear { align-self:center; font-size:0.82rem; color:#7D8F76; text-decoration:none; }

    .card { background:#fff; border-radius:1.2rem; box-shadow:0 2px 10px rgba(0,0,0,0.05); overflow:hidden; }
    table { width:100%; border-collapse:collapse; }
    thead th { background:#F4F7F1; padding:12px 16px; text-align:left; font-size:0.7rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#7D8F76; }
    td { padding:12px 16px; font-size:0.88rem; border-top:1px solid #F0F4EC; vertical-align:top; }
    .cr { color:#4A735C; font-size:0.85rem; white-space:pre-wrap; max-width:420px; }
    .empty { text-align:center; padding:3rem; color:#92A389; }
    .empty i { font-size:2rem; display:block; margin-bottom:0.5rem; }
    @media (max-width:768px){ nav{flex-wrap:wrap;height:auto;padding:0.5rem 1rem;} .nav-links{width:100%;} table{display:block;overflow-x:auto;} }
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
    <a href="visites.php" class="active"><i class="fas fa-map-marked-alt"></i> Visites</a>
    <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Déconnexion</a>
  </div>
</nav>

<div class="main">
  <div class="page-title"><i class="fas fa-map-marked-alt"></i> Historique des visites</div>

  <form method="GET" class="filters">
    <select name="commercial">
      <option value="">Tous les commerciaux</option>
      <?php foreach ($commerciaux as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $filterCommercial == $c['id'] ? 'selected' : '' ?>>
          <?= htmlspecialchars(($c['nom'] ?? '') !== '' ? $c['nom'] : $c['username']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <select name="praticien">
      <option value="">Tous les praticiens</option>
      <?php foreach ($praticiens as $p): ?>
        <option value="<?= (int)$p['id'] ?>" <?= $filterPraticien == $p['id'] ? 'selected' : '' ?>>
          <?= htmlspecialchars($p['prenom'] . ' ' . $p['nom']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <button type="submit" class="btn"><i class="fas fa-filter"></i> Filtrer</button>
    <?php if ($filterPraticien || $filterCommercial): ?><a href="visites.php" class="clear">Réinitialiser</a><?php endif; ?>
  </form>

  <div class="card">
    <?php if (empty($visites)): ?>
      <div class="empty"><i class="fas fa-inbox"></i> Aucune visite enregistrée.</div>
    <?php else: ?>
    <table>
      <thead>
        <tr><th>Date</th><th>Praticien</th><th>Commercial</th><th>Compte rendu</th></tr>
      </thead>
      <tbody>
      <?php foreach ($visites as $v): ?>
        <tr>
          <td><?= htmlspecialchars(date('d/m/Y', strtotime($v['date_visite']))) ?></td>
          <td>
            <strong><?= htmlspecialchars(trim(($v['p_prenom'] ?? '') . ' ' . ($v['p_nom'] ?? '')) ?: '— supprimé —') ?></strong>
            <?php if (!empty($v['p_ville'])): ?><br><small style="color:#92A389;"><?= htmlspecialchars($v['p_ville']) ?></small><?php endif; ?>
          </td>
          <td><?= htmlspecialchars($v['commercial_nom'] ?? '—') ?></td>
          <td class="cr"><?= nl2br(htmlspecialchars($v['compte_rendu'] ?? '')) ?: '—' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
