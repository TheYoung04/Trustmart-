<?php
/**
 * Simple .env file loader for TrustMart
 * 
 * Loads environment variables from .env file into $_ENV and getenv()
 * Falls back to default values if .env doesn't exist
 */

function load_env($path = null) {
    // Default to .env in project root
    if ($path === null) {
        $path = __DIR__ . '/../.env';
    }
    
    // If .env doesn't exist, skip (will use defaults or system env vars)
    if (!file_exists($path)) {
        return;
    }
    
    // Read the file
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    
    foreach ($lines as $line) {
        // Skip comments
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        
        // Parse KEY=VALUE format
        if (strpos($line, '=') !== false) {
            list($key, $value) = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            
            // Remove quotes if present
            if (preg_match('/^(["\'])(.*)\\1$/', $value, $matches)) {
                $value = $matches[2];
            }
            
            // Set environment variable
            if (!array_key_exists($key, $_ENV)) {
                $_ENV[$key] = $value;
                putenv("$key=$value");
            }
        }
    }
}

/**
 * Get environment variable with optional default
 */
function env($key, $default = null) {
    $value = getenv($key);
    
    if ($value === false) {
        return $default;
    }
    
    // Convert string booleans to actual booleans
    switch (strtolower($value)) {
        case 'true':
        case '(true)':
            return true;
        case 'false':
        case '(false)':
            return false;
        case 'empty':
        case '(empty)':
            return '';
        case 'null':
        case '(null)':
            return null;
    }
    
    return $value;
}

// Auto-load .env when this file is included
load_env();

// ── Development mode flag ─────────────────────────────────────
// Controls whether sensitive demo helpers (activation_link,
// reset_link) may be returned in API responses.
//
//  TRUE  → local XAMPP / demo environment (no mail server)
//  FALSE → production (links are delivered via email only)
//
// SECURE BY DEFAULT: the shipped default is FALSE so a production
// deployment never exposes recovery tokens. For local development,
// explicitly opt in by setting  TRUSTMART_DEV_MODE=true  in .env.
//
// Defence in depth: even when TRUE, links are only ever surfaced to
// requests originating from the local machine (see
// includes/mail_helper.php :: trustmart_allow_demo_links()).
if (!defined('TRUSTMART_DEV_MODE')) {
    $dev_env = env('TRUSTMART_DEV_MODE', 'false');
    define('TRUSTMART_DEV_MODE', $dev_env === true || strtolower((string)$dev_env) === 'true');
}
