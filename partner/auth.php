<?php
/**
 * Espace commercial (partner) — authentification isolée de l'admin.
 * Clé de session dédiée : partner_id (aucun accès croisé avec /fachfecha/).
 */
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'samesite' => 'Lax',
    ]);
    session_start();
}

define('PARTNER_SESSION_TIMEOUT', 1800); // 30 min

function getDB(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $env = parse_ini_file(__DIR__ . '/../envprod');
    if (!$env) { http_response_code(500); die('Erreur configuration.'); }
    $dsn = 'mysql:host=' . $env['DB_HOST'] . ';dbname=' . $env['DB_NAME'] . ';charset=utf8mb4';
    $pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASS'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

/**
 * Garantit la présence des colonnes/tables nécessaires à l'espace commercial.
 */
function ensureSchema(PDO $pdo): void {
    try { $pdo->exec("ALTER TABLE admins ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'admin'"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE admins ADD COLUMN nom VARCHAR(150) NULL"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE admins ADD COLUMN actif TINYINT(1) NOT NULL DEFAULT 1"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE praticiens ADD COLUMN commercial_id INT NULL DEFAULT NULL"); } catch (Exception $e) {}
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS visites (
                id            INT AUTO_INCREMENT PRIMARY KEY,
                praticien_id  INT NOT NULL,
                commercial_id INT NULL,
                date_visite   DATE NOT NULL,
                compte_rendu  TEXT,
                date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_praticien (praticien_id),
                INDEX idx_commercial (commercial_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    } catch (Exception $e) {}
}

/** Exige un commercial connecté. Redirige vers la connexion sinon. */
function requirePartner(): void {
    if (empty($_SESSION['partner_id'])) {
        header('Location: index.php'); exit;
    }
    if (isset($_SESSION['partner_last']) && (time() - $_SESSION['partner_last']) > PARTNER_SESSION_TIMEOUT) {
        unset($_SESSION['partner_id'], $_SESSION['partner_user'], $_SESSION['partner_nom'], $_SESSION['partner_last']);
        header('Location: index.php?timeout=1'); exit;
    }
    $_SESSION['partner_last'] = time();
}

/** Identifiant du commercial connecté. */
function currentPartnerId(): int {
    return (int)($_SESSION['partner_id'] ?? 0);
}

/* ── CSRF ───────────────────────────────────────────────────────────────── */
function csrfToken(): string {
    if (empty($_SESSION['partner_csrf'])) {
        $_SESSION['partner_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['partner_csrf'];
}
function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">';
}
function csrfCheck(): void {
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || $token === '' || !hash_equals($_SESSION['partner_csrf'] ?? '', $token)) {
        http_response_code(419);
        die('Session de sécurité expirée ou invalide. Revenez en arrière, rechargez la page et réessayez.');
    }
}
