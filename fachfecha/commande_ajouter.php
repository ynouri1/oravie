<?php
require_once 'auth.php';
requireAuth();

$pdo = getDB();

// Migration : colonne lot_id sur commandes (au cas où commande.php n'a jamais tourné)
try {
    if (empty($pdo->query("SHOW COLUMNS FROM commandes LIKE 'lot_id'")->fetchAll())) {
        $pdo->exec("ALTER TABLE commandes ADD COLUMN lot_id INT NULL DEFAULT NULL");
    }
} catch (Exception $e) {}

// Migration : colonne prix_praticien sur produits (prix spécial, jamais exposé au public)
try {
    if (empty($pdo->query("SHOW COLUMNS FROM produits LIKE 'prix_praticien'")->fetchAll())) {
        $pdo->exec("ALTER TABLE produits ADD COLUMN prix_praticien DECIMAL(8,2) NULL DEFAULT NULL");
    }
} catch (Exception $e) {}

$praticiens = $pdo->query("
    SELECT id, prenom, nom, specialite, telephone, email, adresse, ville, code_postal
    FROM praticiens WHERE actif = 1 ORDER BY nom, prenom ASC
")->fetchAll();

$produits = $pdo->query("
    SELECT id, nom, volume_ml, prix, prix_praticien, stock
    FROM produits WHERE actif = 1 ORDER BY volume_ml ASC
")->fetchAll();

$lots = [];
try {
    $lots = $pdo->query("SELECT id, numero, nom FROM lots WHERE actif = 1 ORDER BY numero ASC")->fetchAll();
} catch (Exception $e) {}

$validStatuts = ['nouvelle', 'confirmée', 'expédiée', 'livrée'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $praticien_id = filter_var($_POST['praticien_id'] ?? '', FILTER_VALIDATE_INT);
    $lot_id       = filter_var($_POST['lot_id'] ?? '', FILTER_VALIDATE_INT) ?: null;
    $statut       = in_array($_POST['statut'] ?? '', $validStatuts) ? $_POST['statut'] : 'confirmée';
    $notes        = trim(strip_tags($_POST['notes'] ?? ''));
    $lignesInput  = $_POST['lignes'] ?? [];

    if (!$praticien_id) {
        $errors[] = 'Veuillez sélectionner un praticien.';
    }

    $lignesValides = [];
    if (is_array($lignesInput)) {
        foreach ($lignesInput as $l) {
            $pid = filter_var($l['produit_id'] ?? '', FILTER_VALIDATE_INT);
            $qte = filter_var($l['quantite'] ?? '', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $pu  = filter_var($l['prix_unitaire'] ?? '', FILTER_VALIDATE_FLOAT);
            if (!$pid || !$qte || $pu === false || $pu < 0) continue;
            $lignesValides[] = ['produit_id' => $pid, 'quantite' => $qte, 'prix_unitaire' => $pu];
        }
    }
    if (empty($lignesValides)) {
        $errors[] = 'Veuillez ajouter au moins une ligne produit valide.';
    }

    $lignesCommande = [];
    $prix_total = 0;

    if (!$errors) {
        $stmtP = $pdo->prepare("SELECT id, nom, volume_ml, stock FROM produits WHERE id = :id AND actif = 1");
        foreach ($lignesValides as $ligne) {
            $stmtP->execute([':id' => $ligne['produit_id']]);
            $produit = $stmtP->fetch();
            if (!$produit) {
                $errors[] = 'Produit #' . $ligne['produit_id'] . ' introuvable.';
                break;
            }
            if ($produit['stock'] < $ligne['quantite']) {
                $errors[] = 'Stock insuffisant pour ' . $produit['nom'] . ' (reste ' . $produit['stock'] . ').';
                break;
            }
            $sousTotal = round($ligne['prix_unitaire'] * $ligne['quantite'], 2);
            $lignesCommande[] = [
                'produit_id'    => $produit['id'],
                'produit_nom'   => $produit['nom'],
                'volume_ml'     => $produit['volume_ml'],
                'quantite'      => $ligne['quantite'],
                'prix_unitaire' => $ligne['prix_unitaire'],
                'sous_total'    => $sousTotal,
            ];
            $prix_total += $sousTotal;
        }
        $prix_total = round($prix_total, 2);
    }

    if (!$errors) {
        $stmtPr = $pdo->prepare("SELECT * FROM praticiens WHERE id = :id");
        $stmtPr->execute([':id' => $praticien_id]);
        $prat = $stmtPr->fetch();

        $donnees = json_encode([
            'civilite'        => '',
            'prenom'          => $prat['prenom'] ?? '',
            'nom'             => $prat['nom'] ?? '',
            'email'           => $prat['email'] ?? '',
            'telephone'       => $prat['telephone'] ?? '',
            'adresse'         => $prat['adresse'] ?? '',
            'code_postal'     => $prat['code_postal'] ?? '',
            'ville'           => $prat['ville'] ?? '',
            'instructions'    => $notes,
            'lignes'          => $lignesCommande,
            'prix_total'      => $prix_total,
            'frais_livraison' => 0,
            'prix_total_ttc'  => $prix_total,
            'origine'         => 'admin_praticien',
        ], JSON_UNESCAPED_UNICODE);

        $stmt = $pdo->prepare("
            INSERT INTO commandes (donnees, praticien_id, prix_total, statut, lot_id)
            VALUES (:donnees, :praticien_id, :prix, :statut, :lot_id)
        ");
        $stmt->execute([
            ':donnees'      => $donnees,
            ':praticien_id' => $praticien_id,
            ':prix'         => $prix_total,
            ':statut'       => $statut,
            ':lot_id'       => $lot_id,
        ]);
        $newId = $pdo->lastInsertId();

        $stmtStock = $pdo->prepare("UPDATE produits SET stock = stock - :qte WHERE id = :id");
        foreach ($lignesCommande as $ligne) {
            $stmtStock->execute([':qte' => $ligne['quantite'], ':id' => $ligne['produit_id']]);
        }

        header('Location: commande.php?id=' . $newId . '&created=1'); exit;
    }
}
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Nouvelle commande praticien · ORAVIE Admin</title>
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

    .main { max-width:900px; margin:0 auto; padding:2rem 1.5rem 4rem; }
    .page-title { font-size:1.4rem; font-weight:700; margin-bottom:1.5rem; }

    .error-msg { background:#FEE2E2; color:#DC2626; border:1px solid #FECACA; border-radius:0.8rem; padding:10px 14px; font-size:0.85rem; margin-bottom:1.5rem; }

    .card { background:#fff; border-radius:1.2rem; box-shadow:0 2px 10px rgba(0,0,0,0.05); overflow:hidden; margin-bottom:1.5rem; }
    .card-header { background:#2F4B3C; color:#fff; padding:14px 20px; font-weight:700; font-size:0.9rem; display:flex; align-items:center; gap:8px; }
    .card-body { padding:20px; }

    .form-group { display:flex; flex-direction:column; gap:5px; margin-bottom:14px; }
    .form-group label { font-size:0.75rem; font-weight:600; color:#7D8F76; text-transform:uppercase; letter-spacing:0.5px; }
    .form-group input, .form-group select, .form-group textarea {
      padding:10px 12px; border:1.5px solid #E2E9DA; border-radius:0.6rem;
      font-size:0.9rem; font-family:inherit; color:#2C3A2F; background:#FAFCF8; width:100%; outline:none; transition:0.2s;
    }
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus { border-color:#4A735C; }
    .row { display:flex; gap:1rem; flex-wrap:wrap; }
    .row .form-group { flex:1; min-width:200px; }

    .ligne-row { display:flex; gap:0.8rem; align-items:flex-end; margin-bottom:0.8rem; flex-wrap:wrap; padding-bottom:0.8rem; border-bottom:1px solid #F0F4EC; }
    .ligne-row .form-group { margin-bottom:0; }
    .ligne-produit { flex:2; min-width:220px; }
    .ligne-qte { flex:1; min-width:90px; }
    .ligne-prix { flex:1; min-width:110px; }
    .ligne-total { flex:1; min-width:100px; font-weight:800; color:#2F4B3C; padding:10px 0; }
    .btn-remove { background:#FEE2E2; color:#DC2626; border:none; border-radius:0.6rem; width:38px; height:38px; cursor:pointer; font-size:0.9rem; flex-shrink:0; }
    .btn-remove:hover { background:#FECACA; }

    .btn { padding:10px 20px; border-radius:0.6rem; font-weight:700; font-size:0.85rem; cursor:pointer; border:none; transition:0.2s; display:inline-flex; align-items:center; gap:6px; }
    .btn-add-ligne { background:#EFF3EA; color:#2F4B3C; margin-top:0.5rem; }
    .btn-add-ligne:hover { background:#DCE9D4; }
    .btn-submit { background:#2F4B3C; color:#fff; width:100%; justify-content:center; padding:14px; font-size:0.95rem; }
    .btn-submit:hover { background:#3D6150; }

    .total-box { display:flex; justify-content:space-between; align-items:center; padding:14px 0 0; font-size:1.05rem; font-weight:800; color:#2F4B3C; }

    .hint { font-size:0.78rem; color:#92A389; margin-top:4px; }
  </style>
</head>
<body>
<nav>
  <div class="nav-brand"><i class="fas fa-leaf"></i> ORAVIE <span>Admin</span></div>
  <div class="nav-links">
    <a href="dashboard.php"><i class="fas fa-list-alt"></i> Commandes</a>
    <a href="commande_ajouter.php" class="active"><i class="fas fa-plus-circle"></i> Nouvelle commande</a>
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
  <div class="page-title"><i class="fas fa-plus-circle"></i> Nouvelle commande praticien</div>

  <?php if ($errors): ?>
    <div class="error-msg">
      <?php foreach ($errors as $e): ?><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($e) ?><br><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (empty($praticiens)): ?>
    <div class="error-msg"><i class="fas fa-exclamation-circle"></i> Aucun praticien actif. Ajoutez-en un dans <a href="praticiens.php">Praticiens</a> avant de créer une commande.</div>
  <?php endif; ?>

  <form method="POST" id="formCommande">
    <?= csrfField() ?>
    <div class="card">
      <div class="card-header"><i class="fas fa-stethoscope"></i> Praticien &amp; suivi</div>
      <div class="card-body">
        <div class="row">
          <div class="form-group">
            <label>Praticien</label>
            <select name="praticien_id" required>
              <option value="">— Sélectionner —</option>
              <?php foreach ($praticiens as $p): ?>
                <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['prenom'] . ' ' . $p['nom'] . ($p['specialite'] ? ' — ' . $p['specialite'] : '')) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Lot (optionnel)</label>
            <select name="lot_id">
              <option value="">— Aucun —</option>
              <?php foreach ($lots as $l): ?>
                <option value="<?= $l['id'] ?>">Lot <?= htmlspecialchars($l['numero']) ?><?= $l['nom'] ? ' — ' . htmlspecialchars($l['nom']) : '' ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Statut</label>
            <select name="statut">
              <option value="confirmée" selected>Confirmée</option>
              <option value="nouvelle">Nouvelle</option>
              <option value="expédiée">Expédiée</option>
              <option value="livrée">Livrée</option>
            </select>
            <div class="hint">Le stock du lot n'est décompté en "vendu" que pour les commandes livrées + rattachées à un lot.</div>
          </div>
        </div>
        <div class="form-group">
          <label>Notes / instructions (optionnel)</label>
          <textarea name="notes" rows="2"></textarea>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header"><i class="fas fa-box"></i> Produits commandés</div>
      <div class="card-body">
        <div id="lignesContainer"></div>
        <button type="button" class="btn btn-add-ligne" id="btnAddLigne"><i class="fas fa-plus"></i> Ajouter un produit</button>
        <div class="total-box">
          <span>Total commande</span>
          <span id="totalGlobal">0.00 DT</span>
        </div>
      </div>
    </div>

    <button type="submit" class="btn btn-submit"><i class="fas fa-save"></i> Enregistrer la commande</button>
  </form>
</div>

<script>
const PRODUITS = <?= json_encode($produits, JSON_UNESCAPED_UNICODE) ?>;
let ligneIndex = 0;

function optionsProduits(selectedId) {
  let html = '<option value="">— Choisir un produit —</option>';
  PRODUITS.forEach(function(p) {
    const sel = (String(p.id) === String(selectedId)) ? 'selected' : '';
    html += '<option value="' + p.id + '" data-prix="' + p.prix + '" data-prix-praticien="' + (p.prix_praticien ?? '') + '" data-stock="' + p.stock + '" ' + sel + '>'
          + p.nom + ' (' + p.volume_ml + ' ml) — stock: ' + p.stock + '</option>';
  });
  return html;
}

function addLigne() {
  const idx = ligneIndex++;
  const div = document.createElement('div');
  div.className = 'ligne-row';
  div.dataset.idx = idx;
  div.innerHTML =
    '<div class="form-group ligne-produit">' +
      '<label>Produit</label>' +
      '<select name="lignes[' + idx + '][produit_id]" class="sel-produit" required>' + optionsProduits('') + '</select>' +
    '</div>' +
    '<div class="form-group ligne-qte">' +
      '<label>Quantité</label>' +
      '<input type="number" name="lignes[' + idx + '][quantite]" class="inp-qte" min="1" value="1" required>' +
    '</div>' +
    '<div class="form-group ligne-prix">' +
      '<label>Prix unitaire (DT)</label>' +
      '<input type="number" name="lignes[' + idx + '][prix_unitaire]" class="inp-prix" min="0" step="0.1" required>' +
    '</div>' +
    '<div class="ligne-total"><span class="txt-total">0.00 DT</span></div>' +
    '<button type="button" class="btn-remove"><i class="fas fa-trash"></i></button>';
  document.getElementById('lignesContainer').appendChild(div);
  attachLigneEvents(div);
  recalcTotal();
}

function attachLigneEvents(row) {
  const selProduit = row.querySelector('.sel-produit');
  const inpQte     = row.querySelector('.inp-qte');
  const inpPrix    = row.querySelector('.inp-prix');
  const btnRemove  = row.querySelector('.btn-remove');

  selProduit.addEventListener('change', function() {
    const opt = selProduit.options[selProduit.selectedIndex];
    const prixPraticien = opt.dataset.prixPraticien;
    const prixPublic = opt.dataset.prix;
    inpPrix.value = (prixPraticien !== '' && prixPraticien !== undefined) ? prixPraticien : prixPublic;
    recalcLigne(row);
  });
  inpQte.addEventListener('input', function() { recalcLigne(row); });
  inpPrix.addEventListener('input', function() { recalcLigne(row); });
  btnRemove.addEventListener('click', function() { row.remove(); recalcTotal(); });

  // Pré-remplir le prix dès la création si un produit est déjà sélectionné
  if (selProduit.value) selProduit.dispatchEvent(new Event('change'));
}

function recalcLigne(row) {
  const qte  = parseFloat(row.querySelector('.inp-qte').value) || 0;
  const prix = parseFloat(row.querySelector('.inp-prix').value) || 0;
  row.querySelector('.txt-total').textContent = (qte * prix).toFixed(2) + ' DT';
  recalcTotal();
}

function recalcTotal() {
  let total = 0;
  document.querySelectorAll('.ligne-row').forEach(function(row) {
    const qte  = parseFloat(row.querySelector('.inp-qte').value) || 0;
    const prix = parseFloat(row.querySelector('.inp-prix').value) || 0;
    total += qte * prix;
  });
  document.getElementById('totalGlobal').textContent = total.toFixed(2) + ' DT';
}

document.getElementById('btnAddLigne').addEventListener('click', addLigne);
addLigne(); // une ligne par défaut
</script>
</body>
</html>
