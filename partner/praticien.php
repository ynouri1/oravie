<?php
require_once 'auth.php';
requirePartner();

$pdo = getDB();
ensureSchema($pdo);
$me = currentPartnerId();

$id = filter_var($_GET['id'] ?? ($_POST['id'] ?? ''), FILTER_VALIDATE_INT);

// Contrôle de propriété : le praticien doit appartenir au commercial connecté
function loadOwnedPraticien(PDO $pdo, int $id, int $me) {
    if (!$id) return null;
    $stmt = $pdo->prepare("SELECT * FROM praticiens WHERE id = :id AND commercial_id = :me");
    $stmt->execute([':id' => $id, ':me' => $me]);
    return $stmt->fetch() ?: null;
}

$praticien = loadOwnedPraticien($pdo, (int)$id, $me);
if (!$praticien) {
    // Inexistant ou non rattaché à ce commercial : on ne divulgue rien
    header('Location: dashboard.php'); exit;
}

$msg = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = $_POST['action'] ?? '';

    if ($action === 'update_praticien') {
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
            // Le AND commercial_id = :me garantit qu'on ne modifie qu'un praticien qui nous appartient
            $pdo->prepare("
                UPDATE praticiens
                SET nom = :nom, prenom = :prenom, specialite = :specialite,
                    telephone = :telephone, email = :email, adresse = :adresse,
                    ville = :ville, code_postal = :code_postal
                WHERE id = :id AND commercial_id = :me
            ")->execute([
                ':nom' => $nom, ':prenom' => $prenom, ':specialite' => $specialite,
                ':telephone' => $telephone, ':email' => $email, ':adresse' => $adresse,
                ':ville' => $ville, ':code_postal' => $code_postal,
                ':id' => $praticien['id'], ':me' => $me,
            ]);
            $msg = 'Coordonnées mises à jour.';
        }
    } elseif ($action === 'add_visite') {
        $date_visite  = $_POST['date_visite'] ?? '';
        $compte_rendu = trim($_POST['compte_rendu'] ?? '');
        $d = DateTime::createFromFormat('Y-m-d', $date_visite);
        if (!$d || $d->format('Y-m-d') !== $date_visite) {
            $msg = 'Date de visite invalide.';
            $msgType = 'error';
        } else {
            $pdo->prepare("
                INSERT INTO visites (praticien_id, commercial_id, date_visite, compte_rendu)
                VALUES (:pid, :me, :dte, :cr)
            ")->execute([
                ':pid' => $praticien['id'], ':me' => $me,
                ':dte' => $date_visite, ':cr' => $compte_rendu,
            ]);
            $msg = 'Visite enregistrée.';
        }
    } elseif ($action === 'delete_visite') {
        $vid = filter_var($_POST['visite_id'] ?? '', FILTER_VALIDATE_INT);
        if ($vid) {
            // On ne peut supprimer qu'une visite de ce praticien saisie par soi-même
            $pdo->prepare("DELETE FROM visites WHERE id = :vid AND praticien_id = :pid AND commercial_id = :me")
                ->execute([':vid' => $vid, ':pid' => $praticien['id'], ':me' => $me]);
            $msg = 'Visite supprimée.';
        }
    }
    header('Location: praticien.php?id=' . $praticien['id'] . '&msg=' . urlencode($msg) . '&t=' . $msgType); exit;
}

$msg = htmlspecialchars($_GET['msg'] ?? '');
$msgType = $_GET['t'] ?? 'success';

// Historique des visites du praticien
$vstmt = $pdo->prepare("SELECT id, date_visite, compte_rendu, date_creation FROM visites WHERE praticien_id = :pid ORDER BY date_visite DESC, id DESC");
$vstmt->execute([':pid' => $praticien['id']]);
$visites = $vstmt->fetchAll();
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= htmlspecialchars($praticien['prenom'] . ' ' . $praticien['nom']) ?> · ORAVIE</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body { background:#F4F7F1; font-family:'Segoe UI',sans-serif; color:#2C3A2F; min-height:100vh; }
    nav { background:#2F4B3C; padding:0 1.5rem; display:flex; align-items:center; justify-content:space-between; height:58px; box-shadow:0 2px 8px rgba(0,0,0,0.15); }
    .nav-brand { color:#fff; font-size:1.1rem; font-weight:700; display:flex; align-items:center; gap:8px; }
    .nav-brand span { font-size:0.68rem; letter-spacing:2px; color:#A0C4A8; text-transform:uppercase; }
    .nav-right a { color:#A0C4A8; text-decoration:none; font-size:0.85rem; padding:6px 14px; border-radius:1rem; }
    .nav-right a:hover { background:rgba(255,255,255,0.12); color:#fff; }

    .main { max-width:820px; margin:0 auto; padding:2rem 1.5rem; }
    .back { color:#4A735C; text-decoration:none; font-size:0.85rem; display:inline-block; margin-bottom:1rem; }
    .page-title { font-size:1.4rem; font-weight:700; margin-bottom:1.5rem; }
    .success { background:#D1FAE5; color:#065F46; border:1px solid #A7F3D0; border-radius:0.8rem; padding:10px 14px; font-size:0.85rem; margin-bottom:1.5rem; }
    .error-msg { background:#FEE2E2; color:#DC2626; border:1px solid #FECACA; border-radius:0.8rem; padding:10px 14px; font-size:0.85rem; margin-bottom:1.5rem; }

    .card { background:#fff; border-radius:1.2rem; padding:1.5rem; box-shadow:0 2px 10px rgba(0,0,0,0.05); margin-bottom:1.5rem; }
    .card-title { font-weight:700; font-size:1rem; margin-bottom:1rem; color:#2F4B3C; }
    .grid { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
    .field { display:flex; flex-direction:column; gap:5px; }
    .field.full { grid-column:1 / -1; }
    .field label { font-size:0.7rem; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:#7D8F76; }
    .field input, .field textarea { border:1.5px solid #E2E9DA; border-radius:0.7rem; padding:10px 12px; font-size:0.9rem; font-family:inherit; outline:none; }
    .field input:focus, .field textarea:focus { border-color:#4A735C; }
    .btn { background:#2F4B3C; color:#fff; border:none; border-radius:0.8rem; padding:11px 22px; font-size:0.9rem; font-weight:700; cursor:pointer; transition:0.2s; margin-top:1rem; }
    .btn:hover { background:#4A735C; }

    .visite { border-left:3px solid #4A735C; background:#F9FBF7; border-radius:0 0.6rem 0.6rem 0; padding:12px 16px; margin-bottom:12px; }
    .visite .date { font-weight:700; color:#2F4B3C; font-size:0.9rem; }
    .visite .cr { color:#4A735C; font-size:0.88rem; margin-top:6px; white-space:pre-wrap; }
    .visite form { margin-top:8px; }
    .btn-del { background:transparent; border:none; color:#DC2626; font-size:0.78rem; cursor:pointer; padding:0; }
    .empty { color:#92A389; font-size:0.9rem; }
    @media (max-width:768px){ .grid{grid-template-columns:1fr;} }
  </style>
</head>
<body>
<nav>
  <div class="nav-brand"><i class="fas fa-user-tie"></i> ORAVIE <span>Commercial</span></div>
  <div class="nav-right"><a href="dashboard.php"><i class="fas fa-arrow-left"></i> Mes praticiens</a></div>
</nav>

<div class="main">
  <a href="dashboard.php" class="back"><i class="fas fa-arrow-left"></i> Retour à la liste</a>
  <div class="page-title"><i class="fas fa-stethoscope"></i> <?= htmlspecialchars($praticien['prenom'] . ' ' . $praticien['nom']) ?></div>

  <?php if ($msg): ?>
    <div class="<?= $msgType === 'error' ? 'error-msg' : 'success' ?>">
      <i class="fas fa-<?= $msgType === 'error' ? 'exclamation-circle' : 'check-circle' ?>"></i> <?= $msg ?>
    </div>
  <?php endif; ?>

  <!-- Coordonnées -->
  <div class="card">
    <div class="card-title"><i class="fas fa-id-card"></i> Coordonnées</div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="update_praticien">
      <input type="hidden" name="id" value="<?= (int)$praticien['id'] ?>">
      <div class="grid">
        <div class="field">
          <label>Prénom *</label>
          <input type="text" name="prenom" required value="<?= htmlspecialchars($praticien['prenom']) ?>">
        </div>
        <div class="field">
          <label>Nom *</label>
          <input type="text" name="nom" required value="<?= htmlspecialchars($praticien['nom']) ?>">
        </div>
        <div class="field">
          <label>Spécialité</label>
          <input type="text" name="specialite" value="<?= htmlspecialchars($praticien['specialite'] ?? '') ?>">
        </div>
        <div class="field">
          <label>Téléphone</label>
          <input type="tel" name="telephone" value="<?= htmlspecialchars($praticien['telephone'] ?? '') ?>">
        </div>
        <div class="field">
          <label>Email</label>
          <input type="email" name="email" value="<?= htmlspecialchars($praticien['email'] ?? '') ?>">
        </div>
        <div class="field">
          <label>Ville</label>
          <input type="text" name="ville" value="<?= htmlspecialchars($praticien['ville'] ?? '') ?>">
        </div>
        <div class="field">
          <label>Code postal</label>
          <input type="text" name="code_postal" value="<?= htmlspecialchars($praticien['code_postal'] ?? '') ?>">
        </div>
        <div class="field full">
          <label>Adresse</label>
          <textarea name="adresse"><?= htmlspecialchars($praticien['adresse'] ?? '') ?></textarea>
        </div>
      </div>
      <button type="submit" class="btn"><i class="fas fa-save"></i> Enregistrer</button>
    </form>
  </div>

  <!-- Nouvelle visite -->
  <div class="card">
    <div class="card-title"><i class="fas fa-plus-circle"></i> Ajouter une visite</div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="add_visite">
      <input type="hidden" name="id" value="<?= (int)$praticien['id'] ?>">
      <div class="grid">
        <div class="field">
          <label>Date de la visite *</label>
          <input type="date" name="date_visite" required value="<?= htmlspecialchars(date('Y-m-d')) ?>">
        </div>
        <div class="field full">
          <label>Compte rendu</label>
          <textarea name="compte_rendu" placeholder="Déroulé de la visite, remarques, suivi..." style="min-height:90px;"></textarea>
        </div>
      </div>
      <button type="submit" class="btn"><i class="fas fa-save"></i> Enregistrer la visite</button>
    </form>
  </div>

  <!-- Historique -->
  <div class="card">
    <div class="card-title"><i class="fas fa-history"></i> Historique des visites (<?= count($visites) ?>)</div>
    <?php if (empty($visites)): ?>
      <div class="empty">Aucune visite enregistrée pour ce praticien.</div>
    <?php else: ?>
      <?php foreach ($visites as $v): ?>
        <div class="visite">
          <div class="date"><i class="fas fa-calendar-day"></i> <?= htmlspecialchars(date('d/m/Y', strtotime($v['date_visite']))) ?></div>
          <?php if (!empty($v['compte_rendu'])): ?>
            <div class="cr"><?= nl2br(htmlspecialchars($v['compte_rendu'])) ?></div>
          <?php endif; ?>
          <form method="POST" onsubmit="return confirm('Supprimer cette visite ?');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete_visite">
            <input type="hidden" name="id" value="<?= (int)$praticien['id'] ?>">
            <input type="hidden" name="visite_id" value="<?= (int)$v['id'] ?>">
            <button type="submit" class="btn-del"><i class="fas fa-trash"></i> Supprimer</button>
          </form>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
