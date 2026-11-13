<?php
// ============================================================
// preview_router.php — front controller for the PHP built-in
// preview server (php -S localhost:8080 preview_router.php).
// Not used by Apache/XAMPP in normal operation.
// ============================================================

$root = __DIR__;

// Built-in server already serves existing files with the -t docroot
// router; here we map "/" to the public landing page and protect the
// private config/ db_backups/ scratch/ includes/ directories.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = ($path === '/' || $path === '') ? '/index.php' : $path;

$blocked = ['/config/', '/db_backups/', '/scratch/', '/includes/'];
foreach ($blocked as $prefix) {
    if (strpos($path, $prefix) === 0) {
        http_response_code(403);
        exit('Forbidden');
    }
}

$file = $root . $path;

// Serve real files (lets the router handle the doc-root / redirects).
if (is_file($file)) {
    if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'php') {
        // Let PHP handle routing decisions (auth checks, redirects).
        return false;
    }
    return false; // static asset passthrough
}

// Anything that maps to a directory → show its index.php if present.
if (is_dir($file)) {
    $index = rtrim($file, '/\\') . DIRECTORY_SEPARATOR . 'index.php';
    if (is_file($index)) {
        return false;
    }
}

// Fall back to the root entry (which redirects to public/index.php).
header('Location: /index.php');
exit;
