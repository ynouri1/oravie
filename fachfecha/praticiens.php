<?php
require_once 'auth.php';
requireAuth();

$pdo = getDB();
$msg = '';
$msgType = 'success';

// Vérifier si la table praticiens existe
$tableExists = false;
try {
    $pdo->query("SELECT 1 FROM praticiens LIMIT 1");
    $tableExists = true;
} catch (Exception $e) {
    // Créer la table si elle n'existe pas
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS praticiens (
                id          INT AUTO_INCREMENT PRIMARY KEY,
                nom         VARCHAR(150)   NOT NULL,
                prenom      VARCHAR(150)   NOT NULL,
                specialite  VARCHAR(150),
                telephone   VARCHAR(20),
                email       VARCHAR(150),
                adresse     TEXT,
                ville       VARCHAR(100),
                code_postal VARCHAR(20),
                date_ajout  DATETIME DEFAULT CURRENT_TIMESTAMP,
                actif       TINYINT(1) NOT NULL DEFAULT 1
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $tableExists = true;
        $msg = 'Table praticiens créée. Vous pouvez maintenant ajouter des praticiens.';
        $msgType = 'success';
    } catch (Exception $createError) {
        $tableExists = false;
        $msg = 'Erreur : impossible de créer la table praticiens. Veuillez exécuter init_db.php.';
        $msgType = 'error';
    }
}


$msg = htmlspecialchars($_GET['msg'] ?? '');
$msgType = $_GET['t'] ?? 'success';

$praticiens = [];
$praticien_edit = null;

if ($tableExists) {
    // Traitement des actions POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        // Ajouter/Modifier un praticien
        if ($action === 'save') {
            $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
            $nom = trim($_POST['nom'] ?? '');
            $prenom = trim($_POST['prenom'] ?? '');
            $specialite = trim($_POST['specialite'] ?? '');
            $telephone = trim($_POST['telephone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $adresse = trim($_POST['adresse'] ?? '');
            $ville = trim($_POST['ville'] ?? '');
            $code_postal = trim($_POST['code_postal'] ?? '');
            $actif = isset($_POST['actif']) ? 1 : 0;

            if ($nom && $prenom) {
                if ($id > 0) {
                    // Modification
                    $pdo->prepare("
                        UPDATE praticiens 
                        SET nom = :nom, prenom = :prenom, specialite = :specialite, 
                            telephone = :telephone, email = :email, adresse = :adresse, 
                            ville = :ville, code_postal = :code_postal, actif = :actif 
                        WHERE id = :id
                    ")->execute([
                        ':nom' => $nom, ':prenom' => $prenom, ':specialite' => $specialite,
                        ':telephone' => $telephone, ':email' => $email, ':adresse' => $adresse,
                        ':ville' => $ville, ':code_postal' => $code_postal, ':actif' => $actif, ':id' => $id
                    ]);
                    $msg = 'Praticien modifié avec succès.';
                } else {
                    // Ajout
                    $pdo->prepare("
                        INSERT INTO praticiens (nom, prenom, specialite, telephone, email, adresse, ville, code_postal, actif)
                        VALUES (:nom, :prenom, :specialite, :telephone, :email, :adresse, :ville, :code_postal, :actif)
                    ")->execute([
                        ':nom' => $nom, ':prenom' => $prenom, ':specialite' => $specialite,
                        ':telephone' => $telephone, ':email' => $email, ':adresse' => $adresse,
                        ':ville' => $ville, ':code_postal' => $code_postal, ':actif' => $actif
                    ]);
                    $msg = 'Praticien ajouté avec succès.';
                }
            } else {
                $msg = 'Le nom et le prénom sont obligatoires.';
                $msgType = 'error';
            }
            header('Location: praticiens.php?msg=' . urlencode($msg) . '&t=' . $msgType); exit;
        }

        // Supprimer un praticien
        if ($action === 'delete') {
            $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
            if ($id) {
                $pdo->prepare("DELETE FROM praticiens WHERE id = :id")->execute([':id' => $id]);
                $msg = 'Praticien supprimé avec succès.';
            }
            header('Location: praticiens.php?msg=' . urlencode($msg) . '&t=' . $msgType); exit;
        }
    }

    $praticiens = $pdo->query("SELECT * FROM praticiens ORDER BY date_ajout DESC")->fetchAll();

    if (isset($_GET['edit'])) {
        $edit_id = filter_var($_GET['edit'], FILTER_VALIDATE_INT);
        $stmt = $pdo->prepare("SELECT * FROM praticiens WHERE id = :id");
        $stmt->execute([':id' => $edit_id]);
        $praticien_edit = $stmt->fetch();
    }
}
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Praticiens · ORAVIE Admin</title>
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

    .main { max-width:1000px; margin:0 auto; padding:2rem 1.5rem; }
    .page-title { font-size:1.4rem; font-weight:700; margin-bottom:1.5rem; display:flex; align-items:center; gap:10px; }

    .success { background:#D1FAE5; color:#065F46; border:1px solid #A7F3D0; border-radius:0.8rem; padding:12px 16px; font-size:0.85rem; margin-bottom:1.5rem; }
    .error-msg { background:#FEE2E2; color:#DC2626; border:1px solid #FECACA; border-radius:0.8rem; padding:12px 16px; font-size:0.85rem; margin-bottom:1.5rem; }

    .form-card { background:#fff; border-radius:1.2rem; padding:1.5rem; box-shadow:0 2px 10px rgba(0,0,0,0.05); margin-bottom:2rem; }
    .form-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(250px, 1fr)); gap:1.2rem; margin-bottom:1.5rem; }
    .form-group { display:flex; flex-direction:column; gap:5px; }
    .form-group label { font-size:0.7rem; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:#7D8F76; }
    .form-group input, .form-group textarea { border:1.5px solid #E2E9DA; border-radius:0.7rem; padding:10px 12px; font-size:0.9rem; font-family:inherit; outline:none; transition:0.2s; }
    .form-group input:focus, .form-group textarea:focus { border-color:#4A735C; box-shadow:0 0 0 3px rgba(74,115,92,0.1); }
    .form-group textarea { resize:vertical; min-height:80px; }
    .form-actions { display:flex; gap:1rem; justify-content:flex-end; margin-top:1.5rem; }
    .btn { border:none; border-radius:0.8rem; padding:11px 22px; font-size:0.9rem; font-weight:700; cursor:pointer; transition:0.2s; display:inline-flex; align-items:center; gap:8px; }
    .btn-save { background:#2F4B3C; color:#fff; }
    .btn-save:hover { background:#4A735C; }
    .btn-cancel { background:#E2E9DA; color:#7D8F76; }
    .btn-cancel:hover { background:#D1DFD0; }

    /* Toggle */
    .toggle-wrap { display:flex; align-items:center; gap:12px; }
    .toggle input { display:none; }
    .toggle-slider { width:44px; height:24px; background:#E2E9DA; border-radius:12px; position:relative; cursor:pointer; transition:0.2s; }
    .toggle-slider::after { content:''; position:absolute; width:18px; height:18px; background:#fff; border-radius:50%; top:3px; left:3px; transition:0.2s; box-shadow:0 1px 3px rgba(0,0,0,0.2); }
    .toggle input:checked + .toggle-slider { background:#4A735C; }
    .toggle input:checked + .toggle-slider::after { left:23px; }

    /* Table */
    .card { background:#fff; border-radius:1.2rem; box-shadow:0 2px 10px rgba(0,0,0,0.05); overflow:hidden; }
    table { width:100%; border-collapse:collapse; }
    thead th { background:#F4F7F1; padding:12px 16px; text-align:left; font-size:0.7rem; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#7D8F76; }
    tbody tr { border-top:1px solid #F0F4EC; transition:background 0.15s; }
    tbody tr:hover { background:#FAFCF8; }
    td { padding:12px 16px; font-size:0.88rem; vertical-align:middle; }
    .actions { display:flex; gap:8px; }
    .btn-edit, .btn-delete { padding:6px 12px; border-radius:0.6rem; border:none; font-size:0.75rem; font-weight:600; cursor:pointer; transition:0.2s; }
    .btn-edit { background:#DBEAFE; color:#2563EB; }
    .btn-edit:hover { background:#BFDBFE; }
    .btn-delete { background:#FEE2E2; color:#DC2626; }
    .btn-delete:hover { background:#FECACA; }
    .status-badge { font-size:0.75rem; font-weight:700; padding:4px 10px; border-radius:1rem; }
    .status-active { background:#D1FAE5; color:#059669; }
    .status-inactive { background:#FEE2E2; color:#DC2626; }
    .empty { text-align:center; padding:3rem 1.5rem; color:#92A389; }
  </style>
</head>
<body>
<nav>
  <div class="nav-brand"><i class="fas fa-leaf"></i> ORAVIE <span>Admin</span></div>
  <div class="nav-links">
    <a href="dashboard.php"><i class="fas fa-list-alt"></i> Commandes</a>
    <a href="produits.php"><i class="fas fa-box"></i> Produits</a>
    <a href="praticiens.php" class="active"><i class="fas fa-stethoscope"></i> Praticiens</a>
    <a href="stats.php"><i class="fas fa-chart-bar"></i> Statistiques</a>
    <a href="depenses.php"><i class="fas fa-receipt"></i> Dépenses</a>
    <a href="mouvements.php"><i class="fas fa-boxes"></i> Stock lots</a>
    <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Déconnexion</a>
  </div>
</nav>

<div class="main">
  <div class="page-title"><i class="fas fa-stethoscope"></i> Gestion des praticiens</div>

  <?php if ($msg): ?>
    <div class="<?= $msgType === 'success' ? 'success' : 'error-msg' ?>">
      <i class="fas fa-<?= $msgType === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i> <?= $msg ?>
    </div>
  <?php endif; ?>

  <?php if (!$tableExists): ?>
    <div class="error-msg" style="padding:2rem; text-align:center;">
      <i class="fas fa-exclamation-triangle" style="font-size:2rem; margin-bottom:1rem; display:block;"></i>
      <h3 style="margin-bottom:1rem; color:#DC2626;">Table praticiens non configurée</h3>
      <p style="margin-bottom:1rem;">Pour utiliser la gestion des praticiens, vous devez d'abord exécuter le script d'initialisation.</p>
      <p style="font-size:0.85rem;">Veuillez accéder à : <code style="background:#fff; padding:4px 8px; border-radius:4px;">init_db.php</code></p>
    </div>
  <?php else: ?>

  <!-- Formulaire d'ajout/modification -->
  <div class="form-card">
    <h2 style="font-size:1.1rem; margin-bottom:1rem; color:#2F4B3C;">
      <i class="fas fa-<?= $praticien_edit ? 'edit' : 'plus' ?>"></i> 
      <?= $praticien_edit ? 'Modifier le praticien' : 'Ajouter un praticien' ?>
    </h2>
    
    <form method="POST">
      <input type="hidden" name="action" value="save">
      <?php if ($praticien_edit): ?>
        <input type="hidden" name="id" value="<?= $praticien_edit['id'] ?>">
      <?php else: ?>
        <input type="hidden" name="id" value="0">
      <?php endif; ?>

      <div class="form-grid">
        <div class="form-group">
          <label for="prenom">Prénom *</label>
          <input type="text" id="prenom" name="prenom" required 
                 value="<?= htmlspecialchars($praticien_edit['prenom'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="nom">Nom *</label>
          <input type="text" id="nom" name="nom" required 
                 value="<?= htmlspecialchars($praticien_edit['nom'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="specialite">Spécialité</label>
          <input type="text" id="specialite" name="specialite" 
                 value="<?= htmlspecialchars($praticien_edit['specialite'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="telephone">Téléphone</label>
          <input type="tel" id="telephone" name="telephone" 
                 value="<?= htmlspecialchars($praticien_edit['telephone'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="email">Email</label>
          <input type="email" id="email" name="email" 
                 value="<?= htmlspecialchars($praticien_edit['email'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label for="code_postal">Code postal</label>
          <input type="text" id="code_postal" name="code_postal" 
                 value="<?= htmlspecialchars($praticien_edit['code_postal'] ?? '') ?>">
        </div>
      </div>

      <div class="form-grid">
        <div class="form-group">
          <label for="adresse">Adresse</label>
          <textarea id="adresse" name="adresse"><?= htmlspecialchars($praticien_edit['adresse'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
          <label for="ville">Ville</label>
          <input type="text" id="ville" name="ville" 
                 value="<?= htmlspecialchars($praticien_edit['ville'] ?? '') ?>">
        </div>
      </div>

      <div style="margin-bottom:1.5rem;">
        <label style="font-size:0.7rem; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:#7D8F76; display:block; margin-bottom:8px;">Statut</label>
        <label class="toggle-wrap">
          <input type="checkbox" name="actif" <?= (isset($praticien_edit['actif']) && $praticien_edit['actif']) || !$praticien_edit ? 'checked' : '' ?>>
          <span class="toggle-slider"></span>
          <span style="font-size:0.85rem;">Actif</span>
        </label>
      </div>

      <div class="form-actions">
        <?php if ($praticien_edit): ?>
          <a href="praticiens.php" class="btn btn-cancel"><i class="fas fa-times"></i> Annuler</a>
        <?php endif; ?>
        <button type="submit" class="btn btn-save"><i class="fas fa-save"></i> Enregistrer</button>
      </div>
    </form>
  </div>

  <!-- Liste des praticiens -->
  <div class="card">
    <?php if (empty($praticiens)): ?>
      <div class="empty">
        <i class="fas fa-inbox" style="font-size:2rem; margin-bottom:1rem;"></i><br>
        Aucun praticien enregistré.
      </div>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Prénom</th>
            <th>Nom</th>
            <th>Spécialité</th>
            <th>Téléphone</th>
            <th>Email</th>
            <th>Ville</th>
            <th>Statut</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($praticiens as $p): ?>
            <tr>
              <td><?= htmlspecialchars($p['prenom']) ?></td>
              <td><?= htmlspecialchars($p['nom']) ?></td>
              <td><?= htmlspecialchars($p['specialite'] ?? '-') ?></td>
              <td><?= htmlspecialchars($p['telephone'] ?? '-') ?></td>
              <td><?= htmlspecialchars($p['email'] ?? '-') ?></td>
              <td><?= htmlspecialchars($p['ville'] ?? '-') ?></td>
              <td>
                <span class="status-badge <?= $p['actif'] ? 'status-active' : 'status-inactive' ?>">
                  <?= $p['actif'] ? 'Actif' : 'Inactif' ?>
                </span>
              </td>
              <td>
                <div class="actions">
                  <a href="praticiens.php?edit=<?= $p['id'] ?>" class="btn-edit"><i class="fas fa-edit"></i> Modifier</a>
                  <form method="POST" style="display:inline;">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= $p['id'] ?>">
                    <button type="submit" class="btn-delete" onclick="return confirm('Confirmer la suppression ?')"><i class="fas fa-trash"></i> Supprimer</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <?php endif; ?>
</div>
</body>
</html>
