<?php
require_once 'auth.php';
requireAuth();

$pdo = getDB();
$id  = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
if (!$id) { header('Location: dashboard.php'); exit; }

// Migration : colonne lot_id sur commandes + chargement des lots
try {
    if (empty($pdo->query("SHOW COLUMNS FROM commandes LIKE 'lot_id'")->fetchAll())) {
        $pdo->exec("ALTER TABLE commandes ADD COLUMN lot_id INT NULL DEFAULT NULL");
    }
    $lots = $pdo->query("SELECT * FROM lots WHERE actif = 1 ORDER BY numero ASC")->fetchAll();
} catch (Exception $e) {
    $lots = [];
}

// Charger les praticiens
$praticiens = [];
try {
    $praticiens = $pdo->query("SELECT id, prenom, nom, specialite FROM praticiens WHERE actif = 1 ORDER BY nom, prenom ASC")->fetchAll();
} catch (Exception $e) {
    $praticiens = [];
}

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['statut'])) {
    $validStatuts = ['nouvelle', 'confirmée', 'expédiée', 'livrée', 'annulée'];
    if (in_array($_POST['statut'], $validStatuts)) {
        $pdo->prepare("UPDATE commandes SET statut = :s WHERE id = :id")
            ->execute([':s' => $_POST['statut'], ':id' => $id]);
    }
    header("Location: commande.php?id=$id&updated=1"); exit;
}

// Handle lot assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_lot') {
    $lot_id = filter_var($_POST['lot_id'] ?? '', FILTER_VALIDATE_INT) ?: null;
    $pdo->prepare("UPDATE commandes SET lot_id = :lid WHERE id = :id")
        ->execute([':lid' => $lot_id, ':id' => $id]);
    header("Location: commande.php?id=$id&updated=1"); exit;
}

// Handle praticien assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_praticien') {
    $praticien_id = filter_var($_POST['praticien_id'] ?? '', FILTER_VALIDATE_INT) ?: null;
    $pdo->prepare("UPDATE commandes SET praticien_id = :pid WHERE id = :id")
        ->execute([':pid' => $praticien_id, ':id' => $id]);
    header("Location: commande.php?id=$id&updated=1"); exit;
}

// Vérifier si les colonnes/tables requises existent
$hasPraticienColumn = false;
$hasPraticiens = false;
try {
    $pdo->query("SELECT praticien_id FROM commandes LIMIT 1");
    $hasPraticienColumn = true;
} catch (Exception $e) {
    // Colonne n'existe pas
}

try {
    $pdo->query("SELECT 1 FROM praticiens LIMIT 1");
    $hasPraticiens = true;
} catch (Exception $e) {
    // Table n'existe pas
}

$sql = "SELECT c.*";
if ($hasPraticienColumn && $hasPraticiens) {
    $sql .= ", COALESCE(CONCAT(p.prenom, ' ', p.nom), NULL) AS praticien_nom,
             COALESCE(p.specialite, NULL) AS praticien_specialite";
} else {
    $sql .= ", NULL AS praticien_nom, NULL AS praticien_specialite";
}
$sql .= " FROM commandes c";
if ($hasPraticienColumn && $hasPraticiens) {
    $sql .= " LEFT JOIN praticiens p ON c.praticien_id = p.id";
}
$sql .= " WHERE c.id = :id";

$stmt = $pdo->prepare($sql);
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();
if (!$row) { header('Location: dashboard.php'); exit; }

$d      = json_decode($row['donnees'], true);
$lignes = $d['lignes'] ?? [];
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Commande #<?= $id ?> · ORAVIE Admin</title>
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

    .main { max-width:960px; margin:0 auto; padding:2rem 1.5rem; }
    .page-header { display:flex; align-items:center; gap:1rem; margin-bottom:2rem; flex-wrap:wrap; }
    .page-header h1 { font-size:1.4rem; font-weight:700; }
    .back-link { color:#4A735C; text-decoration:none; font-size:0.85rem; display:flex; align-items:center; gap:5px; padding:6px 12px; background:#fff; border-radius:0.8rem; }
    .back-link:hover { background:#EFF3EA; }

    .grid2 { display:flex; gap:1.5rem; flex-wrap:nowrap; overflow-x:auto; padding-bottom:0.5rem; }
    .col { flex:1; min-width:350px; display:flex; flex-direction:column; gap:1.5rem; }

    .card { background:#fff; border-radius:1.2rem; padding:1.5rem; box-shadow:0 2px 10px rgba(0,0,0,0.05); }
    .card-title { font-size:0.78rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#7D8F76; margin-bottom:1.2rem; display:flex; align-items:center; gap:8px; }

    .info-row { display:flex; justify-content:space-between; align-items:flex-start; padding:8px 0; border-bottom:1px solid #F0F4EC; font-size:0.88rem; gap:1rem; }
    .info-row:last-child { border-bottom:none; }
    .info-label { color:#92A389; font-weight:600; white-space:nowrap; }
    .info-val { font-weight:500; text-align:right; }

    table { width:100%; border-collapse:collapse; }
    thead th { font-size:0.7rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#7D8F76; padding:8px 0; border-bottom:2px solid #F0F4EC; text-align:left; }
    tbody td { padding:10px 0; font-size:0.88rem; border-bottom:1px solid #F0F4EC; }
    .total-row td { font-weight:800; font-size:1rem; color:#2F4B3C; border-top:2px solid #C6A43F; border-bottom:none; padding-top:14px; }

    .status-form { display:flex; gap:0.8rem; align-items:center; flex-wrap:wrap; }
    .status-form select { border:1.5px solid #E2E9DA; border-radius:0.8rem; padding:10px 14px; font-size:0.9rem; font-family:inherit; flex:1; min-width:150px; outline:none; }
    .status-form select:focus { border-color:#4A735C; }
    .btn-save { background:#2F4B3C; color:#fff; border:none; border-radius:0.8rem; padding:10px 20px; font-size:0.9rem; font-weight:700; cursor:pointer; transition:0.2s; white-space:nowrap; }
    .btn-save:hover { background:#4A735C; }

    .success { background:#D1FAE5; color:#065F46; border:1px solid #A7F3D0; border-radius:0.8rem; padding:10px 14px; font-size:0.85rem; margin-bottom:1.5rem; }

    /* ===== RESPONSIVE MOBILE ===== */
    @media (max-width: 768px) {
      body { padding: 1rem 0.75rem; }
      nav { padding: 0 1rem; flex-wrap: wrap; height: auto; }
      .nav-brand { flex: 1; min-width: 200px; padding: 0.75rem 0; }
      .nav-links { width: 100%; flex-direction: column; gap: 0; margin-top: 0.5rem; }
      .nav-links a { width: 100%; padding: 0.75rem 1rem; text-align: left; border-radius: 0; }
      .nav-links a.logout { color: #F87171; }

      .main { max-width: 100%; padding: 1rem 0.75rem; }
      .page-header { flex-direction: column; align-items: flex-start; gap: 0.5rem; margin-bottom: 1.5rem; }
      .page-header h1 { font-size: 1.1rem; width: 100%; }
      .back-link { width: 100%; justify-content: flex-start; }

      .grid2 { flex-direction: column; gap: 1rem; }
      .col { min-width: 100%; }

      .card { padding: 1rem; border-radius: 1rem; }
      .card-title { font-size: 0.7rem; margin-bottom: 1rem; }

      .info-row { flex-direction: column; gap: 0.25rem; padding: 0.75rem 0; }
      .info-label { text-align: left; }
      .info-val { text-align: left; }

      table { font-size: 0.8rem; }
      thead th { font-size: 0.65rem; padding: 0.5rem 0; }
      tbody td { padding: 0.75rem 0; font-size: 0.8rem; }
      .total-row td { font-size: 0.95rem; padding-top: 1rem; }

      .status-form { flex-direction: column; }
      .status-form select { width: 100%; }
      .btn-save { width: 100%; }
    }

    @media (max-width: 480px) {
      body { padding: 0.75rem 0.5rem; }
      nav { padding: 0 0.75rem; }
      .main { padding: 0.75rem 0.5rem; }
      
      .page-header h1 { font-size: 1rem; }
      .card { padding: 0.75rem; }
      
      table { font-size: 0.75rem; }
      thead th { font-size: 0.6rem; }
      tbody td { padding: 0.5rem 0; }
      
      .status-form select { font-size: 0.85rem; padding: 0.75rem; }
      .btn-save { padding: 0.75rem 1rem; font-size: 0.85rem; }
    }
  </style>
</head>
<body>
<nav>
  <div class="nav-brand"><i class="fas fa-leaf"></i> ORAVIE <span>Admin</span></div>
  <div class="nav-links">
    <a href="dashboard.php"><i class="fas fa-list-alt"></i> Commandes</a>
    <a href="produits.php"><i class="fas fa-box"></i> Produits</a>
    <a href="depenses.php"><i class="fas fa-receipt"></i> Dépenses</a>
    <a href="mouvements.php"><i class="fas fa-boxes"></i> Stock lots</a>
    <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Déconnexion</a>
  </div>
</nav>

<div class="main">
  <div class="page-header">
    <a href="dashboard.php" class="back-link"><i class="fas fa-arrow-left"></i> Retour</a>
    <h1>Commande #<?= $id ?></h1>
    <?= statusBadge($row['statut']) ?>
    <small style="color:#92A389; font-size:0.85rem;"><?= date('d/m/Y à H:i', strtotime($row['date_commande'])) ?></small>
  </div>

  <?php if (isset($_GET['updated'])): ?>
    <div class="success"><i class="fas fa-check-circle"></i> Statut mis à jour avec succès.</div>
  <?php endif; ?>

  <div class="grid2">
    <!-- Colonne gauche : client -->
    <div class="col">
      <div class="card">
        <div class="card-title"><i class="fas fa-user"></i> Informations client</div>
        <?php
        $fields = [
            'Civilité'    => $d['civilite']    ?? '',
            'Prénom'      => $d['prenom']      ?? '',
            'Nom'         => $d['nom']         ?? '',
            'Email'       => $d['email']       ?? '',
            'Téléphone'   => $d['telephone']   ?? '',
            'Adresse'     => $d['adresse']     ?? '',
            'Code postal' => $d['code_postal'] ?? '',
            'Ville'       => $d['ville']       ?? '',
            'Instructions'=> $d['instructions']?? '',
        ];
        foreach ($fields as $label => $val):
            if (!$val) continue; ?>
        <div class="info-row">
          <span class="info-label"><?= $label ?></span>
          <span class="info-val"><?= nl2br(htmlspecialchars($val)) ?></span>
        </div>
        <?php endforeach; ?>
        <?php if ($row['praticien_nom']): ?>
        <div style="margin-top:1rem; padding-top:1rem; border-top:1px solid #E2E9DA;">
          <div class="info-row">
            <span class="info-label"><i class="fas fa-stethoscope"></i> Praticien</span>
            <span class="info-val"><?= htmlspecialchars($row['praticien_nom']) ?></span>
          </div>
          <?php if ($row['praticien_specialite']): ?>
          <div class="info-row">
            <span class="info-label">Spécialité</span>
            <span class="info-val"><?= htmlspecialchars($row['praticien_specialite']) ?></span>
          </div>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Colonne droite : produits + statut -->
    <div class="col">
      <div class="card">
        <div class="card-title"><i class="fas fa-shopping-bag"></i> Produits commandés</div>
        <table>
          <thead>
            <tr><th>Produit</th><th>Qté</th><th>P.U.</th><th>Sous-total</th></tr>
          </thead>
          <tbody>
          <?php foreach ($lignes as $l): ?>
            <tr>
              <td><?= htmlspecialchars($l['produit_nom']) ?></td>
              <td><?= (int)$l['quantite'] ?></td>
              <td><?= number_format((float)$l['prix_unitaire'], 2) ?> DT</td>
              <td><?= number_format((float)$l['sous_total'], 2) ?> DT</td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr style="font-weight:600; background:#F4F7F1;">
              <td colspan="3">Sous-total</td>
              <td><?= number_format((float)($d['prix_total'] ?? 0), 2) ?> DT</td>
            </tr>
            <tr style="font-weight:600; background:#F4F7F1;">
              <td colspan="3">Frais de livraison</td>
              <td><?= number_format((float)($d['frais_livraison'] ?? 8.00), 2) ?> DT</td>
            </tr>
            <tr class="total-row">
              <td colspan="3">TOTAL TTC</td>
              <td><?= number_format((float)($d['prix_total_ttc'] ?? ((float)($d['prix_total'] ?? 0) + 8.00)), 2) ?> DT</td>
            </tr>
          </tfoot>
        </table>
      </div>

      <div class="card">
        <div class="card-title"><i class="fas fa-exchange-alt"></i> Modifier le statut</div>
        <form method="POST" class="status-form">
          <select name="statut">
            <?php foreach (['nouvelle', 'confirmée', 'expédiée', 'livrée', 'annulée'] as $s): ?>
            <option value="<?= $s ?>" <?= $row['statut'] === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn-save"><i class="fas fa-save"></i> Enregistrer</button>
        </form>
      </div>

      <?php if (!empty($lots)): ?>
      <div class="card">
        <div class="card-title"><i class="fas fa-layer-group"></i> Lot associé</div>
        <form method="POST" class="status-form">
          <input type="hidden" name="action" value="update_lot">
          <select name="lot_id">
            <option value="">— Aucun lot —</option>
            <?php foreach ($lots as $lot): ?>
            <option value="<?= $lot['id'] ?>" <?= ($row['lot_id'] ?? null) == $lot['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($lot['nom'] ?: 'Lot ' . $lot['numero']) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn-save"><i class="fas fa-save"></i> Enregistrer</button>
        </form>
      </div>
      <?php endif; ?>

      <?php if (!empty($praticiens)): ?>
      <div class="card">
        <div class="card-title"><i class="fas fa-stethoscope"></i> Praticien prescripteur</div>
        <form method="POST" class="status-form">
          <input type="hidden" name="action" value="update_praticien">
          <select name="praticien_id">
            <option value="">— Aucun praticien —</option>
            <?php foreach ($praticiens as $p): ?>
            <option value="<?= $p['id'] ?>" <?= ($row['praticien_id'] ?? null) == $p['id'] ? 'selected' : '' ?>>
              <?= htmlspecialchars($p['prenom'] . ' ' . $p['nom'] . ($p['specialite'] ? ' (' . $p['specialite'] . ')' : '')) ?>
            </option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn-save"><i class="fas fa-save"></i> Enregistrer</button>
        </form>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>
</body>
</html>
