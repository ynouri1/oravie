<?php
require_once 'auth.php';
requirePartner();

$pdo = getDB();
ensureSchema($pdo);
$me = currentPartnerId();

$msg = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_praticien') {
    csrfCheck();
    $nom        = trim($_POST['nom'] ?? '');
    $prenom     = trim($_POST['prenom'] ?? '');
    $specialite = trim($_POST['specialite'] ?? '');
    $telephone  = trim($_POST['telephone'] ?? '');
    $email      = trim($_POST['email'] ?? '');
    $adresse    = trim($_POST['adresse'] ?? '');
    $ville      = trim($_POST['ville'] ?? '');
    $code_postal = trim($_POST['code_postal'] ?? '');

    if ($nom === '' || $prenom === '') {
        $msg = 'Le nom et le prénom sont obligatoires.';
        $msgType = 'error';
    } else {
        $pdo->prepare("
            INSERT INTO praticiens (nom, prenom, specialite, telephone, email, adresse, ville, code_postal, actif, commercial_id)
            VALUES (:nom, :prenom, :specialite, :telephone, :email, :adresse, :ville, :code_postal, 1, :me)
        ")->execute([
            ':nom' => $nom, ':prenom' => $prenom, ':specialite' => $specialite,
            ':telephone' => $telephone, ':email' => $email, ':adresse' => $adresse,
            ':ville' => $ville, ':code_postal' => $code_postal, ':me' => $me,
        ]);
        $msg = 'Praticien ajouté.';
    }
    header('Location: dashboard.php?msg=' . urlencode($msg) . '&t=' . $msgType); exit;
}

$msg = htmlspecialchars($_GET['msg'] ?? '');
$msgType = $_GET['t'] ?? 'success';

// Uniquement les praticiens du commercial connecté
$stmt = $pdo->prepare("
    SELECT p.id, p.prenom, p.nom, p.specialite, p.ville, p.telephone,
           (SELECT COUNT(*) FROM visites v WHERE v.praticien_id = p.id) AS nb_visites,
           (SELECT MAX(v.date_visite) FROM visites v WHERE v.praticien_id = p.id) AS derniere_visite
    FROM praticiens p
    WHERE p.commercial_id = :me
    ORDER BY p.nom, p.prenom
");
$stmt->execute([':me' => $me]);
$praticiens = $stmt->fetchAll();
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Mes praticiens · ORAVIE</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body { background:#F4F7F1; font-family:'Segoe UI',sans-serif; color:#2C3A2F; min-height:100vh; }
    nav { background:#2F4B3C; padding:0 1.5rem; display:flex; align-items:center; justify-content:space-between; height:58px; box-shadow:0 2px 8px rgba(0,0,0,0.15); }
    .nav-brand { color:#fff; font-size:1.1rem; font-weight:700; display:flex; align-items:center; gap:8px; }
    .nav-brand span { font-size:0.68rem; letter-spacing:2px; color:#A0C4A8; text-transform:uppercase; }
    .nav-right { display:flex; align-items:center; gap:1rem; }
    .nav-user { color:#A0C4A8; font-size:0.82rem; }
    .nav-right a.logout { color:#F87171; text-decoration:none; font-size:0.85rem; padding:6px 14px; border-radius:1rem; }
    .nav-right a.logout:hover { background:rgba(255,255,255,0.12); }

    .main { max-width:900px; margin:0 auto; padding:2rem 1.5rem; }
    .page-title { font-size:1.4rem; font-weight:700; margin-bottom:1.5rem; }
    .success { background:#D1FAE5; color:#065F46; border:1px solid #A7F3D0; border-radius:0.8rem; padding:10px 14px; font-size:0.85rem; margin-bottom:1.5rem; }
    .error-msg { background:#FEE2E2; color:#DC2626; border:1px solid #FECACA; border-radius:0.8rem; padding:10px 14px; font-size:0.85rem; margin-bottom:1.5rem; }

    .card { background:#fff; border-radius:1.2rem; padding:1.5rem; box-shadow:0 2px 10px rgba(0,0,0,0.05); margin-bottom:1.5rem; }
    .card-title { font-weight:700; font-size:1rem; margin-bottom:1rem; color:#2F4B3C; cursor:pointer; display:flex; align-items:center; gap:8px; }
    .grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
    .field { display:flex; flex-direction:column; gap:5px; }
    .field.full { grid-column:1 / -1; }
    .field label { font-size:0.7rem; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:#7D8F76; }
    .field input, .field textarea { border:1.5px solid #E2E9DA; border-radius:0.7rem; padding:10px 12px; font-size:0.9rem; font-family:inherit; outline:none; }
    .field input:focus, .field textarea:focus { border-color:#4A735C; }
    .btn { background:#2F4B3C; color:#fff; border:none; border-radius:0.8rem; padding:11px 22px; font-size:0.9rem; font-weight:700; cursor:pointer; transition:0.2s; margin-top:1rem; }
    .btn:hover { background:#4A735C; }

    table { width:100%; border-collapse:collapse; }
    thead th { background:#F4F7F1; padding:11px 14px; text-align:left; font-size:0.7rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#7D8F76; }
    td { padding:11px 14px; font-size:0.88rem; border-top:1px solid #F0F4EC; vertical-align:middle; }
    .btn-view { background:#EFF3EA; color:#2F4B3C; border:none; border-radius:0.6rem; padding:6px 14px; font-size:0.8rem; font-weight:600; cursor:pointer; text-decoration:none; }
    .btn-view:hover { background:#DCE9D4; }
    .empty { text-align:center; padding:2.5rem; color:#92A389; }
    .empty i { font-size:2rem; display:block; margin-bottom:0.5rem; }
    @media (max-width:768px){ .grid{grid-template-columns:1fr;} table{display:block;overflow-x:auto;} }
  </style>
</head>
<body>
<nav>
  <div class="nav-brand"><i class="fas fa-user-tie"></i> ORAVIE <span>Commercial</span></div>
  <div class="nav-right">
    <span class="nav-user"><i class="fas fa-user"></i> <?= htmlspecialchars($_SESSION['partner_nom'] ?? '') ?></span>
    <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Déconnexion</a>
  </div>
</nav>

<div class="main">
  <div class="page-title"><i class="fas fa-stethoscope"></i> Mes praticiens</div>

  <?php if ($msg): ?>
    <div class="<?= $msgType === 'error' ? 'error-msg' : 'success' ?>">
      <i class="fas fa-<?= $msgType === 'error' ? 'exclamation-circle' : 'check-circle' ?>"></i> <?= $msg ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-title" onclick="document.getElementById('addForm').hidden = !document.getElementById('addForm').hidden;">
      <i class="fas fa-plus-circle"></i> Ajouter un praticien
    </div>
    <form method="POST" id="addForm" <?= $msgType === 'error' ? '' : 'hidden' ?>>
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_praticien">
      <div class="grid">
        <div class="field">
          <label>Prénom *</label>
          <input type="text" name="prenom" required value="<?= htmlspecialchars($_POST['prenom'] ?? '') ?>">
        </div>
        <div class="field">
          <label>Nom *</label>
          <input type="text" name="nom" required value="<?= htmlspecialchars($_POST['nom'] ?? '') ?>">
        </div>
        <div class="field">
          <label>Spécialité</label>
          <input type="text" name="specialite" value="<?= htmlspecialchars($_POST['specialite'] ?? '') ?>">
        </div>
        <div class="field">
          <label>Téléphone</label>
          <input type="tel" name="telephone" value="<?= htmlspecialchars($_POST['telephone'] ?? '') ?>">
        </div>
        <div class="field">
          <label>Email</label>
          <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
        </div>
        <div class="field">
          <label>Ville</label>
          <input type="text" name="ville" value="<?= htmlspecialchars($_POST['ville'] ?? '') ?>">
        </div>
        <div class="field">
          <label>Code postal</label>
          <input type="text" name="code_postal" value="<?= htmlspecialchars($_POST['code_postal'] ?? '') ?>">
        </div>
        <div class="field full">
          <label>Adresse</label>
          <textarea name="adresse"><?= htmlspecialchars($_POST['adresse'] ?? '') ?></textarea>
        </div>
      </div>
      <button type="submit" class="btn"><i class="fas fa-save"></i> Ajouter</button>
    </form>
  </div>

  <div class="card">
    <?php if (empty($praticiens)): ?>
      <div class="empty"><i class="fas fa-inbox"></i> Aucun praticien ne vous est encore assigné.<br>Ajoutez-en un avec le bouton ci-dessus.</div>
    <?php else: ?>
    <table>
      <thead>
        <tr><th>Praticien</th><th>Spécialité</th><th>Ville</th><th>Visites</th><th>Dernière visite</th><th></th></tr>
      </thead>
      <tbody>
      <?php foreach ($praticiens as $p): ?>
        <tr>
          <td><strong><?= htmlspecialchars($p['prenom'] . ' ' . $p['nom']) ?></strong><br><small style="color:#92A389;"><?= htmlspecialchars($p['telephone'] ?? '') ?></small></td>
          <td><?= htmlspecialchars($p['specialite'] ?: '—') ?></td>
          <td><?= htmlspecialchars($p['ville'] ?: '—') ?></td>
          <td><?= (int)$p['nb_visites'] ?></td>
          <td><?= $p['derniere_visite'] ? htmlspecialchars(date('d/m/Y', strtotime($p['derniere_visite']))) : '—' ?></td>
          <td><a href="praticien.php?id=<?= (int)$p['id'] ?>" class="btn-view"><i class="fas fa-pen"></i> Gérer</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
