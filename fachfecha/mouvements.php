<?php
require_once 'auth.php';
requireAuth();

$pdo = getDB();

// ── TABLE ─────────────────────────────────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS mouvements_lot (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        lot_id      INT NOT NULL,
        type        ENUM('production','vendu','défectueux','échantillon','retour') NOT NULL,
        quantite    INT NOT NULL,
        date_mvt    DATE NOT NULL,
        notes       TEXT,
        depense_id  INT NULL DEFAULT NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
if (empty($pdo->query("SHOW COLUMNS FROM mouvements_lot LIKE 'depense_id'")->fetchAll())) {
    $pdo->exec("ALTER TABLE mouvements_lot ADD COLUMN depense_id INT NULL DEFAULT NULL");
}

$lots = $pdo->query("SELECT * FROM lots ORDER BY numero ASC")->fetchAll();
$msg  = '';

// ── SUPPRIMER ─────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
    if ($id) {
        $pdo->prepare("DELETE FROM mouvements_lot WHERE id = :id")->execute([':id' => $id]);
        $msg = 'ok:Mouvement supprimé.';
    }
    $qs = http_build_query(array_filter(['lot' => $_POST['flot'] ?? '']));
    header('Location: mouvements.php?' . ($qs ?: '') . ($msg ? '&msg=' . urlencode($msg) : '')); exit;
}

// ── AJOUTER ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $validTypes = ['production', 'vendu', 'défectueux', 'échantillon', 'retour'];
    $lot_id   = filter_var($_POST['lot_id']  ?? '', FILTER_VALIDATE_INT) ?: null;
    $type     = in_array($_POST['type'] ?? '', $validTypes) ? $_POST['type'] : '';
    $quantite = filter_var($_POST['quantite'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $date     = $_POST['date_mvt'] ?? '';
    $notes    = trim(strip_tags($_POST['notes'] ?? ''));

    if ($lot_id && $type && $quantite && $date) {
        $pdo->prepare("INSERT INTO mouvements_lot (lot_id, type, quantite, date_mvt, notes) VALUES (:l,:t,:q,:d,:n)")
            ->execute([':l'=>$lot_id, ':t'=>$type, ':q'=>$quantite, ':d'=>$date, ':n'=>$notes ?: null]);
        $msg = 'ok:Mouvement ajouté.';
    } else {
        $msg = 'err:Veuillez remplir tous les champs obligatoires.';
    }
    $qs = http_build_query(array_filter(['lot' => $_POST['flot'] ?? '']));
    header('Location: mouvements.php?' . ($qs ?: '') . '&msg=' . urlencode($msg)); exit;
}

// ── FILTRES ───────────────────────────────────────────────────────────────────
$filterLot = filter_var($_GET['lot'] ?? '', FILTER_VALIDATE_INT) ?: '';
if (isset($_GET['msg'])) $msg = $_GET['msg'];

// ── VENDUS AUTO : quantités depuis commandes livrées liées à un lot ───────────
$vendusAuto = []; // lot_id => quantite totale
$cmdLivrees = $pdo->query("
    SELECT lot_id, donnees->>'$.lignes' AS lignes_json
    FROM commandes
    WHERE statut = 'livrée' AND lot_id IS NOT NULL
")->fetchAll();
foreach ($cmdLivrees as $cmd) {
    $lignes = json_decode($cmd['lignes_json'], true) ?? [];
    $total  = 0;
    foreach ($lignes as $ligne) {
        $total += (int)($ligne['quantite'] ?? 0);
    }
    $vendusAuto[$cmd['lot_id']] = ($vendusAuto[$cmd['lot_id']] ?? 0) + $total;
}

// ── RÉSUMÉ PAR LOT ────────────────────────────────────────────────────────────
$resume = $pdo->query("
    SELECT
        l.id, l.numero, l.nom,
        COALESCE(SUM(CASE WHEN m.type = 'production'  THEN m.quantite ELSE 0 END), 0) AS produit,
        COALESCE(SUM(CASE WHEN m.type = 'vendu'       THEN m.quantite ELSE 0 END), 0) AS vendu_manuel,
        COALESCE(SUM(CASE WHEN m.type = 'défectueux'  THEN m.quantite ELSE 0 END), 0) AS defectueux,
        COALESCE(SUM(CASE WHEN m.type = 'échantillon' THEN m.quantite ELSE 0 END), 0) AS echantillon,
        COALESCE(SUM(CASE WHEN m.type = 'retour'      THEN m.quantite ELSE 0 END), 0) AS retour
    FROM lots l
    LEFT JOIN mouvements_lot m ON m.lot_id = l.id
    GROUP BY l.id
    ORDER BY l.numero ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Fusionner vendus auto + manuels et calculer stock restant
foreach ($resume as &$r) {
    $r['vendu_auto'] = $vendusAuto[$r['id']] ?? 0;
    $r['vendu']      = $r['vendu_manuel'] + $r['vendu_auto'];
    $r['stock_restant'] = $r['produit'] + $r['retour']
                        - $r['vendu'] - $r['defectueux'] - $r['echantillon'];
}
unset($r);

// ── LISTE DES MOUVEMENTS ──────────────────────────────────────────────────────
$where = []; $params = [];
if ($filterLot) {
    $where[] = "m.lot_id = :lid"; $params[':lid'] = $filterLot;
}
$whereSQL = $where ? "WHERE " . implode(' AND ', $where) : '';
$mouvements = $pdo->prepare("
    SELECT m.*, l.numero AS lot_numero, l.nom AS lot_nom
    FROM mouvements_lot m
    JOIN lots l ON l.id = m.lot_id
    $whereSQL
    ORDER BY m.date_mvt DESC, m.id DESC
");
$mouvements->execute($params);
$mouvements = $mouvements->fetchAll();

// ── CONFIGS ───────────────────────────────────────────────────────────────────
$typeConfig = [
    'production'  => ['label'=>'Production',   'icon'=>'fa-industry',       'color'=>'#10B981', 'bg'=>'#D1FAE5', 'sign'=>'+'],
    'vendu'       => ['label'=>'Vendu',         'icon'=>'fa-shopping-cart',  'color'=>'#3B82F6', 'bg'=>'#DBEAFE', 'sign'=>'-'],
    'défectueux'  => ['label'=>'Défectueux',    'icon'=>'fa-times-circle',   'color'=>'#EF4444', 'bg'=>'#FEE2E2', 'sign'=>'-'],
    'échantillon' => ['label'=>'Échantillon',   'icon'=>'fa-gift',           'color'=>'#F59E0B', 'bg'=>'#FEF3C7', 'sign'=>'-'],
    'retour'      => ['label'=>'Retour stock',  'icon'=>'fa-undo',           'color'=>'#8B5CF6', 'bg'=>'#EDE9FE', 'sign'=>'+'],
];
$lotColors = ['#2F4B3C','#C6A43F','#3B82F6','#8B5CF6','#F97316','#EF4444','#10B981'];
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Stock par lot · ORAVIE Admin</title>
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

    .main { max-width:1280px; margin:0 auto; padding:2rem 1.5rem 4rem; }
    .page-title { font-size:1.4rem; font-weight:700; margin-bottom:1.5rem; }

    .toast { padding:12px 16px; border-radius:0.7rem; margin-bottom:1.2rem; font-size:0.88rem; font-weight:600; }
    .toast.ok  { background:#EAF5E9; color:#2F6B3A; border:1.5px solid #A8D5A2; }
    .toast.err { background:#FDF0F0; color:#B03A2E; border:1.5px solid #F1948A; }

    /* Résumé cards */
    .resume-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(240px,1fr)); gap:1rem; margin-bottom:2rem; }
    .lot-card { background:#fff; border-radius:1.2rem; padding:1.2rem 1.4rem; box-shadow:0 2px 10px rgba(0,0,0,0.05); border-top:4px solid #ccc; }
    .lot-card-title { font-weight:800; font-size:1rem; margin-bottom:0.9rem; display:flex; align-items:center; gap:8px; }
    .lot-num { width:28px; height:28px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:0.75rem; font-weight:800; color:#fff; flex-shrink:0; }
    .lot-rows { display:flex; flex-direction:column; gap:5px; }
    .lot-row { display:flex; justify-content:space-between; align-items:center; font-size:0.82rem; }
    .lot-row-label { color:#7D8F76; display:flex; align-items:center; gap:6px; }
    .lot-row-val { font-weight:700; }
    .lot-row-val.green  { color:#059669; }
    .lot-row-val.red    { color:#DC2626; }
    .lot-row-val.amber  { color:#D97706; }
    .lot-row-val.blue   { color:#2563EB; }
    .lot-row-val.purple { color:#7C3AED; }
    .divider { border:none; border-top:1px solid #F0F4EC; margin:6px 0; }
    .stock-badge { display:inline-flex; align-items:center; gap:5px; padding:4px 12px; border-radius:99px; font-size:0.85rem; font-weight:800; }
    .stock-badge.ok   { background:#D1FAE5; color:#059669; }
    .stock-badge.warn { background:#FEF3C7; color:#D97706; }
    .stock-badge.zero { background:#FEE2E2; color:#DC2626; }

    /* Filtre lot */
    .lot-pills { display:flex; gap:0.6rem; flex-wrap:wrap; margin-bottom:1.5rem; }
    .lot-pill { background:#fff; border-radius:2rem; padding:6px 16px; box-shadow:0 1px 6px rgba(0,0,0,0.07); font-size:0.83rem; font-weight:600; text-decoration:none; color:#4A735C; border:2px solid transparent; transition:0.2s; }
    .lot-pill:hover { border-color:#C6A43F; }
    .lot-pill.active { border-color:#2F4B3C; background:#EBF2E8; color:#2F4B3C; }

    /* Layout */
    .layout { display:flex; gap:1.5rem; align-items:flex-start; flex-wrap:wrap; }
    .col-left  { flex:1; min-width:300px; }
    .col-right { width:320px; min-width:280px; }

    .card { background:#fff; border-radius:1.2rem; box-shadow:0 2px 10px rgba(0,0,0,0.05); overflow:hidden; }
    .card-header { background:#2F4B3C; color:#fff; padding:14px 20px; font-weight:700; font-size:0.9rem; display:flex; align-items:center; gap:8px; }
    .card-body  { padding:20px; }

    .form-group { display:flex; flex-direction:column; gap:5px; margin-bottom:12px; }
    .form-group label { font-size:0.75rem; font-weight:600; color:#7D8F76; text-transform:uppercase; letter-spacing:0.5px; }
    .form-group input, .form-group select, .form-group textarea {
        padding:9px 12px; border:1.5px solid #E2E9DA; border-radius:0.6rem;
        font-size:0.88rem; font-family:inherit; color:#2C3A2F; background:#FAFCF8; width:100%; transition:border-color 0.2s;
    }
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color:#4A735C; outline:none; }
    .form-group textarea { resize:vertical; min-height:50px; }
    .btn { padding:9px 20px; border-radius:0.6rem; font-weight:700; font-size:0.85rem; cursor:pointer; border:none; transition:0.2s; display:inline-flex; align-items:center; gap:6px; }
    .btn-primary { background:#2F4B3C; color:#fff; width:100%; justify-content:center; }
    .btn-primary:hover { background:#3D6150; }
    .btn-danger { background:#FEE2E2; color:#DC2626; border:none; }
    .btn-danger:hover { background:#FECACA; }
    .btn-sm { padding:4px 10px; font-size:0.75rem; }

    table { width:100%; border-collapse:collapse; }
    thead th { background:#F4F7F1; padding:10px 14px; text-align:left; font-size:0.7rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#7D8F76; }
    tbody tr { border-top:1px solid #F0F4EC; transition:background 0.15s; }
    tbody tr:hover { background:#FAFCF8; }
    td { padding:10px 14px; font-size:0.85rem; vertical-align:middle; }

    .type-badge { display:inline-flex; align-items:center; gap:5px; padding:3px 10px; border-radius:99px; font-size:0.75rem; font-weight:700; white-space:nowrap; }
    .lot-badge  { padding:3px 9px; border-radius:99px; font-size:0.72rem; font-weight:700; white-space:nowrap; display:inline-block; }
    .qty-cell   { font-weight:800; font-size:0.95rem; }
    .qty-plus   { color:#059669; }
    .qty-minus  { color:#DC2626; }
    .notes-cell { color:#7D8F76; font-size:0.8rem; max-width:160px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }

    .empty { text-align:center; padding:2.5rem; color:#92A389; font-size:0.9rem; }
    .section-title { font-size:1rem; font-weight:700; margin-bottom:1rem; display:flex; align-items:center; gap:8px; }
  </style>
</head>
<body>
<nav>
  <div class="nav-brand"><i class="fas fa-leaf"></i> ORAVIE <span>Admin</span></div>
  <div class="nav-links">
    <a href="dashboard.php"><i class="fas fa-list-alt"></i> Commandes</a>
    <a href="produits.php"><i class="fas fa-box"></i> Produits</a>
    <a href="depenses.php"><i class="fas fa-receipt"></i> Dépenses</a>
    <a href="mouvements.php" class="active"><i class="fas fa-boxes"></i> Stock lots</a>
    <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Déconnexion</a>
  </div>
</nav>

<div class="main">
  <div class="page-title"><i class="fas fa-boxes" style="color:#4A735C;margin-right:8px"></i>Stock par lot</div>

  <?php if ($msg): $type = str_starts_with($msg,'ok:') ? 'ok' : 'err'; $text = substr($msg,4); ?>
  <div class="toast <?= $type ?>"><?= htmlspecialchars($text) ?></div>
  <?php endif; ?>

  <!-- RÉSUMÉ PAR LOT -->
  <div class="section-title"><i class="fas fa-chart-bar"></i> Résumé des lots</div>
  <div class="resume-grid">
    <?php foreach ($resume as $i => $r):
        $color = $lotColors[$i % count($lotColors)];
        $pct   = $r['produit'] > 0 ? round($r['stock_restant'] / $r['produit'] * 100) : 0;
        $badgeClass = $r['stock_restant'] > 10 ? 'ok' : ($r['stock_restant'] > 0 ? 'warn' : 'zero');
    ?>
    <div class="lot-card" style="border-top-color:<?= $color ?>">
      <div class="lot-card-title">
        <span class="lot-num" style="background:<?= $color ?>"><?= (int)$r['numero'] ?></span>
        <?= htmlspecialchars($r['nom']) ?>
      </div>
      <div class="lot-rows">
        <div class="lot-row">
          <span class="lot-row-label"><i class="fas fa-industry" style="color:#10B981"></i> Production</span>
          <span class="lot-row-val green">+<?= (int)$r['produit'] ?></span>
        </div>
        <div class="lot-row">
          <span class="lot-row-label"><i class="fas fa-shopping-cart" style="color:#3B82F6"></i> Vendus</span>
          <span class="lot-row-val blue">
            <?= (int)$r['vendu'] ?>
            <?php if ($r['vendu_auto'] > 0 && $r['vendu_manuel'] > 0): ?>
            <small style="font-weight:500;color:#7D8F76;font-size:0.72rem">(<?= (int)$r['vendu_auto'] ?> cmd + <?= (int)$r['vendu_manuel'] ?> manuel)</small>
            <?php elseif ($r['vendu_auto'] > 0): ?>
            <small style="font-weight:500;color:#7D8F76;font-size:0.72rem">(depuis commandes)</small>
            <?php elseif ($r['vendu_manuel'] > 0): ?>
            <small style="font-weight:500;color:#7D8F76;font-size:0.72rem">(manuel)</small>
            <?php endif; ?>
          </span>
        </div>
        <div class="lot-row">
          <span class="lot-row-label"><i class="fas fa-times-circle" style="color:#EF4444"></i> Défectueux</span>
          <span class="lot-row-val red"><?= (int)$r['defectueux'] ?></span>
        </div>
        <div class="lot-row">
          <span class="lot-row-label"><i class="fas fa-gift" style="color:#F59E0B"></i> Échantillons</span>
          <span class="lot-row-val amber"><?= (int)$r['echantillon'] ?></span>
        </div>
        <?php if ($r['retour'] > 0): ?>
        <div class="lot-row">
          <span class="lot-row-label"><i class="fas fa-undo" style="color:#8B5CF6"></i> Retours</span>
          <span class="lot-row-val purple">+<?= (int)$r['retour'] ?></span>
        </div>
        <?php endif; ?>
        <hr class="divider">
        <div class="lot-row">
          <span class="lot-row-label" style="font-weight:700;color:#2C3A2F">Stock restant</span>
          <span class="stock-badge <?= $badgeClass ?>">
            <i class="fas fa-box"></i> <?= (int)$r['stock_restant'] ?>
            <?php if ($r['produit'] > 0): ?>
            <small style="font-weight:600;opacity:0.75">(<?= $pct ?>%)</small>
            <?php endif; ?>
          </span>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php if (empty($resume)): ?>
    <p style="color:#92A389;font-size:0.9rem">Aucun lot trouvé. Créez des lots dans Dépenses.</p>
    <?php endif; ?>
  </div>

  <!-- FILTRE -->
  <div class="lot-pills">
    <a href="mouvements.php" class="lot-pill <?= $filterLot === '' ? 'active' : '' ?>">Tous les lots</a>
    <?php foreach ($lots as $i => $l): ?>
    <a href="mouvements.php?lot=<?= $l['id'] ?>" class="lot-pill <?= (string)$filterLot === (string)$l['id'] ? 'active' : '' ?>"
       style="<?= (string)$filterLot === (string)$l['id'] ? 'border-color:'.$lotColors[$i % count($lotColors)] : '' ?>">
      Lot <?= (int)$l['numero'] ?> — <?= htmlspecialchars($l['nom']) ?>
    </a>
    <?php endforeach; ?>
  </div>

  <div class="layout">
    <!-- LISTE DES MOUVEMENTS -->
    <div class="col-left">
      <div class="card">
        <div class="card-header"><i class="fas fa-history"></i> Historique des mouvements</div>
        <?php if (empty($mouvements)): ?>
        <div class="empty">Aucun mouvement enregistré.</div>
        <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Date</th>
              <th>Lot</th>
              <th>Type</th>
              <th>Qté</th>
              <th>Notes</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($mouvements as $mv):
              $tc = $typeConfig[$mv['type']];
              $isPlus = in_array($mv['type'], ['production','retour']);
          ?>
            <tr>
              <td><?= date('d/m/Y', strtotime($mv['date_mvt'])) ?></td>
              <td>
                <?php foreach ($lots as $i => $l): if ($l['id'] == $mv['lot_id']): ?>
                <span class="lot-badge" style="background:<?= $lotColors[$i % count($lotColors)] ?>22;color:<?= $lotColors[$i % count($lotColors)] ?>">
                  Lot <?= (int)$mv['lot_numero'] ?>
                </span>
                <?php break; endif; endforeach; ?>
              </td>
              <td>
                <span class="type-badge" style="background:<?= $tc['bg'] ?>;color:<?= $tc['color'] ?>">
                  <i class="fas <?= $tc['icon'] ?>"></i> <?= $tc['label'] ?>
                </span>
              </td>
              <td class="qty-cell <?= $isPlus ? 'qty-plus' : 'qty-minus' ?>">
                <?= $tc['sign'] ?><?= (int)$mv['quantite'] ?>
              </td>
              <td class="notes-cell" title="<?= htmlspecialchars($mv['notes'] ?? '') ?>">
                <?php if ($mv['depense_id']): ?>
                <span style="display:inline-flex;align-items:center;gap:4px;padding:2px 7px;border-radius:99px;background:#EBF2E8;color:#2F4B3C;font-size:0.7rem;font-weight:700;">
                  <i class="fas fa-link"></i> Auto
                </span>
                <?php else: ?>
                <?= htmlspecialchars($mv['notes'] ?? '—') ?>
                <?php endif; ?>
              </td>
              <td>
                <form method="POST" onsubmit="return confirm('Supprimer ce mouvement ?')">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id"    value="<?= (int)$mv['id'] ?>">
                  <input type="hidden" name="flot"  value="<?= htmlspecialchars($filterLot) ?>">
                  <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- FORMULAIRE AJOUT -->
    <div class="col-right">
      <div class="card">
        <div class="card-header"><i class="fas fa-plus-circle"></i> Ajouter un mouvement</div>
        <div class="card-body">
          <form method="POST">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="flot"   value="<?= htmlspecialchars($filterLot) ?>">

            <div class="form-group">
              <label>Lot *</label>
              <select name="lot_id" required>
                <option value="">— Choisir —</option>
                <?php foreach ($lots as $l): ?>
                <option value="<?= $l['id'] ?>" <?= (string)$filterLot === (string)$l['id'] ? 'selected' : '' ?>>
                  Lot <?= (int)$l['numero'] ?> — <?= htmlspecialchars($l['nom']) ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group">
              <label>Type *</label>
              <select name="type" required>
                <option value="">— Choisir —</option>
                <?php foreach ($typeConfig as $key => $tc): ?>
                <option value="<?= $key ?>"><?= $tc['label'] ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="form-group">
              <label>Quantité *</label>
              <input type="number" name="quantite" min="1" required placeholder="ex : 100">
            </div>

            <div class="form-group">
              <label>Date *</label>
              <input type="date" name="date_mvt" required value="<?= date('Y-m-d') ?>">
            </div>

            <div class="form-group">
              <label>Notes</label>
              <textarea name="notes" placeholder="Remarque optionnelle…"></textarea>
            </div>

            <button type="submit" class="btn btn-primary"><i class="fas fa-plus"></i> Enregistrer</button>
          </form>
        </div>
      </div>

      <!-- Aide -->
      <div style="margin-top:1rem;background:#fff;border-radius:1.2rem;padding:1.2rem 1.4rem;box-shadow:0 2px 10px rgba(0,0,0,0.05);">
        <div style="font-weight:700;font-size:0.85rem;margin-bottom:0.8rem;color:#2F4B3C"><i class="fas fa-info-circle"></i> Guide rapide</div>
        <?php foreach ($typeConfig as $tc): ?>
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;font-size:0.82rem;">
          <span style="width:22px;height:22px;border-radius:50%;background:<?= $tc['bg'] ?>;display:inline-flex;align-items:center;justify-content:center;">
            <i class="fas <?= $tc['icon'] ?>" style="color:<?= $tc['color'] ?>;font-size:0.7rem"></i>
          </span>
          <strong><?= $tc['label'] ?></strong>
          <span style="color:#92A389;font-size:0.78rem">
            <?php echo match($tc['label']) {
              'Production'   => '→ sprays fabriqués',
              'Vendu'        => '→ commandes livrées',
              'Défectueux'   => '→ sprays abîmés',
              'Échantillon'  => '→ donnés / promo',
              'Retour stock' => '→ retour client',
              default        => ''
            }; ?>
          </span>
        </div>
        <?php endforeach; ?>
        <div style="margin-top:0.8rem;padding:8px 10px;background:#F4F7F1;border-radius:0.6rem;font-size:0.78rem;color:#7D8F76;line-height:1.5;">
          Le coût du lot est noté dans <strong>Dépenses</strong> (Matières premières + lot).
        </div>
      </div>
    </div>
  </div>

</div>
</body>
</html>
