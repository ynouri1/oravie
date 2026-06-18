<?php
require_once 'auth.php';

if (!empty($_SESSION['admin_id'])) {
    header('Location: dashboard.php'); exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
        try {
            $pdo  = getDB();
            $stmt = $pdo->prepare("SELECT id, password FROM admins WHERE username = :u LIMIT 1");
            $stmt->execute([':u' => $username]);
            $admin = $stmt->fetch();

            if ($admin && password_verify($password, $admin['password'])) {
                session_regenerate_id(true);
                $_SESSION['admin_id']      = $admin['id'];
                $_SESSION['admin_user']    = $username;
                $_SESSION['last_activity'] = time();
                header('Location: dashboard.php'); exit;
            } else {
                $error = 'Identifiant ou mot de passe incorrect.';
            }
        } catch (PDOException $e) {
            $error = 'Erreur de connexion à la base de données.';
        }
    } else {
        $error = 'Veuillez remplir tous les champs.';
    }
}
?><!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>ORAVIE · Administration</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <style>
    * { margin:0; padding:0; box-sizing:border-box; }
    body { min-height:100vh; background:linear-gradient(135deg,#2F4B3C,#4A735C); display:flex; align-items:center; justify-content:center; font-family:'Segoe UI',sans-serif; }
    .card { background:#fff; border-radius:1.5rem; padding:2.5rem 2rem; width:100%; max-width:380px; box-shadow:0 25px 50px rgba(0,0,0,0.25); }
    .logo { text-align:center; margin-bottom:2rem; }
    .logo-icon { background:#DCE9D4; width:60px; height:60px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:1.8rem; color:#4A735C; margin-bottom:0.8rem; }
    .logo h1 { font-size:1.6rem; color:#2F4B3C; font-weight:700; }
    .logo p { font-size:0.72rem; color:#92A389; letter-spacing:2px; text-transform:uppercase; margin-top:2px; }
    .form-group { margin-bottom:1.2rem; }
    label { display:block; font-size:0.72rem; font-weight:600; text-transform:uppercase; letter-spacing:0.5px; color:#7D8F76; margin-bottom:5px; }
    input { width:100%; padding:13px 16px; border-radius:0.8rem; border:1.5px solid #E2E9DA; font-size:0.95rem; font-family:inherit; transition:0.2s; outline:none; }
    input:focus { border-color:#4A735C; box-shadow:0 0 0 3px rgba(74,115,92,0.1); }
    .btn { width:100%; background:linear-gradient(135deg,#2F4B3C,#4A735C); border:none; border-radius:2rem; padding:14px; font-weight:700; font-size:1rem; color:#fff; cursor:pointer; margin-top:0.5rem; transition:0.2s; }
    .btn:hover { opacity:0.9; }
    .error { background:#FEE2E2; color:#DC2626; border:1px solid #FECACA; border-radius:0.8rem; padding:10px 14px; font-size:0.85rem; margin-bottom:1rem; }
    .timeout { background:#FEF3C7; color:#D97706; border:1px solid #FDE68A; border-radius:0.8rem; padding:10px 14px; font-size:0.85rem; margin-bottom:1rem; }
  </style>
</head>
<body>
<div class="card">
  <div class="logo">
    <div class="logo-icon"><i class="fas fa-leaf"></i></div>
    <h1>ORAVIE</h1>
    <p>Administration</p>
  </div>

  <?php if ($error): ?>
    <div class="error"><i class="fas fa-exclamation-circle"></i> <?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <?php if (isset($_GET['timeout'])): ?>
    <div class="timeout"><i class="fas fa-clock"></i> Session expirée, veuillez vous reconnecter.</div>
  <?php endif; ?>

  <form method="POST">
    <div class="form-group">
      <label for="username">Identifiant</label>
      <input type="text" id="username" name="username" required autocomplete="username"
             value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label for="password">Mot de passe</label>
      <input type="password" id="password" name="password" required autocomplete="current-password">
    </div>
    <button type="submit" class="btn"><i class="fas fa-sign-in-alt"></i> Se connecter</button>
  </form>
</div>
</body>
</html>
