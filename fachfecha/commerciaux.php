<?php
require_once 'auth.php';
requireAuth();

$pdo = getDB();
ensureRolesSchema($pdo);
// S'assurer que le rattachement praticien existe (pour le comptage)
try { $pdo->exec("ALTER TABLE praticiens ADD COLUMN commercial_id INT NULL DEFAULT NULL"); } catch (Exception $e) {}

$msg = '';
$msgType = 'success';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrfCheck();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username = trim($_POST['username'] ?? '');
        $nom      = trim($_POST['nom'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || strlen($password) < 6) {
            $msg = 'Identifiant requis et mot de passe d\'au moins 6 caractères.';
            $msgType = 'error';
        } else {
            $chk = $pdo->prepare("SELECT 1 FROM admins WHERE username = :u LIMIT 1");
            $chk->execute([':u' => $username]);
            if ($chk->fetchColumn()) {
                $msg = 'Cet identifiant existe déjà.';
                $msgType = 'error';
            } else {
                $pdo->prepare("INSERT INTO admins (username, password, role, nom, actif) VALUES (:u, :p, 'commercial', :n, 1)")
                    ->execute([
                        ':u' => $username,
                        ':p' => password_hash($password, PASSWORD_DEFAULT),
                        ':n' => ($nom !== '' ? $nom : null),
                    ]);
                $msg = 'Commercial créé avec succès.';
            }
        }
    } elseif ($action === 'toggle') {
        $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
        if ($id) {
            $pdo->prepare("UPDATE admins SET actif = 1 - actif WHERE id = :id AND role = 'commercial'")
                ->execute([':id' => $id]);
            $msg = 'Statut du compte mis à jour.';
        }
    } elseif ($action === 'reset_password') {
        $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
        $password = $_POST['password'] ?? '';
        if ($id && strlen($password) >= 6) {
            $pdo->prepare("UPDATE admins SET password = :p WHERE id = :id AND role = 'commercial'")
                ->execute([':p' => password_hash($password, PASSWORD_DEFAULT), ':id' => $id]);
            $msg = 'Mot de passe réinitialisé.';
        } else {
            $msg = 'Mot de passe trop court (6 caractères minimum).';
            $msgType = 'error';
        }
    }
    header('Location: commerciaux.php?msg=' . urlencode($msg) . '&t=' . $msgType); exit;
}

$msg = htmlspecialchars($_GET['msg'] ?? '');
$msgType = $_GET['t'] ?? 'success';

$commerciaux = [];
try {
    $commerciaux = $pdo->query("
        SELECT a.id, a.username, a.nom, a.actif,
               (SELECT COUNT(*) FROM praticiens p WHERE p.commercial_id = a.id) AS nb_praticiens
        FROM admins a
        WHERE a.role = 'commercial'
        ORDER BY a.nom, a.username
    ")->fetchAll();
} catch (Exception $e) {
    // Fallback si la table praticiens n'existe pas encore
    try {
        $commerciaux = $pdo->query("SELECT id, username, nom, actif, 0 AS nb_praticiens FROM admins WHERE role = 'commercial' ORDER BY nom, username")->fetchAll();
    } catch (Exception $e2) {
        $commerciaux = [];
    }
}
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Commerciaux · ORAVIE Admin</title>
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

    .main { max-width:900px; margin:0 auto; padding:2rem 1.5rem; }
    .page-title { font-size:1.4rem; font-weight:700; margin-bottom:1.5rem; }
    .success { background:#D1FAE5; color:#065F46; border:1px solid #A7F3D0; border-radius:0.8rem; padding:10px 14px; font-size:0.85rem; margin-bottom:1.5rem; }
    .error-msg { background:#FEE2E2; color:#DC2626; border:1px solid #FECACA; border-radius:0.8rem; padding:10px 14px; font-size:0.85rem; margin-bottom:1.5rem; }

    .card { background:#fff; border-radius:1.2rem; padding:1.5rem; box-shadow:0 2px 10px rgba(0,0,0,0.05); margin-bottom:1.5rem; }
    .card-title { font-weight:700; font-size:1rem; margin-bottom:1rem; color:#2F4B3C; }
    .row { display:flex; gap:1rem; flex-wrap:wrap; align-items:flex-end; }
    .field { display:flex; flex-direction:column; gap:5px; flex:1; min-width:160px; }
    .field label { font-size:0.7rem; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:#7D8F76; }
    .field input { border:1.5px solid #E2E9DA; border-radius:0.7rem; padding:10px 12px; font-size:0.9rem; font-family:inherit; outline:none; }
    .field input:focus { border-color:#4A735C; }
    .btn { background:#2F4B3C; color:#fff; border:none; border-radius:0.8rem; padding:10px 20px; font-size:0.9rem; font-weight:700; cursor:pointer; transition:0.2s; white-space:nowrap; }
    .btn:hover { background:#4A735C; }
    .btn-sm { padding:6px 12px; font-size:0.8rem; border-radius:0.6rem; }
    .btn-gray { background:#EFF3EA; color:#2F4B3C; }
    .btn-gray:hover { background:#DCE9D4; }

    table { width:100%; border-collapse:collapse; }
    thead th { background:#F4F7F1; padding:10px 14px; text-align:left; font-size:0.7rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#7D8F76; }
    td { padding:10px 14px; font-size:0.88rem; border-top:1px solid #F0F4EC; vertical-align:middle; }
    .badge { padding:3px 10px; border-radius:1rem; font-size:0.72rem; font-weight:700; }
    .badge.on { background:#D1FAE5; color:#059669; }
    .badge.off { background:#FEE2E2; color:#DC2626; }
    .empty { text-align:center; padding:2rem; color:#92A389; }
    .inline { display:inline; }
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
    <a href="commerciaux.php" class="active"><i class="fas fa-user-tie"></i> Commerciaux</a>
    <a href="visites.php"><i class="fas fa-map-marked-alt"></i> Visites</a>
    <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Déconnexion</a>
  </div>
</nav>

<div class="main">
  <div class="page-title"><i class="fas fa-user-tie"></i> Commerciaux</div>

  <?php if ($msg): ?>
    <div class="<?= $msgType === 'error' ? 'error-msg' : 'success' ?>">
      <i class="fas fa-<?= $msgType === 'error' ? 'exclamation-circle' : 'check-circle' ?>"></i> <?= $msg ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <div class="card-title"><i class="fas fa-user-plus"></i> Créer un compte commercial</div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="create">
      <div class="row">
        <div class="field">
          <label>Nom complet</label>
          <input type="text" name="nom" placeholder="Jean Dupont">
        </div>
        <div class="field">
          <label>Identifiant *</label>
          <input type="text" name="username" required autocomplete="off" placeholder="jdupont">
        </div>
        <div class="field">
          <label>Mot de passe *</label>
          <input type="text" name="password" required autocomplete="new-password" placeholder="6 caractères min.">
        </div>
        <button type="submit" class="btn"><i class="fas fa-plus"></i> Créer</button>
      </div>
    </form>
  </div>

  <div class="card">
    <div class="card-title"><i class="fas fa-users"></i> Comptes existants</div>
    <?php if (empty($commerciaux)): ?>
      <div class="empty"><i class="fas fa-inbox"></i> Aucun commercial pour le moment.</div>
    <?php else: ?>
    <table>
      <thead>
        <tr><th>Nom</th><th>Identifiant</th><th>Praticiens</th><th>Statut</th><th>Actions</th></tr>
      </thead>
      <tbody>
      <?php foreach ($commerciaux as $c): ?>
        <tr>
          <td><strong><?= htmlspecialchars($c['nom'] ?: '—') ?></strong></td>
          <td><?= htmlspecialchars($c['username']) ?></td>
          <td><?= (int)$c['nb_praticiens'] ?></td>
          <td><span class="badge <?= $c['actif'] ? 'on' : 'off' ?>"><?= $c['actif'] ? 'Actif' : 'Désactivé' ?></span></td>
          <td>
            <form method="POST" class="inline">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="toggle">
              <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <button type="submit" class="btn btn-sm btn-gray"><?= $c['actif'] ? 'Désactiver' : 'Activer' ?></button>
            </form>
            <form method="POST" class="inline" onsubmit="return confirm('Réinitialiser le mot de passe de ce commercial ?');">
              <?= csrfField() ?>
              <input type="hidden" name="action" value="reset_password">
              <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <input type="text" name="password" placeholder="Nouveau mot de passe" style="border:1.5px solid #E2E9DA;border-radius:0.6rem;padding:5px 10px;font-size:0.8rem;width:150px;">
              <button type="submit" class="btn btn-sm btn-gray">Réinitialiser</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
