<?php
// ============================================================
// config/db.php
// PDO database connection for TrustMart
// Include this in every PHP file that needs the database:
//   require_once __DIR__ . '/../config/db.php';
// ============================================================

// Load environment variables
require_once __DIR__ . '/env.php';

// ── Connection settings ──────────────────────────────────────
// These values come from environment variables (.env file or system)
// with fallback defaults for local development
define('DB_HOST', env('DB_HOST', 'localhost'));
define('DB_NAME', env('DB_NAME', 'trustmart'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));
define('DB_CHARSET', env('DB_CHARSET', 'utf8mb4'));

// ── Build the DSN ─────────────────────────────────────────────
$dsn = 'mysql:host=' . DB_HOST
     . ';dbname='    . DB_NAME
     . ';charset='   . DB_CHARSET;

// ── PDO options ───────────────────────────────────────────────
// ERRMODE_EXCEPTION  : throws PDOException on every error (no silent failures)
// FETCH_ASSOC        : all fetchAll() calls return associative arrays by default
// EMULATE_PREPARES   : false forces real prepared statements (blocks SQL injection)
// STRINGIFY_FETCHES  : false keeps INT and DECIMAL columns as their native PHP types
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
    PDO::ATTR_STRINGIFY_FETCHES  => false,
];

// ── Create the connection ─────────────────────────────────────
try {
    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (PDOException $e) {
    // Never echo $e->getMessage() on a live site — it exposes credentials.
    // Log to a file instead, then show a safe message to the user.
    error_log('[TrustMart DB Error] ' . $e->getMessage());
    http_response_code(503);
    die(json_encode([
        'success' => false,
        'message' => 'Sambungan pangkalan data gagal. Sila cuba lagi.'
    ]));
}