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
                date_visite DATE,
                notes       TEXT,
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
    // Ajouter les colonnes manquantes si elles n'existent pas
    try {
        $pdo->query("SELECT date_visite FROM praticiens LIMIT 1");
    } catch (Exception $e) {
        try {
            $pdo->exec("ALTER TABLE praticiens ADD COLUMN date_visite DATE, ADD COLUMN notes TEXT");
        } catch (Exception $alterError) {
            // Les colonnes existent peut-être déjà
        }
    }

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
            $date_visite = trim($_POST['date_visite'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            $actif = isset($_POST['actif']) ? 1 : 0;

            if ($nom && $prenom) {
                if ($id > 0) {
                    // Modification
                    $pdo->prepare("
                        UPDATE praticiens 
                        SET nom = :nom, prenom = :prenom, specialite = :specialite, 
                            telephone = :telephone, email = :email, adresse = :adresse, 
                            ville = :ville, code_postal = :code_postal, date_visite = :date_visite,
                            notes = :notes, actif = :actif 
                        WHERE id = :id
                    ")->execute([
                        ':nom' => $nom, ':prenom' => $prenom, ':specialite' => $specialite,
                        ':telephone' => $telephone, ':email' => $email, ':adresse' => $adresse,
                        ':ville' => $ville, ':code_postal' => $code_postal, ':date_visite' => ($date_visite ?: null),
                        ':notes' => $notes, ':actif' => $actif, ':id' => $id
                    ]);
                    $msg = 'Praticien modifié avec succès.';
                } else {
                    // Ajout
                    $pdo->prepare("
                        INSERT INTO praticiens (nom, prenom, specialite, telephone, email, adresse, ville, code_postal, date_visite, notes, actif)
                        VALUES (:nom, :prenom, :specialite, :telephone, :email, :adresse, :ville, :code_postal, :date_visite, :notes, :actif)
                    ")->execute([
                        ':nom' => $nom, ':prenom' => $prenom, ':specialite' => $specialite,
                        ':telephone' => $telephone, ':email' => $email, ':adresse' => $adresse,
                        ':ville' => $ville, ':code_postal' => $code_postal, ':date_visite' => ($date_visite ?: null),
                        ':notes' => $notes, ':actif' => $actif
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
    body { background:#F4F7F1; font-family:'Segoe UI',-apple-system,BlinkMacSystemFont,sans-serif; color:#2C3A2F; min-height:100vh; }
    
    nav { background:#2F4B3C; padding:0 2rem; display:flex; align-items:center; justify-content:space-between; height:58px; box-shadow:0 2px 8px rgba(0,0,0,0.15); }
    .nav-brand { color:#fff; font-size:1.1rem; font-weight:700; display:flex; align-items:center; gap:8px; }
    .nav-brand span { font-size:0.68rem; letter-spacing:2px; color:#A0C4A8; text-transform:uppercase; }
    .nav-links { display:flex; gap:0.3rem; align-items:center; }
    .nav-links a { color:#A0C4A8; text-decoration:none; font-size:0.85rem; padding:6px 14px; border-radius:1rem; transition:0.2s; }
    .nav-links a:hover, .nav-links a.active { background:rgba(255,255,255,0.15); color:#fff; }
    .nav-links a.logout { color:#F87171; }

    .page-title { font-size:1.4rem; font-weight:700; display:flex; align-items:center; gap:10px; }
    .alert { border-radius:0.8rem; padding:12px 16px; font-size:0.85rem; margin-bottom:1.5rem; display:flex; align-items:center; gap:8px; }
    .alert-success { background:#D1FAE5; color:#065F46; border:1px solid #A7F3D0; }
    .alert-error { background:#FEE2E2; color:#DC2626; border:1px solid #FECACA; }

    /* Layout 2 colonnes */
    .container { max-width:1400px; margin:0 auto; padding:2rem 1.5rem; }
    .layout { display:grid; grid-template-columns:38% 1fr; gap:2rem; min-height:calc(100vh - 120px); }

    /* COLONNE GAUCHE : Formulaire */
    .form-panel {
      background:#fff;
      border-radius:1.2rem;
      padding:1.8rem;
      box-shadow:0 2px 10px rgba(0,0,0,0.05);
      height:fit-content;
      position:sticky;
      top:2rem;
    }

    .form-panel h2 { font-size:1.05rem; margin-bottom:1.5rem; display:flex; align-items:center; gap:10px; color:#2F4B3C; }
    .form-group { margin-bottom:1.2rem; }
    .form-group label { display:block; font-size:0.7rem; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:#7D8F76; margin-bottom:6px; }
    .form-group input, .form-group textarea {
      width:100%;
      border:1.5px solid #E2E9DA;
      border-radius:0.7rem;
      padding:11px 13px;
      font-size:0.9rem;
      font-family:inherit;
      outline:none;
      transition:0.2s;
      background:#fafcf8;
    }
    .form-group input:focus, .form-group textarea:focus {
      border-color:#4A735C;
      box-shadow:0 0 0 3px rgba(74,115,92,0.1);
      background:#fff;
    }
    .form-group textarea { resize:vertical; min-height:75px; }

    .form-group.two-col { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
    .form-group.two-col > div { margin-bottom:0; }

    /* Toggle */
    .toggle-wrap { display:flex; align-items:center; gap:12px; margin-top:8px; }
    .toggle-wrap input { display:none; }
    .toggle-slider {
      width:44px;
      height:24px;
      background:#E2E9DA;
      border-radius:12px;
      position:relative;
      cursor:pointer;
      transition:0.2s;
      flex-shrink:0;
    }
    .toggle-slider::after {
      content:'';
      position:absolute;
      width:18px;
      height:18px;
      background:#fff;
      border-radius:50%;
      top:3px;
      left:3px;
      transition:0.2s;
      box-shadow:0 1px 3px rgba(0,0,0,0.2);
    }
    .toggle-wrap input:checked + .toggle-slider { background:#4A735C; }
    .toggle-wrap input:checked + .toggle-slider::after { left:23px; }
    .toggle-wrap label { font-size:0.9rem; font-weight:500; color:#4A735C; cursor:pointer; margin:0; }

    .form-actions {
      display:flex;
      gap:1rem;
      margin-top:2rem;
      padding-top:1.5rem;
      border-top:1px solid #E2E9DA;
    }

    .btn {
      flex:1;
      border:none;
      border-radius:0.8rem;
      padding:12px;
      font-size:0.9rem;
      font-weight:700;
      cursor:pointer;
      transition:0.2s;
      display:flex;
      align-items:center;
      justify-content:center;
      gap:8px;
    }
    .btn-primary { background:#2F4B3C; color:#fff; }
    .btn-primary:hover { background:#4A735C; }
    .btn-secondary { background:#E2E9DA; color:#7D8F76; }
    .btn-secondary:hover { background:#D1DFD0; }
    .btn-danger { background:#FEE2E2; color:#DC2626; }
    .btn-danger:hover { background:#FECACA; }

    /* COLONNE DROITE : Liste */
    .list-panel {
      background:#fff;
      border-radius:1.2rem;
      box-shadow:0 2px 10px rgba(0,0,0,0.05);
      overflow:hidden;
      display:flex;
      flex-direction:column;
    }

    .list-header {
      padding:1.5rem;
      border-bottom:1px solid #E2E9DA;
    }

    .search-filter {
      display:grid;
      grid-template-columns:1fr 180px 150px;
      gap:1rem;
      margin-bottom:1rem;
    }

    .search-input {
      position:relative;
    }

    .search-input input {
      width:100%;
      border:1.5px solid #E2E9DA;
      border-radius:0.7rem;
      padding:11px 13px 11px 36px;
      font-size:0.9rem;
      outline:none;
      transition:0.2s;
    }

    .search-input input:focus {
      border-color:#4A735C;
      box-shadow:0 0 0 3px rgba(74,115,92,0.1);
    }

    .search-input i {
      position:absolute;
      left:12px;
      top:50%;
      transform:translateY(-50%);
      color:#92A389;
      font-size:0.95rem;
    }

    .filter-select {
      border:1.5px solid #E2E9DA;
      border-radius:0.7rem;
      padding:11px 13px;
      font-size:0.9rem;
      background:#fafcf8;
      cursor:pointer;
      outline:none;
      transition:0.2s;
    }

    .filter-select:focus {
      border-color:#4A735C;
      box-shadow:0 0 0 3px rgba(74,115,92,0.1);
    }

    .list-stats { display:flex; gap:2rem; font-size:0.85rem; color:#7D8F76; }
    .list-stat { display:flex; align-items:center; gap:6px; }
    .list-stat .num { font-weight:700; color:#2F4B3C; }

    .list-content {
      flex:1;
      overflow-y:auto;
      max-height:calc(100vh - 250px);
    }

    .list-content::-webkit-scrollbar { width:6px; }
    .list-content::-webkit-scrollbar-track { background:#f1f5f0; }
    .list-content::-webkit-scrollbar-thumb { background:#C0CAB8; border-radius:3px; }
    .list-content::-webkit-scrollbar-thumb:hover { background:#A0B298; }

    .practitioner-item {
      border-bottom:1px solid #F0F4EC;
      padding:1.2rem 1.5rem;
      cursor:pointer;
      transition:0.15s;
      display:flex;
      justify-content:space-between;
      align-items:center;
    }

    .practitioner-item:hover {
      background:#FAFCF8;
    }

    .practitioner-item.selected {
      background:#E8F5F3;
      border-left:4px solid #4A735C;
      padding-left:calc(1.5rem - 4px);
    }

    .practitioner-info { flex:1; min-width:0; }
    .practitioner-name { font-weight:600; color:#2F4B3C; }
    .practitioner-meta { font-size:0.8rem; color:#92A389; margin-top:4px; }
    .practitioner-status { display:flex; gap:8px; margin-top:6px; }

    .badge {
      font-size:0.7rem;
      font-weight:700;
      padding:4px 8px;
      border-radius:1rem;
    }

    .badge-active { background:#D1FAE5; color:#059669; }
    .badge-inactive { background:#FEE2E2; color:#DC2626; }

    .practitioner-actions {
      display:flex;
      gap:6px;
      flex-shrink:0;
    }

    .btn-icon {
      width:32px;
      height:32px;
      border:none;
      border-radius:0.6rem;
      cursor:pointer;
      display:flex;
      align-items:center;
      justify-content:center;
      transition:0.2s;
      font-size:0.75rem;
      background:#F0F4EB;
      color:#4A735C;
    }

    .btn-icon:hover { background:#E2E9DA; }
    .btn-icon.delete { background:#FEE2E2; color:#DC2626; }
    .btn-icon.delete:hover { background:#FECACA; }

    .empty-state {
      text-align:center;
      padding:4rem 2rem;
      color:#92A389;
    }

    .empty-state i { font-size:3rem; margin-bottom:1rem; opacity:0.5; }

    /* Modal */
    .modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:1000; justify-content:center; align-items:center; }
    .modal.active { display:flex; }
    .modal-content {
      background:#fff;
      border-radius:1.2rem;
      padding:2rem;
      max-width:400px;
      box-shadow:0 20px 40px rgba(0,0,0,0.15);
    }
    .modal-title { font-size:1.1rem; font-weight:700; margin-bottom:1rem; color:#2F4B3C; }
    .modal-text { font-size:0.9rem; color:#7D8F76; margin-bottom:1.5rem; line-height:1.5; }
    .modal-actions { display:flex; gap:1rem; }
    .modal-actions .btn { flex:1; }

    /* Responsive */
    @media (max-width:1024px) {
      .layout { grid-template-columns:1fr; }
      .form-panel { position:static; }
      .search-filter { grid-template-columns:1fr 150px; }
    }

    @media (max-width:768px) {
      .search-filter { grid-template-columns:1fr; }
      .layout { padding:1rem; }
      .list-content { max-height:50vh; }
      .practitioner-item { flex-direction:column; align-items:flex-start; }
      .practitioner-actions { width:100%; margin-top:1rem; }
    }

    /* Loading */
    .spinner {
      border:3px solid #E2E9DA;
      border-top-color:#4A735C;
      border-radius:50%;
      width:20px;
      height:20px;
      animation:spin 0.8s linear infinite;
    }
    @keyframes spin { to { transform:rotate(360deg); } }

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
      
      /* Accordion */
      .accordion-item { margin-bottom: 0.75rem; }
      .accordion-button { padding: 1rem 0.75rem; }
      .accordion-content { padding: 1rem; }
      
      /* Forms */
      .form-row { flex-direction: column; gap: 0.75rem; }
      .form-row > div { width: 100%; }
      
      .form-group { margin-bottom: 0.8rem; }
      label { font-size: 0.7rem; margin-bottom: 0.3rem; }
      input[type="text"],
      input[type="email"],
      input[type="tel"],
      input[type="date"],
      select,
      textarea { 
        padding: 10px 12px;
        font-size: 0.85rem;
        width: 100%;
        border-radius: 0.8rem;
      }
      
      .form-actions { 
        flex-direction: column;
        gap: 0.75rem;
      }
      .btn-save,
      .btn-cancel,
      button[type="submit"] {
        width: 100%;
        padding: 0.75rem;
        font-size: 0.9rem;
      }
      
      /* Table */
      table { display: block; }
      thead { display: none; }
      tbody { display: block; }
      tr {
        display: block;
        background: #fff;
        border: 1px solid #E2E9DA;
        border-radius: 0.8rem;
        margin-bottom: 1rem;
        padding: 1rem;
      }
      td {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 0.5rem 0;
        border: none !important;
        font-size: 0.8rem;
        gap: 1rem;
      }
      td::before {
        content: attr(data-label);
        font-weight: 700;
        color: #7D8F76;
        font-size: 0.65rem;
        text-transform: uppercase;
        flex: 0 0 40%;
      }
    }

    @media (max-width: 480px) {
      nav { padding: 0 0.75rem; }
      .main { padding: 1rem 0.75rem; }
      .page-title { font-size: 1rem; }
      
      input[type="text"],
      input[type="email"],
      input[type="tel"],
      input[type="date"],
      select,
      textarea { font-size: 0.8rem; }
      
      .btn-save,
      .btn-cancel,
      button[type="submit"] { font-size: 0.85rem; }
      
      tr { padding: 0.75rem; }
      td { font-size: 0.75rem; }
      td::before { font-size: 0.6rem; flex: 0 0 35%; }
    }
  </style>
</head>
<body>
<nav>
  <div class="nav-brand"><i class="fas fa-leaf"></i> ORAVIE <span>Admin</span></div>
  <div class="nav-links">
    <a href="dashboard.php"><i class="fas fa-list-alt"></i> Commandes</a>
    <a href="commande_ajouter.php"><i class="fas fa-plus-circle"></i> Nouvelle commande</a>
    <a href="produits.php"><i class="fas fa-box"></i> Produits</a>
    <a href="praticiens.php" class="active"><i class="fas fa-stethoscope"></i> Praticiens</a>
    <a href="stats.php"><i class="fas fa-chart-bar"></i> Statistiques</a>
    <a href="depenses.php"><i class="fas fa-receipt"></i> Dépenses</a>
    <a href="mouvements.php"><i class="fas fa-boxes"></i> Stock lots</a>
    <a href="feedback_avis.php"><i class="fas fa-comments"></i> Avis clients</a>
    <a href="logout.php" class="logout"><i class="fas fa-sign-out-alt"></i> Déconnexion</a>
  </div>
</nav>

<div class="container">
  <?php if ($msg): ?>
    <div class="alert alert-<?= $msgType === 'success' ? 'success' : 'error' ?>">
      <i class="fas fa-<?= $msgType === 'success' ? 'check-circle' : 'exclamation-circle' ?>"></i>
      <span><?= $msg ?></span>
    </div>
  <?php endif; ?>

  <?php if (!$tableExists): ?>
    <div class="alert alert-error" style="padding:2rem; text-align:center; margin-top:2rem;">
      <i class="fas fa-exclamation-triangle" style="font-size:2rem; margin-right:1rem;"></i>
      <div>
        <h3 style="margin-bottom:0.5rem; color:#DC2626;">Table praticiens non configurée</h3>
        <p style="font-size:0.9rem;">Veuillez exécuter <code style="background:#fff; padding:4px 8px; border-radius:4px;">init_db.php</code> pour initialiser.</p>
      </div>
    </div>
  <?php else: ?>

  <div class="layout">
    <!-- COLONNE GAUCHE: Formulaire -->
    <div class="form-panel">
      <h2>
        <i class="fas fa-<?= $praticien_edit ? 'edit' : 'plus-circle' ?>"></i>
        <?= $praticien_edit ? 'Modifier' : 'Nouveau praticien' ?>
      </h2>

      <form id="pracForm" method="POST">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="id" id="pracId" value="<?= $praticien_edit['id'] ?? 0 ?>">

        <div class="form-group">
          <label for="prenom">Prénom *</label>
          <input type="text" id="prenom" name="prenom" required placeholder="Jean" 
                 value="<?= htmlspecialchars($praticien_edit['prenom'] ?? '') ?>">
        </div>

        <div class="form-group">
          <label for="nom">Nom *</label>
          <input type="text" id="nom" name="nom" required placeholder="Dupont" 
                 value="<?= htmlspecialchars($praticien_edit['nom'] ?? '') ?>">
        </div>

        <div class="form-group">
          <label for="specialite">Spécialité</label>
          <input type="text" id="specialite" name="specialite" placeholder="Orthodontie" 
                 value="<?= htmlspecialchars($praticien_edit['specialite'] ?? '') ?>">
        </div>

        <div class="form-group">
          <label for="telephone">Téléphone</label>
          <input type="tel" id="telephone" name="telephone" placeholder="+216 XX XXX XXX" 
                 value="<?= htmlspecialchars($praticien_edit['telephone'] ?? '') ?>">
        </div>

        <div class="form-group">
          <label for="email">Email</label>
          <input type="email" id="email" name="email" placeholder="nom@exemple.com" 
                 value="<?= htmlspecialchars($praticien_edit['email'] ?? '') ?>">
        </div>

        <div class="form-group two-col">
          <div>
            <label for="code_postal">Code postal</label>
            <input type="text" id="code_postal" name="code_postal" placeholder="1000" 
                   value="<?= htmlspecialchars($praticien_edit['code_postal'] ?? '') ?>">
          </div>
          <div>
            <label for="ville">Ville</label>
            <input type="text" id="ville" name="ville" placeholder="Tunis" 
                   value="<?= htmlspecialchars($praticien_edit['ville'] ?? '') ?>">
          </div>
        </div>

        <div class="form-group">
          <label for="adresse">Adresse</label>
          <textarea id="adresse" name="adresse" placeholder="Rue..."><?= htmlspecialchars($praticien_edit['adresse'] ?? '') ?></textarea>
        </div>

        <div class="form-group two-col">
          <div>
            <label for="date_visite">Visité le</label>
            <input type="date" id="date_visite" name="date_visite" 
                   value="<?= htmlspecialchars($praticien_edit['date_visite'] ?? '') ?>">
          </div>
        </div>

        <div class="form-group">
          <label for="notes">Notes</label>
          <textarea id="notes" name="notes" placeholder="Ajouter des notes..." style="min-height:100px;"><?= htmlspecialchars($praticien_edit['notes'] ?? '') ?></textarea>
        </div>

        <div class="form-group" style="margin-bottom:0;">
          <label style="margin-bottom:8px;">Statut</label>
          <div class="toggle-wrap">
            <input type="checkbox" id="actifToggle" name="actif" <?= (isset($praticien_edit['actif']) && $praticien_edit['actif']) || !$praticien_edit ? 'checked' : '' ?>>
            <span class="toggle-slider"></span>
            <label for="actifToggle" style="font-size:0.9rem; font-weight:500; color:#4A735C; cursor:pointer; margin:0;">Actif</label>
          </div>
        </div>

        <div class="form-actions">
          <button type="reset" class="btn btn-secondary" title="Réinitialiser le formulaire">
            <i class="fas fa-redo"></i> Réinitialiser
          </button>
          <button type="submit" class="btn btn-primary" title="Enregistrer le praticien">
            <i class="fas fa-save"></i> Enregistrer
          </button>
        </div>
      </form>
    </div>

    <!-- COLONNE DROITE: Liste -->
    <div class="list-panel">
      <div class="list-header">
        <div class="page-title" style="margin-bottom:1rem;">
          <i class="fas fa-stethoscope"></i> Praticiens
        </div>

        <div class="search-filter">
          <div class="search-input">
            <i class="fas fa-search"></i>
            <input type="text" id="searchInput" placeholder="Rechercher par nom, spécialité..." autocomplete="off">
          </div>
          <select id="filterStatus" class="filter-select">
            <option value="">Tous les statuts</option>
            <option value="active">Actifs</option>
            <option value="inactive">Inactifs</option>
          </select>
          <select id="filterSpecialty" class="filter-select">
            <option value="">Toutes les spécialités</option>
            <?php 
              $specialties = $pdo->query("SELECT DISTINCT specialite FROM praticiens WHERE specialite IS NOT NULL AND specialite != '' ORDER BY specialite")->fetchAll();
              foreach ($specialties as $spec) {
                echo '<option value="' . htmlspecialchars($spec['specialite']) . '">' . htmlspecialchars($spec['specialite']) . '</option>';
              }
            ?>
          </select>
        </div>

        <div class="list-stats">
          <div class="list-stat">
            <span>Total:</span>
            <span class="num" id="statTotal"><?= count($praticiens) ?></span>
          </div>
          <div class="list-stat">
            <span>Actifs:</span>
            <span class="num" id="statActive"><?= count(array_filter($praticiens, fn($p) => $p['actif'])) ?></span>
          </div>
        </div>
      </div>

      <div class="list-content" id="listContent">
        <?php if (empty($praticiens)): ?>
          <div class="empty-state">
            <i class="fas fa-inbox"></i>
            <p style="margin-top:1rem; font-size:0.95rem;">Aucun praticien enregistré.</p>
          </div>
        <?php else: ?>
          <?php foreach ($praticiens as $p): ?>
            <div class="practitioner-item" data-id="<?= $p['id'] ?>" data-specialty="<?= htmlspecialchars($p['specialite'] ?? '') ?>" data-status="<?= $p['actif'] ? 'active' : 'inactive' ?>">
              <div class="practitioner-info">
                <div class="practitioner-name"><?= htmlspecialchars($p['prenom']) ?> <strong><?= htmlspecialchars($p['nom']) ?></strong></div>
                <div class="practitioner-meta">
                  <?= htmlspecialchars($p['specialite'] ?? 'Sans spécialité') ?> 
                  <?php if (!empty($p['ville'])): ?>
                    • <?= htmlspecialchars($p['ville']) ?>
                  <?php endif; ?>
                </div>
                <div class="practitioner-status">
                  <span class="badge badge-<?= $p['actif'] ? 'active' : 'inactive' ?>">
                    <?= $p['actif'] ? '● Actif' : '○ Inactif' ?>
                  </span>
                </div>
              </div>
              <div class="practitioner-actions">
                <button class="btn-icon edit-btn" data-id="<?= $p['id'] ?>" title="Modifier" aria-label="Modifier <?= htmlspecialchars($p['prenom']) ?> <?= htmlspecialchars($p['nom']) ?>">
                  <i class="fas fa-edit"></i>
                </button>
                <button class="btn-icon delete delete-btn" data-id="<?= $p['id'] ?>" title="Supprimer" aria-label="Supprimer <?= htmlspecialchars($p['prenom']) ?> <?= htmlspecialchars($p['nom']) ?>">
                  <i class="fas fa-trash"></i>
                </button>
              </div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Modal de confirmation -->
  <div class="modal" id="confirmModal">
    <div class="modal-content">
      <h3 class="modal-title"><i class="fas fa-exclamation-circle" style="color:#DC2626;"></i> Confirmer la suppression</h3>
      <p class="modal-text" id="modalText">Êtes-vous sûr de vouloir supprimer ce praticien ? Cette action est irréversible.</p>
      <div class="modal-actions">
        <button class="btn btn-secondary" id="cancelBtn"><i class="fas fa-times"></i> Annuler</button>
        <button class="btn btn-danger" id="confirmBtn"><i class="fas fa-check"></i> Supprimer</button>
      </div>
    </div>
  </div>

  <?php endif; ?>
</div>

<script>
// Variables globales
let allPraticiens = <?= json_encode($praticiens) ?>;
let deleteId = null;

// AJAX: Charger un praticien pour modification
document.querySelectorAll('.edit-btn').forEach(btn => {
  btn.addEventListener('click', function() {
    const id = this.dataset.id;
    const p = allPraticiens.find(x => x.id == id);
    if (p) {
      document.getElementById('pracId').value = p.id;
      document.getElementById('prenom').value = p.prenom;
      document.getElementById('nom').value = p.nom;
      document.getElementById('specialite').value = p.specialite || '';
      document.getElementById('telephone').value = p.telephone || '';
      document.getElementById('email').value = p.email || '';
      document.getElementById('adresse').value = p.adresse || '';
      document.getElementById('ville').value = p.ville || '';
      document.getElementById('code_postal').value = p.code_postal || '';
      document.getElementById('date_visite').value = p.date_visite || '';
      document.getElementById('notes').value = p.notes || '';
      document.querySelector('input[name="actif"]').checked = p.actif == 1;
      
      document.querySelector('.form-panel h2 i').className = 'fas fa-edit';
      document.querySelector('.form-panel h2').innerHTML = '<i class="fas fa-edit"></i> Modifier';
      document.getElementById('prenom').focus();
      
      // Scroll to form on mobile
      if (window.innerWidth < 1024) {
        document.querySelector('.form-panel').scrollIntoView({behavior: 'smooth'});
      }
    }
  });
});

// Modal de confirmation
document.querySelectorAll('.delete-btn').forEach(btn => {
  btn.addEventListener('click', function(e) {
    e.preventDefault();
    const id = this.dataset.id;
    const p = allPraticiens.find(x => x.id == id);
    if (p) {
      deleteId = id;
      document.getElementById('modalText').textContent = `Supprimer ${p.prenom} ${p.nom} ?`;
      document.getElementById('confirmModal').classList.add('active');
    }
  });
});

document.getElementById('cancelBtn').addEventListener('click', function() {
  document.getElementById('confirmModal').classList.remove('active');
  deleteId = null;
});

document.getElementById('confirmBtn').addEventListener('click', function() {
  if (deleteId) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="id" value="${deleteId}">
    `;
    document.body.appendChild(form);
    form.submit();
  }
});

// Recherche et filtres
const searchInput = document.getElementById('searchInput');
const filterStatus = document.getElementById('filterStatus');
const filterSpecialty = document.getElementById('filterSpecialty');

function filterList() {
  const search = searchInput.value.toLowerCase();
  const status = filterStatus.value;
  const specialty = filterSpecialty.value;

  document.querySelectorAll('.practitioner-item').forEach(item => {
    const name = item.textContent.toLowerCase();
    const itemStatus = item.dataset.status;
    const itemSpecialty = item.dataset.specialty;

    const matchSearch = !search || name.includes(search);
    const matchStatus = !status || itemStatus === status;
    const matchSpecialty = !specialty || itemSpecialty === specialty;

    item.style.display = (matchSearch && matchStatus && matchSpecialty) ? '' : 'none';
  });

  // Update stats
  const visible = document.querySelectorAll('.practitioner-item:not([style*="display: none"])').length;
  document.getElementById('statTotal').textContent = visible;
}

searchInput.addEventListener('input', filterList);
filterStatus.addEventListener('change', filterList);
filterSpecialty.addEventListener('change', filterList);

// Réinitialiser formulaire au chargement
window.addEventListener('DOMContentLoaded', function() {
  document.getElementById('pracForm').addEventListener('reset', function() {
    document.getElementById('pracId').value = 0;
    setTimeout(() => document.getElementById('prenom').focus(), 100);
  });
});
</script>
</body>
</html>
</body>
</html>
