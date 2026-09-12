<?php
require_once 'auth.php';
// Ne déconnecte que l'espace commercial (préserve une éventuelle session admin parallèle)
unset($_SESSION['partner_id'], $_SESSION['partner_user'], $_SESSION['partner_nom'], $_SESSION['partner_last'], $_SESSION['partner_csrf']);
header('Location: index.php'); exit;
