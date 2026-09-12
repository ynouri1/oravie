<?php
require_once 'auth.php';
requireAuth();

$pdo = getDB();
$msg = '';
$msgType = 'success';

// Migration : colonne prix_praticien (prix spécial, jamais exposé au public)
try {
    if (empty($pdo->query("SHOW COLUMNS FROM produits LIKE 'prix_praticien'")->fetchAll())) {
        $pdo->exec("ALTER TABLE produits ADD COLUMN prix_praticien DECIMAL(8,2) NULL DEFAULT NULL");
    }
} catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['id'])) {
    $pid = filter_var($_POST['id'], FILTER_VALIDATE_INT);
    if ($pid && $_POST['action'] === 'update') {
        $prix  = filter_var($_POST['prix']  ?? '', FILTER_VALIDATE_FLOAT);
        $stock = filter_var($_POST['stock'] ?? '', FILTER_VALIDATE_INT);
        $actif = isset($_POST['actif']) ? 1 : 0;
        $prixPraticienRaw = trim($_POST['prix_praticien'] ?? '');
        $prixPraticien = $prixPraticienRaw === '' ? null : filter_var($prixPraticienRaw, FILTER_VALIDATE_FLOAT);
        if ($prix !== false && $stock !== false && $prix >= 0 && $stock >= 0 && $prixPraticien !== false) {
            $pdo->prepare("UPDATE produits SET prix = :p, stock = :s, actif = :a, prix_praticien = :pp WHERE id = :id")
                ->execute([':p' => $prix, ':s' => $stock, ':a' => $actif, ':pp' => $prixPraticien, ':id' => $pid]);
            $msg = 'Produit mis à jour avec succès.';
        } else {
            $msg = 'Valeurs invalides.'; $msgType = 'error';
        }
    }
    header('Location: produits.php?msg=' . urlencode($msg) . '&t=' . $msgType); exit;
}

$produits = $pdo->query("SELECT * FROM produits ORDER BY volume_ml ASC")->fetchAll();
$msg      = htmlspecialchars($_GET['msg'] ?? '');
$msgType  = $_GET['t'] ?? 'success';
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Produits · ORAVIE Admin</title>
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

    .main { max-width:800px; margin:0 auto; padding:2rem 1.5rem; }
    .page-title { font-size:1.4rem; font-weight:700; margin-bottom:1.5rem; }

    .success { background:#D1FAE5; color:#065F46; border:1px solid #A7F3D0; border-radius:0.8rem; padding:10px 14px; font-size:0.85rem; margin-bottom:1.5rem; }
    .error-msg { background:#FEE2E2; color:#DC2626; border:1px solid #FECACA; border-radius:0.8rem; padding:10px 14px; font-size:0.85rem; margin-bottom:1.5rem; }

    .product-card { background:#fff; border-radius:1.2rem; padding:1.5rem; box-shadow:0 2px 10px rgba(0,0,0,0.05); margin-bottom:1.2rem; }
    .prod-header { display:flex; align-items:center; gap:12px; margin-bottom:1.2rem; }
    .prod-vol { background:#DCE9D4; color:#2F4B3C; font-weight:800; padding:4px 14px; border-radius:1rem; font-size:0.85rem; }
    .prod-name { font-weight:700; font-size:1rem; }
    .prod-status-badge { margin-left:auto; font-size:0.75rem; font-weight:700; padding:3px 10px; border-radius:1rem; }
    .prod-status-badge.active { background:#D1FAE5; color:#059669; }
    .prod-status-badge.inactive { background:#FEE2E2; color:#DC2626; }

    .edit-row { display:flex; gap:1rem; flex-wrap:wrap; align-items:flex-end; }
    .edit-group { display:flex; flex-direction:column; gap:5px; }
    .edit-group label { font-size:0.7rem; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:#7D8F76; }
    .edit-group input[type=number] { border:1.5px solid #E2E9DA; border-radius:0.7rem; padding:9px 12px; font-size:0.9rem; font-family:inherit; width:110px; outline:none; transition:0.2s; }
    .edit-group input[type=number]:focus { border-color:#4A735C; }

    /* Toggle switch */
    .toggle-wrap { display:flex; flex-direction:column; gap:5px; }
    .toggle-wrap > span { font-size:0.7rem; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:#7D8F76; }
    .toggle { display:flex; align-items:center; gap:8px; cursor:pointer; padding:9px 0; }
    .toggle input { display:none; }
    .toggle-slider { width:44px; height:24px; background:#E2E9DA; border-radius:12px; position:relative; transition:0.2s; flex-shrink:0; }
    .toggle-slider::after { content:''; position:absolute; width:18px; height:18px; background:#fff; border-radius:50%; top:3px; left:3px; transition:0.2s; box-shadow:0 1px 3px rgba(0,0,0,0.2); }
    .toggle input:checked + .toggle-slider { background:#4A735C; }
    .toggle input:checked + .toggle-slider::after { left:23px; }
    .toggle-text { font-size:0.85rem; font-weight:600; }

    /* Stock bar */
    .stock-bar { height:5px; background:#E2E9DA; border-radius:3px; margin-top:5px; overflow:hidden; width:110px; }
    .stock-fill { height:100%; border-radius:3px; transition:width 0.3s; }

    .btn-save { background:#2F4B3C; color:#fff; border:none; border-radius:0.8rem; padding:10px 22px; font-size:0.9rem; font-weight:700; cursor:pointer; transition:0.2s; align-self:flex-end; white-space:nowrap; }
    .btn-save:hover { background:#4A735C; }

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
        min-width: 100px;
        padding: 0.5rem;
        font-size: 0.7rem;
        text-align: center;
        border-radius: 0;
      }
      .main { max-width: 100%; padding: 1.5rem 1rem; }
      .page-title { font-size: 1.2rem; margin-bottom: 1rem; }
      
      .product-card { padding: 1.2rem; margin-bottom: 1rem; }
      .prod-header { flex-wrap: wrap; gap: 0.75rem; }
      .prod-name { flex: 1; min-width: 100%; }
      .prod-status-badge { margin-left: 0; }
      
      .edit-row { flex-direction: column; gap: 1rem; align-items: stretch; }
      .edit-group { align-items: stretch; }
      .edit-group input[type=number] { width: 100%; }
      
      .toggle-wrap { margin: 1rem 0; }
      .btn-save { width: 100%; align-self: stretch; }
    }

    @media (max-width: 480px) {
      nav { padding: 0 0.75rem; }
      .main { padding: 1rem 0.75rem; }
      .page-title { font-size: 1rem; }
      .product-card { padding: 1rem; }
    }
  </style>
</head>
<body>
<nav>
  <div class="nav-brand"><i class="fas fa-leaf"></i> ORAVIE <span>Admin</span></div>
  <div class="nav-links">
    <a href="dashboard.php"><i class="fas fa-list-alt"></i> Commandes</a>
    <a href="commande_ajouter.php"><i class="fas fa-plus-circle"></i> Nouvelle commande</a>
    <a href="produits.php" class="active"><i class="fas fa-box"></i> Produits</a>
    <a href="praticiens.php"><i class="fas fa-stethoscope"></i> Praticiens</a>
    <a href="stats.php"><i class="fas fa-chart-bar"></i> Statistiques</a>
    <a href="depenses.php"><i class="fas fa-receipt"></i> Dépenses</a>
    <a href="mouvements.php"><i class="fas fa-boxes"></i> Stock lots</a>
    <a href="feedback_avis.php"><i class="fas fa-comments"></i> Avis clients</a>
    <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Déconnexion</a>
  </div>
</nav>

<div class="main">
  <div class="page-title"><i class="fas fa-box"></i> Gestion des produits</div>

  <?php if ($msg): ?>
    <div class="<?= $msgType === 'error' ? 'error-msg' : 'success' ?>">
      <i class="fas fa-<?= $msgType === 'error' ? 'exclamation-circle' : 'check-circle' ?>"></i> <?= $msg ?>
    </div>
  <?php endif; ?>

  <?php foreach ($produits as $p):
    $stockPct   = $p['stock'] > 0 ? min(100, ($p['stock'] / 100) * 100) : 0;
    $fillColor  = $p['stock'] == 0 ? '#EF4444' : ($p['stock'] <= 10 ? '#F59E0B' : '#4A735C');
    $stockLabel = $p['stock'] == 0 ? 'Épuisé' : 'Stock : ' . $p['stock'];
    $stockColor = $p['stock'] == 0 ? '#DC2626' : ($p['stock'] <= 10 ? '#D97706' : '#059669');
  ?>
  <div class="product-card">
    <div class="prod-header">
      <span class="prod-vol"><?= $p['volume_ml'] ?> ml</span>
      <span class="prod-name"><?= htmlspecialchars($p['nom']) ?></span>
      <span class="prod-status-badge <?= $p['actif'] ? 'active' : 'inactive' ?>"><?= $p['actif'] ? 'Actif' : 'Inactif' ?></span>
    </div>
    <form method="POST">
      <input type="hidden" name="action" value="update">
      <input type="hidden" name="id" value="<?= $p['id'] ?>">
      <div class="edit-row">
        <div class="edit-group">
          <label>Prix (DT)</label>
          <input type="number" name="prix" value="<?= $p['prix'] ?>" min="0" step="0.5" required>
        </div>
        <div class="edit-group">
          <label>Prix praticien (DT)</label>
          <input type="number" name="prix_praticien" value="<?= $p['prix_praticien'] !== null ? $p['prix_praticien'] : '' ?>" min="0" step="0.5" placeholder="Optionnel">
        </div>
        <div class="edit-group">
          <label>Stock <span style="color:<?= $stockColor ?>; font-weight:700;">(<?= $stockLabel ?>)</span></label>
          <input type="number" name="stock" value="<?= $p['stock'] ?>" min="0" required>
          <div class="stock-bar">
            <div class="stock-fill" style="width:<?= $stockPct ?>%; background:<?= $fillColor ?>;"></div>
          </div>
        </div>
        <div class="toggle-wrap">
          <span>Actif</span>
          <label class="toggle">
            <input type="checkbox" name="actif" <?= $p['actif'] ? 'checked' : '' ?>>
            <span class="toggle-slider"></span>
            <span class="toggle-text"><?= $p['actif'] ? 'Oui' : 'Non' ?></span>
          </label>
        </div>
        <button type="submit" class="btn-save"><i class="fas fa-save"></i> Enregistrer</button>
      </div>
    </form>
  </div>
  <?php endforeach; ?>
</div>
</body>
</html>
