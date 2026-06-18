<?php
if (session_status() === PHP_SESSION_NONE) session_start();

define('SESSION_TIMEOUT', 1800); // 30 min

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

function requireAuth(): void {
    if (empty($_SESSION['admin_id'])) {
        header('Location: index.php'); exit;
    }
    if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_TIMEOUT) {
        session_unset(); session_destroy();
        header('Location: index.php?timeout=1'); exit;
    }
    $_SESSION['last_activity'] = time();
}

function statusBadge(string $s): string {
    $m = [
        'nouvelle'  => ['Nouvelle',  '#D97706', '#FEF3C7'],
        'confirmée' => ['Confirmée', '#2563EB', '#DBEAFE'],
        'expédiée'  => ['Expédiée',  '#7C3AED', '#EDE9FE'],
        'livrée'    => ['Livrée',    '#059669', '#D1FAE5'],
        'annulée'   => ['Annulée',   '#DC2626', '#FEE2E2'],
    ];
    [$l, $c, $b] = $m[$s] ?? [$s, '#6B7280', '#F3F4F6'];
    return '<span style="background:'.$b.';color:'.$c.';padding:4px 12px;border-radius:20px;font-size:0.75rem;font-weight:700;white-space:nowrap;">'.htmlspecialchars($l).'</span>';
}
