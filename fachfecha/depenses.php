<?php
require_once 'auth.php';
requireAuth();

$pdo = getDB();

// ── TABLES ───────────────────────────────────────────────────────────────────
$pdo->exec("
    CREATE TABLE IF NOT EXISTS lots (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        numero      INT NOT NULL UNIQUE,
        nom         VARCHAR(150) DEFAULT NULL,
        date_debut  DATE DEFAULT NULL,
        notes       TEXT,
        actif       TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
$pdo->prepare("INSERT IGNORE INTO lots (numero, nom) VALUES (1,'Lot 1'),(2,'Lot 2')")->execute();

$pdo->exec("
    CREATE TABLE IF NOT EXISTS depenses (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        date_depense DATE NOT NULL,
        categorie    VARCHAR(100) NOT NULL,
        description  TEXT,
        montant      DECIMAL(10,2) NOT NULL,
        lot_id       INT NULL DEFAULT NULL,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");
// Migration : ajouter lot_id si absent
$cols = $pdo->query("SHOW COLUMNS FROM depenses LIKE 'lot_id'")->fetchAll();
if (empty($cols)) {
    $pdo->exec("ALTER TABLE depenses ADD COLUMN lot_id INT NULL DEFAULT NULL AFTER montant");
}
// Migration : ajouter quantite si absent
if (empty($pdo->query("SHOW COLUMNS FROM depenses LIKE 'quantite'")->fetchAll())) {
    $pdo->exec("ALTER TABLE depenses ADD COLUMN quantite INT NULL DEFAULT NULL AFTER lot_id");
}
// Migration : ajouter produit_id si absent
if (empty($pdo->query("SHOW COLUMNS FROM depenses LIKE 'produit_id'")->fetchAll())) {
    $pdo->exec("ALTER TABLE depenses ADD COLUMN produit_id INT NULL DEFAULT NULL AFTER quantite");
}
// Table mouvements_lot + colonne depense_id
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

$categories = ['Livraison', 'Marketing', 'Emballage', 'Achat sprays', 'Frais bancaires', 'Salaires', 'Loyer', 'Autre'];
$lots     = $pdo->query("SELECT * FROM lots ORDER BY numero ASC")->fetchAll();
$produits = $pdo->query("SELECT id, nom, volume_ml, stock FROM produits WHERE actif=1 ORDER BY volume_ml ASC")->fetchAll();
$msg = '';

// ── AJOUTER UN LOT ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_lot') {
    $num   = filter_var($_POST['lot_numero'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $nom   = trim(strip_tags($_POST['lot_nom'] ?? ''));
    $debut = $_POST['lot_date_debut'] ?? '';
    if ($num) {
        $pdo->prepare("INSERT IGNORE INTO lots (numero, nom, date_debut) VALUES (:n,:nom,:d)")
            ->execute([':n'=>$num, ':nom'=>$nom ?: 'Lot '.$num, ':d'=>$debut ?: null]);
        $msg = 'ok:Lot ' . $num . ' ajouté.';
    }
    header('Location: depenses.php?msg=' . urlencode($msg)); exit;
}

// ── SUPPRIMER ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
    if ($id) {
        // Annuler le stock produit avant suppression
        $oldDep = $pdo->prepare("SELECT * FROM depenses WHERE id=:id");
        $oldDep->execute([':id'=>$id]); $oldDep = $oldDep->fetch();
        if ($oldDep && $oldDep['categorie'] === 'Achat sprays' && $oldDep['produit_id'] && $oldDep['quantite']) {
            $pdo->prepare("UPDATE produits SET stock = GREATEST(0, stock - :q) WHERE id = :pid")
                ->execute([':q'=>$oldDep['quantite'], ':pid'=>$oldDep['produit_id']]);
        }
        try { $pdo->prepare("DELETE FROM mouvements_lot WHERE depense_id = :did")->execute([':did' => $id]); } catch (Exception $e) {}
        $pdo->prepare("DELETE FROM depenses WHERE id = :id")->execute([':id' => $id]);
        $msg = 'ok:Dépense supprimée.';
    }
    $qs = http_build_query(array_filter(['mois'=>$_POST['mois']??'','cat'=>$_POST['cat']??'','lot'=>$_POST['flot']??'']));
    header('Location: depenses.php?' . $qs); exit;
}

// ── AJOUTER / MODIFIER ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['action'] ?? '', ['add', 'edit'])) {
    $id          = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
    $date        = $_POST['date_depense'] ?? '';
    $categorie   = in_array($_POST['categorie'] ?? '', $categories) ? $_POST['categorie'] : '';
    $description = trim(strip_tags($_POST['description'] ?? ''));
    $montant     = filter_var($_POST['montant'] ?? '', FILTER_VALIDATE_FLOAT);
    $lot_id      = filter_var($_POST['lot_id'] ?? '', FILTER_VALIDATE_INT) ?: null;
    $quantite    = filter_var($_POST['quantite'] ?? '', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: null;
    $produit_id  = filter_var($_POST['produit_id'] ?? '', FILTER_VALIDATE_INT) ?: null;

    if ($date && $categorie && $montant !== false && $montant > 0) {
        if ($_POST['action'] === 'edit' && $id) {
            // Annuler l'ancien stock produit avant modif
            $oldDep = $pdo->prepare("SELECT * FROM depenses WHERE id=:id");
            $oldDep->execute([':id'=>$id]); $oldDep = $oldDep->fetch();
            if ($oldDep && $oldDep['categorie'] === 'Achat sprays' && $oldDep['produit_id'] && $oldDep['quantite']) {
                $pdo->prepare("UPDATE produits SET stock = GREATEST(0, stock - :q) WHERE id = :pid")
                    ->execute([':q'=>$oldDep['quantite'], ':pid'=>$oldDep['produit_id']]);
            }
            $pdo->prepare("UPDATE depenses SET date_depense=:d, categorie=:c, description=:desc, montant=:m, lot_id=:lid, quantite=:q, produit_id=:pid WHERE id=:id")
                ->execute([':d'=>$date, ':c'=>$categorie, ':desc'=>$description, ':m'=>$montant, ':lid'=>$lot_id, ':q'=>$quantite, ':pid'=>$produit_id, ':id'=>$id]);
            try { $pdo->prepare("DELETE FROM mouvements_lot WHERE depense_id = :did")->execute([':did' => $id]); } catch (Exception $e) {}
            if ($categorie === 'Achat sprays' && $lot_id && $quantite) {
                $pdo->prepare("INSERT INTO mouvements_lot (lot_id, type, quantite, date_mvt, notes, depense_id) VALUES (:l,'production',:q,:d,:n,:did)")
                    ->execute([':l'=>$lot_id, ':q'=>$quantite, ':d'=>$date, ':n'=>'Auto · Achat sprays (dépense #'.$id.')', ':did'=>$id]);
                // Ajouter au stock produit
                if ($produit_id) {
                    $pdo->prepare("UPDATE produits SET stock = stock + :q WHERE id = :pid")
                        ->execute([':q'=>$quantite, ':pid'=>$produit_id]);
                }
            }
            $msg = 'ok:Dépense modifiée.';
        } else {
            $pdo->prepare("INSERT INTO depenses (date_depense, categorie, description, montant, lot_id, quantite, produit_id) VALUES (:d,:c,:desc,:m,:lid,:q,:pid)")
                ->execute([':d'=>$date, ':c'=>$categorie, ':desc'=>$description, ':m'=>$montant, ':lid'=>$lot_id, ':q'=>$quantite, ':pid'=>$produit_id]);
            if ($categorie === 'Achat sprays' && $lot_id && $quantite) {
                $newId = (int)$pdo->lastInsertId();
                $pdo->prepare("INSERT INTO mouvements_lot (lot_id, type, quantite, date_mvt, notes, depense_id) VALUES (:l,'production',:q,:d,:n,:did)")
                    ->execute([':l'=>$lot_id, ':q'=>$quantite, ':d'=>$date, ':n'=>'Auto · Achat sprays (dépense #'.$newId.')', ':did'=>$newId]);
                // Ajouter au stock produit
                if ($produit_id) {
                    $pdo->prepare("UPDATE produits SET stock = stock + :q WHERE id = :pid")
                        ->execute([':q'=>$quantite, ':pid'=>$produit_id]);
                }
            }
            $msg = 'ok:Dépense ajoutée.';
        }
    } else {
        $msg = 'err:Veuillez remplir tous les champs obligatoires.';
    }
    $qs = http_build_query(array_filter(['mois'=>$_POST['mois']??'','cat'=>$_POST['cat']??'','lot'=>$_POST['flot']??'','msg'=>$msg]));
    header('Location: depenses.php?' . $qs); exit;
}

// ── FILTRES ──────────────────────────────────────────────────────────────────
$filterMois = $_GET['mois'] ?? '';
$filterCat  = $_GET['cat']  ?? '';
$filterLot  = $_GET['lot']  ?? ''; // '' = tous, '0' = global, 'N' = lot_id
if (isset($_GET['msg'])) $msg = $_GET['msg'];

// Édition
$editDep = null;
if (isset($_GET['edit'])) {
    $eid = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
    if ($eid) {
        $s = $pdo->prepare("SELECT * FROM depenses WHERE id = :id");
        $s->execute([':id' => $eid]);
        $editDep = $s->fetch();
    }
}

// ── REQUÊTE LISTE ─────────────────────────────────────────────────────────────
$where = []; $params = [];
if ($filterMois) {
    $where[] = "DATE_FORMAT(d.date_depense,'%Y-%m') = :mois";
    $params[':mois'] = $filterMois;
}
if ($filterCat && in_array($filterCat, $categories)) {
    $where[] = "d.categorie = :cat";
    $params[':cat'] = $filterCat;
}
if ($filterLot === '0') {
    $where[] = "d.lot_id IS NULL";
} elseif ($filterLot !== '') {
    $lid = filter_var($filterLot, FILTER_VALIDATE_INT);
    if ($lid) { $where[] = "d.lot_id = :lid"; $params[':lid'] = $lid; }
}
$whereSQL = $where ? " WHERE " . implode(' AND ', $where) : '';

$stmt = $pdo->prepare("SELECT d.*, l.numero AS lot_numero, l.nom AS lot_nom FROM depenses d LEFT JOIN lots l ON d.lot_id = l.id" . $whereSQL . " ORDER BY d.date_depense DESC");
$stmt->execute($params);
$depenses = $stmt->fetchAll();

// Totaux
$stmtT = $pdo->prepare("SELECT COALESCE(SUM(d.montant),0) FROM depenses d" . $whereSQL);
$stmtT->execute($params); $totalFiltre = (float)$stmtT->fetchColumn();

$stmtM = $pdo->prepare("SELECT COALESCE(SUM(montant),0) FROM depenses WHERE DATE_FORMAT(date_depense,'%Y-%m')=:m");
$stmtM->execute([':m' => date('Y-m')]); $totalMois = (float)$stmtM->fetchColumn();

$totalAll = (float)$pdo->query("SELECT COALESCE(SUM(montant),0) FROM depenses")->fetchColumn();

// Stats par lot (dépenses + CA livrées)
$statsLots = $pdo->query("
    SELECT l.id, l.numero, l.nom,
        COALESCE((SELECT SUM(d2.montant) FROM depenses d2 WHERE d2.lot_id = l.id), 0) AS total,
        COALESCE((SELECT SUM(CAST(c.donnees->>'$.prix_total' AS DECIMAL(10,2))) FROM commandes c WHERE c.lot_id = l.id AND c.statut = 'livrée'), 0) AS ca_lot
    FROM lots l ORDER BY l.numero ASC
")->fetchAll();
$totalGlobal = (float)$pdo->query("SELECT COALESCE(SUM(montant),0) FROM depenses WHERE lot_id IS NULL")->fetchColumn();
$totalCAAll  = (float)$pdo->query("SELECT COALESCE(SUM(CAST(donnees->>'$.prix_total' AS DECIMAL(10,2))),0) FROM commandes WHERE statut='livrée'")->fetchColumn();

// Répartition par catégorie
$stmtCats = $pdo->prepare("SELECT d.categorie, SUM(d.montant) AS total FROM depenses d" . $whereSQL . " GROUP BY d.categorie ORDER BY total DESC");
$stmtCats->execute($params); $parCat = $stmtCats->fetchAll();

// Couleurs
$catColors = [
    'Livraison'=>'#3B82F6','Marketing'=>'#F59E0B','Emballage'=>'#8B5CF6',
    'Achat sprays'=>'#10B981','Frais bancaires'=>'#EF4444',
    'Salaires'=>'#F97316','Loyer'=>'#6366F1','Autre'=>'#92A389',
];
$lotColors = ['#2F4B3C','#C6A43F','#3B82F6','#8B5CF6','#F97316','#EF4444','#10B981'];

// Coût unitaire réel par lot = somme de TOUTES les dépenses du lot / quantité sprays achetés
$coutLot = []; // lot_id => ['total'=>X, 'quantite'=>Y, 'cout_u'=>Z]
foreach ($pdo->query("
    SELECT lot_id,
        SUM(montant)   AS total_depenses,
        SUM(CASE WHEN categorie='Achat sprays' THEN quantite ELSE 0 END) AS qte_sprays
    FROM depenses
    WHERE lot_id IS NOT NULL
    GROUP BY lot_id
")->fetchAll() as $row) {
    $qte = (int)$row['qte_sprays'];
    $coutLot[$row['lot_id']] = [
        'total'   => (float)$row['total_depenses'],
        'quantite'=> $qte,
        'cout_u'  => $qte > 0 ? round((float)$row['total_depenses'] / $qte, 3) : null,
    ];
}
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dépenses · ORAVIE Admin</title>
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

    .stats { display:flex; gap:1rem; margin-bottom:1.5rem; flex-wrap:wrap; }
    .stat-card { background:#fff; border-radius:1rem; padding:1.2rem 1.5rem; flex:1; min-width:140px; box-shadow:0 2px 10px rgba(0,0,0,0.05); border-left:4px solid #4A735C; }
    .stat-card.red   { border-color:#EF4444; }
    .stat-card.amber { border-color:#F59E0B; }
    .stat-val   { font-size:1.6rem; font-weight:800; color:#2F4B3C; line-height:1; }
    .stat-label { font-size:0.7rem; color:#92A389; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; margin-top:4px; }

    /* Lot filter pills */
    .lot-stats { display:flex; gap:0.7rem; flex-wrap:wrap; margin-bottom:2rem; }
    .lot-pill { background:#fff; border-radius:2rem; padding:8px 16px; box-shadow:0 2px 8px rgba(0,0,0,0.05); display:flex; align-items:center; gap:8px; text-decoration:none; color:inherit; border:2px solid transparent; transition:0.2s; font-size:0.85rem; }
    .lot-pill:hover { border-color:#C6A43F; }
    .lot-pill.active-lot { border-color:#2F4B3C; background:#EBF2E8; }
    .lot-dot { width:9px; height:9px; border-radius:50%; flex-shrink:0; }
    .lot-pill-name { font-weight:700; }
    .lot-pill-amt { font-weight:800; color:#DC2626; margin-left:6px; white-space:nowrap; }

    .layout { display:flex; gap:1.5rem; align-items:flex-start; flex-wrap:wrap; }
    .col-left  { flex:1; min-width:300px; }
    .col-right { width:330px; min-width:280px; }

    .card { background:#fff; border-radius:1.2rem; box-shadow:0 2px 10px rgba(0,0,0,0.05); overflow:hidden; }
    .card-header { background:#2F4B3C; color:#fff; padding:14px 20px; font-weight:700; font-size:0.9rem; display:flex; align-items:center; gap:8px; }
    .card-body { padding:20px; }
    .form-group { display:flex; flex-direction:column; gap:5px; margin-bottom:12px; }
    .form-group label { font-size:0.75rem; font-weight:600; color:#7D8F76; text-transform:uppercase; letter-spacing:0.5px; }
    .form-group input, .form-group select, .form-group textarea {
        padding:9px 12px; border:1.5px solid #E2E9DA; border-radius:0.6rem;
        font-size:0.88rem; font-family:inherit; color:#2C3A2F; background:#FAFCF8; width:100%; transition:border-color 0.2s;
    }
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color:#4A735C; outline:none; }
    .form-group textarea { resize:vertical; min-height:55px; }
    .btn { padding:9px 20px; border-radius:0.6rem; font-weight:700; font-size:0.85rem; cursor:pointer; border:none; transition:0.2s; display:inline-flex; align-items:center; gap:6px; }
    .btn-primary { background:#2F4B3C; color:#fff; }
    .btn-primary:hover { background:#3D6150; }
    .btn-secondary { background:#F4F7F1; color:#4A735C; border:1.5px solid #E2E9DA; }
    .btn-secondary:hover { background:#E2EDD9; }
    .btn-danger { background:#FEE2E2; color:#DC2626; border:none; }
    .btn-danger:hover { background:#FECACA; }
    .btn-sm { padding:5px 12px; font-size:0.78rem; }

    .filters-bar { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:1.2rem; align-items:center; }
    .filters-bar select, .filters-bar input[type=month] {
        padding:7px 12px; border:1.5px solid #E2E9DA; border-radius:0.6rem; font-size:0.85rem; background:#fff; color:#2C3A2F;
    }

    table { width:100%; border-collapse:collapse; }
    thead th { background:#F4F7F1; padding:10px 14px; text-align:left; font-size:0.7rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#7D8F76; }
    tbody tr { border-top:1px solid #F0F4EC; transition:background 0.15s; }
    tbody tr:hover { background:#FAFCF8; }
    td { padding:10px 14px; font-size:0.85rem; vertical-align:middle; }
    .td-montant { font-weight:800; color:#DC2626; white-space:nowrap; }
    .td-actions { display:flex; gap:6px; }

    .cat-badge { padding:3px 9px; border-radius:20px; font-size:0.72rem; font-weight:700; white-space:nowrap; }
    .lot-badge  { padding:3px 9px; border-radius:20px; font-size:0.72rem; font-weight:700; white-space:nowrap; display:inline-block; }

    .rep-item { display:flex; align-items:center; gap:8px; margin-bottom:8px; }
    .rep-bar-wrap { flex:1; background:#F0F4EC; border-radius:99px; height:7px; overflow:hidden; }
    .rep-bar { height:7px; border-radius:99px; }
    .rep-label { font-size:0.78rem; font-weight:600; min-width:120px; }
    .rep-val { font-size:0.78rem; font-weight:700; color:#DC2626; white-space:nowrap; min-width:65px; text-align:right; }

    .toast { padding:12px 16px; border-radius:0.7rem; margin-bottom:1.2rem; font-size:0.88rem; font-weight:600; }
    .toast.ok  { background:#EAF5E9; color:#2F6B3A; border:1.5px solid #A8D5A2; }
    .toast.err { background:#FDF0F0; color:#B03A2E; border:1.5px solid #F1948A; }
    .empty { text-align:center; padding:2.5rem; color:#92A389; font-size:0.9rem; }

    details summary { cursor:pointer; font-size:0.8rem; color:#7D8F76; font-weight:600; user-select:none; padding:6px 0; }
    details summary:hover { color:#2F4B3C; }
    details .form-group { margin-top:8px; }
  </style>
</head>
<body>
<nav>
  <div class="nav-brand"><i class="fas fa-leaf"></i> ORAVIE <span>Admin</span></div>
  <div class="nav-links">
    <a href="dashboard.php"><i class="fas fa-list-alt"></i> Commandes</a>
    <a href="produits.php"><i class="fas fa-box"></i> Produits</a>
    <a href="depenses.php" class="active"><i class="fas fa-receipt"></i> Dépenses</a>
    <a href="mouvements.php"><i class="fas fa-boxes"></i> Stock lots</a>
    <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Déconnexion</a>
  </div>
</nav>

<div class="main">
  <div class="page-title"><i class="fas fa-receipt"></i> Gestion des dépenses</div>

  <?php if ($msg): ?>
    <?php [$type, $text] = explode(':', $msg, 2); ?>
    <div class="toast <?= htmlspecialchars($type) ?>"><?= htmlspecialchars($text) ?></div>
  <?php endif; ?>

  <!-- Stats globales -->
  <div class="stats">
    <div class="stat-card red">
      <div class="stat-val"><?= number_format($totalMois, 2) ?> DT</div>
      <div class="stat-label">Dépenses ce mois</div>
    </div>
    <div class="stat-card amber">
      <div class="stat-val"><?= number_format($totalFiltre, 2) ?> DT</div>
      <div class="stat-label">Total affiché</div>
    </div>
    <div class="stat-card">
      <div class="stat-val"><?= number_format($totalAll, 2) ?> DT</div>
      <div class="stat-label">Total cumulé</div>
    </div>
  </div>

  <!-- Filtres par lot (pills cliquables) -->
  <div class="lot-stats">
    <?php $netAll = $totalCAAll - $totalAll; ?>
    <a href="depenses.php?<?= http_build_query(array_filter(['mois'=>$filterMois,'cat'=>$filterCat])) ?>"
       class="lot-pill <?= $filterLot===''?'active-lot':'' ?>">
      <div class="lot-dot" style="background:#92A389;"></div>
      <span class="lot-pill-name">Tous les lots</span>
      <span class="lot-pill-amt">−<?= number_format($totalAll,2) ?> DT</span>
      <span style="font-size:0.72rem;font-weight:700;color:#059669;white-space:nowrap;">+<?= number_format($totalCAAll,2) ?> CA</span>
      <span style="font-size:0.72rem;font-weight:800;color:<?= $netAll >= 0 ? '#059669' : '#DC2626' ?>;white-space:nowrap;"><?= $netAll >= 0 ? '▲' : '▼' ?><?= number_format(abs($netAll),2) ?></span>
    </a>
    <?php foreach ($statsLots as $i => $sl):
        $net_lot = $sl['ca_lot'] - $sl['total'];
    ?>
    <a href="depenses.php?<?= http_build_query(array_filter(['mois'=>$filterMois,'cat'=>$filterCat,'lot'=>$sl['id']])) ?>"
       class="lot-pill <?= $filterLot==(string)$sl['id']?'active-lot':'' ?>">
      <div class="lot-dot" style="background:<?= $lotColors[$i % count($lotColors)] ?>;"></div>
      <span class="lot-pill-name"><?= htmlspecialchars($sl['nom'] ?: 'Lot '.$sl['numero']) ?></span>
      <span class="lot-pill-amt">−<?= number_format($sl['total'],2) ?> DT</span>
      <span style="font-size:0.72rem;font-weight:700;color:#059669;white-space:nowrap;">+<?= number_format($sl['ca_lot'],2) ?> CA</span>
      <span style="font-size:0.72rem;font-weight:800;color:<?= $net_lot >= 0 ? '#059669' : '#DC2626' ?>;white-space:nowrap;"><?= $net_lot >= 0 ? '▲' : '▼' ?><?= number_format(abs($net_lot),2) ?></span>
    </a>
    <?php endforeach; ?>
    <a href="depenses.php?<?= http_build_query(array_filter(['mois'=>$filterMois,'cat'=>$filterCat,'lot'=>'0'])) ?>"
       class="lot-pill <?= $filterLot==='0'?'active-lot':'' ?>">
      <div class="lot-dot" style="background:#9CA3AF;"></div>
      <span class="lot-pill-name">Global (sans lot)</span>
      <span class="lot-pill-amt">−<?= number_format($totalGlobal,2) ?> DT</span>
    </a>
  </div>

  <div class="layout">
    <!-- Colonne gauche : liste + filtres -->
    <div class="col-left">

      <!-- Filtres mois + catégorie -->
      <form method="get" class="filters-bar">
        <?php if ($filterLot !== ''): ?><input type="hidden" name="lot" value="<?= htmlspecialchars($filterLot) ?>"><?php endif; ?>
        <input type="month" name="mois" value="<?= htmlspecialchars($filterMois) ?>">
        <select name="cat">
          <option value="">Toutes catégories</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= htmlspecialchars($c) ?>" <?= $filterCat===$c?'selected':'' ?>><?= htmlspecialchars($c) ?></option>
          <?php endforeach; ?>
        </select>
        <button type="submit" class="btn btn-secondary"><i class="fas fa-filter"></i> Filtrer</button>
        <a href="depenses.php" class="btn btn-secondary">Tout afficher</a>
      </form>

      <!-- Tableau -->
      <div class="card">
        <?php if (empty($depenses)): ?>
          <div class="empty"><i class="fas fa-receipt" style="font-size:2rem;margin-bottom:8px;display:block;"></i>Aucune dépense pour cette période.</div>
        <?php else: ?>
        <table>
          <thead>
            <tr>
              <th>Date</th>
              <th>Lot</th>
              <th>Catégorie</th>
              <th>Qté</th>
              <th>Description</th>
              <th>Montant</th>
              <th>Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($depenses as $i => $d):
                $lc = $d['lot_id'] ? ($lotColors[($d['lot_numero']-1) % count($lotColors)] ?? '#92A389') : '#9CA3AF';
                $ln = $d['lot_nom'] ? htmlspecialchars($d['lot_nom']) : ($d['lot_numero'] ? 'Lot '.$d['lot_numero'] : 'Global');
            ?>
            <tr>
              <td><?= date('d/m/Y', strtotime($d['date_depense'])) ?></td>
              <td><span class="lot-badge" style="background:<?= $lc ?>22;color:<?= $lc ?>;"><?= $ln ?></span></td>
              <td>
                <span class="cat-badge" style="background:<?= $catColors[$d['categorie']] ?? '#92A389' ?>22;color:<?= $catColors[$d['categorie']] ?? '#92A389' ?>;">
                  <?= htmlspecialchars($d['categorie']) ?>
                </span>
              </td>
              <td style="font-weight:700;color:#2F4B3C;white-space:nowrap;">
                <?php if ($d['categorie'] === 'Achat sprays' && !empty($d['quantite'])): ?>
                  <?= (int)$d['quantite'] ?> u.
                  <?php
                    $pNom = '';
                    foreach ($produits as $p) { if ($p['id'] == ($d['produit_id'] ?? null)) { $pNom = $p['nom']; break; } }
                  ?>
                  <?php if ($pNom): ?>
                  <small style="display:block;color:#4A735C;font-weight:600;font-size:0.71rem"><?= htmlspecialchars($pNom) ?></small>
                  <?php endif; ?>
                <?php else: ?><span style="color:#C5CFC0">—</span><?php endif; ?>
              </td>
              <td style="color:#7D8F76;font-size:0.82rem;max-width:160px;"><?= $d['description'] ? htmlspecialchars($d['description']) : '<em>—</em>' ?></td>
              <td class="td-montant" style="white-space:nowrap;">
                −<?= number_format($d['montant'], 2) ?> DT
              </td>
              <td>
                <div class="td-actions">
                  <a href="depenses.php?edit=<?= $d['id'] ?>&mois=<?= urlencode($filterMois) ?>&cat=<?= urlencode($filterCat) ?>&lot=<?= urlencode($filterLot) ?>" class="btn btn-secondary btn-sm"><i class="fas fa-pen"></i></a>
                  <form method="post" onsubmit="return confirm('Supprimer cette dépense ?');" style="display:inline;">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $d['id'] ?>">
                    <input type="hidden" name="mois" value="<?= htmlspecialchars($filterMois) ?>">
                    <input type="hidden" name="cat" value="<?= htmlspecialchars($filterCat) ?>">
                    <input type="hidden" name="flot" value="<?= htmlspecialchars($filterLot) ?>">
                    <button type="submit" class="btn btn-danger btn-sm"><i class="fas fa-trash"></i></button>
                  </form>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr style="background:#FFF5F5;">
              <td colspan="5" style="padding:10px 14px;font-weight:700;">TOTAL</td>
              <td class="td-montant" style="white-space:nowrap;">
                −<?= number_format($totalFiltre, 2) ?> DT
                <?php
                  // Coût unitaire réel si on filtre sur un lot précis
                  $clTot = $filterLot && $filterLot !== '0' ? ($coutLot[(int)$filterLot] ?? null) : null;
                  if (!$clTot && count($depenses) > 0) {
                      // Si toutes les lignes appartiennent au même lot, afficher quand même
                      $lotIds = array_unique(array_filter(array_column($depenses, 'lot_id')));
                      if (count($lotIds) === 1) { $clTot = $coutLot[reset($lotIds)] ?? null; }
                  }
                  if ($clTot && $clTot['cout_u'] !== null):
                ?>
                <small style="display:block;font-weight:600;color:#059669;font-size:0.75rem;margin-top:2px;">
                  (<?= number_format($clTot['cout_u'], 3) ?> DT/u réel)
                </small>
                <?php endif; ?>
              </td>
              <td></td>
            </tr>
          </tfoot>
        </table>
        <?php endif; ?>
      </div>
    </div>

    <!-- Colonne droite -->
    <div class="col-right">

      <!-- Formulaire ajout / édition -->
      <div class="card" style="margin-bottom:1.2rem;">
        <div class="card-header">
          <?php if ($editDep): ?><i class="fas fa-pen"></i> Modifier<?php else: ?><i class="fas fa-plus"></i> Ajouter une dépense<?php endif; ?>
        </div>
        <div class="card-body">
          <form method="post">
            <input type="hidden" name="action" value="<?= $editDep ? 'edit' : 'add' ?>">
            <?php if ($editDep): ?><input type="hidden" name="id" value="<?= $editDep['id'] ?>"><?php endif; ?>
            <input type="hidden" name="mois" value="<?= htmlspecialchars($filterMois) ?>">
            <input type="hidden" name="cat" value="<?= htmlspecialchars($filterCat) ?>">
            <input type="hidden" name="flot" value="<?= htmlspecialchars($filterLot) ?>">

            <div class="form-group">
              <label>Date *</label>
              <input type="date" name="date_depense" value="<?= htmlspecialchars($editDep['date_depense'] ?? date('Y-m-d')) ?>" required>
            </div>
            <div class="form-group">
              <label>Lot</label>
              <select name="lot_id">
                <option value="">— Global (pas de lot) —</option>
                <?php foreach ($lots as $l): ?>
                  <option value="<?= $l['id'] ?>" <?= ($editDep['lot_id'] ?? null)==$l['id']?'selected':'' ?>>
                    <?= htmlspecialchars($l['nom'] ?: 'Lot '.$l['numero']) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label>Catégorie *</label>
              <select name="categorie" id="sel-categorie" required onchange="toggleQty(this.value)">
                <?php foreach ($categories as $c): ?>
                  <option value="<?= htmlspecialchars($c) ?>" <?= ($editDep['categorie'] ?? '')===$c?'selected':'' ?>><?= htmlspecialchars($c) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group" id="grp-quantite" style="display:none">
              <label>Quantité achetée *</label>
              <input type="number" name="quantite" min="1" id="inp-quantite"
                     value="<?= htmlspecialchars((string)($editDep['quantite'] ?? '')) ?>"
                     placeholder="ex : 100">
            </div>
            <div class="form-group" id="grp-produit" style="display:none">
              <label>Produit (format) *</label>
              <select name="produit_id" id="sel-produit">
                <option value="">— Choisir le format —</option>
                <?php foreach ($produits as $p): ?>
                <option value="<?= $p['id'] ?>" <?= ($editDep['produit_id'] ?? null)==$p['id']?'selected':'' ?>>
                  <?= htmlspecialchars($p['nom']) ?> &mdash; stock actuel : <?= (int)$p['stock'] ?>
                </option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label>Montant total (DT) *</label>
              <input type="number" name="montant" step="0.01" min="0.01" value="<?= htmlspecialchars($editDep['montant'] ?? '') ?>" placeholder="0.00" required>
            </div>
            <div class="form-group">
              <label>Description</label>
              <textarea name="description" placeholder="Détail optionnel..."><?= htmlspecialchars($editDep['description'] ?? '') ?></textarea>
            </div>
            <div style="display:flex;gap:8px;">
              <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> <?= $editDep ? 'Enregistrer' : 'Ajouter' ?></button>
              <?php if ($editDep): ?>
                <a href="depenses.php?mois=<?= urlencode($filterMois) ?>&cat=<?= urlencode($filterCat) ?>&lot=<?= urlencode($filterLot) ?>" class="btn btn-secondary">Annuler</a>
              <?php endif; ?>
            </div>
          </form>
          <script>
          function toggleQty(val) {
            var isSpray = val === 'Achat sprays';
            document.getElementById('grp-quantite').style.display = isSpray ? '' : 'none';
            document.getElementById('grp-produit').style.display  = isSpray ? '' : 'none';
            if (!isSpray) {
              document.getElementById('inp-quantite').value = '';
              document.getElementById('sel-produit').value  = '';
            }
          }
          toggleQty(document.getElementById('sel-categorie').value);
          </script>
        </div>
      </div>

      <!-- Répartition par catégorie -->
      <?php if (!empty($parCat)): ?>
      <div class="card" style="margin-bottom:1.2rem;">
        <div class="card-header"><i class="fas fa-chart-pie"></i> Répartition par catégorie</div>
        <div class="card-body">
          <?php foreach ($parCat as $pc):
            $pct = $totalFiltre > 0 ? ($pc['total'] / $totalFiltre * 100) : 0;
          ?>
          <div class="rep-item">
            <div class="rep-label"><span class="cat-badge" style="background:<?= $catColors[$pc['categorie']] ?? '#92A389' ?>22;color:<?= $catColors[$pc['categorie']] ?? '#92A389' ?>;"><?= htmlspecialchars($pc['categorie']) ?></span></div>
            <div class="rep-bar-wrap"><div class="rep-bar" style="width:<?= round($pct) ?>%;background:<?= $catColors[$pc['categorie']] ?? '#92A389' ?>;"></div></div>
            <div class="rep-val">−<?= number_format($pc['total'], 2) ?> DT</div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <!-- Gestion des lots -->
      <div class="card">
        <div class="card-header"><i class="fas fa-layer-group"></i> Lots de production</div>
        <div class="card-body">
          <?php foreach ($lots as $i => $l): ?>
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:7px;">
            <div style="width:10px;height:10px;border-radius:50%;background:<?= $lotColors[$i % count($lotColors)] ?>;flex-shrink:0;"></div>
            <span style="font-weight:700;font-size:0.88rem;"><?= htmlspecialchars($l['nom'] ?: 'Lot '.$l['numero']) ?></span>
            <?php if ($l['date_debut']): ?><span style="font-size:0.74rem;color:#92A389;margin-left:auto;"><?= date('d/m/Y', strtotime($l['date_debut'])) ?></span><?php endif; ?>
          </div>
          <?php endforeach; ?>
          <details style="margin-top:12px;">
            <summary>+ Ajouter un lot</summary>
            <form method="post" style="margin-top:10px;">
              <input type="hidden" name="action" value="add_lot">
              <div class="form-group">
                <label>Numéro du lot *</label>
                <input type="number" name="lot_numero" min="1" placeholder="3" required>
              </div>
              <div class="form-group">
                <label>Nom (optionnel)</label>
                <input type="text" name="lot_nom" placeholder="Lot 3">
              </div>
              <div class="form-group">
                <label>Date de début</label>
                <input type="date" name="lot_date_debut">
              </div>
              <button type="submit" class="btn btn-primary btn-sm"><i class="fas fa-plus"></i> Créer le lot</button>
            </form>
          </details>
        </div>
      </div>

    </div>
  </div>
</div>
</body>
</html>
