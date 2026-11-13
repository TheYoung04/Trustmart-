<?php
/**
 * ai_trust_worker.php  (TM-AI-1.0)
 *
 * Batch recomputation job for the TrustMart AI Trust Layer.
 * Runs the explainable detectors (circular trading, review credibility,
 * chat scam language) and persists ai_* fields on trust_profiles plus an
 * audit row per signal in trust_ai_signals.
 *
 * Hosting-friendly: pure PHP + MySQL, no external services. The baseline
 * Bayesian score is never modified.
 *
 * Usage:
 *   CLI (recommended, cron):
 *     php ai_trust_worker.php                 # all role profiles
 *     php ai_trust_worker.php --user=12       # one user (both roles)
 *   HTTP (external scheduler / cPanel):
 *     GET http://localhost/Trustmart/ai_trust_worker.php?key=SECRET
 *   HTTP runs REQUIRE AI_TRUST_CRON_KEY to be configured and the ?key to match
 *   it; with no key set, HTTP is refused rather than allowed.
 *
 * Windows Task Scheduler example (every 15 min):
 *   C:\xampp\php\php.exe  C:\xampp\htdocs\Trustmart\ai_trust_worker.php
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/ai_trust_engine.php';

$isCli = (PHP_SAPI === 'cli');
header('Content-Type: application/json');

// ── Master switch ────────────────────────────────────────────
if (!AI_TRUST_ENABLED) {
    echo json_encode(['success' => true, 'skipped' => 'AI_TRUST_ENABLED=false']);
    exit;
}

// ── Shared-secret guard for HTTP (fail closed) ───────────────
// As in payment/expire_unpaid.php: `if (!$isCli && $cronKey)` left this endpoint
// wide open exactly when the operator forgot to configure a key, and a single
// anonymous GET rewrites every trust profile in the database.
$cronKey = getenv('AI_TRUST_CRON_KEY');
if (!$isCli) {
    if ($cronKey === false || $cronKey === '') {
        http_response_code(403);
        error_log('[ai_trust_worker] Blocked HTTP trigger: AI_TRUST_CRON_KEY is not configured');
        echo json_encode([
            'success' => false,
            'message' => 'Forbidden — set AI_TRUST_CRON_KEY to allow HTTP triggering, or run this script from cron/CLI.'
        ]);
        exit;
    }
    $given = $_GET['key'] ?? ($_SERVER['HTTP_X_CRON_KEY'] ?? '');
    if (!hash_equals((string) $cronKey, (string) $given)) {
        http_response_code(403);
        error_log('[ai_trust_worker] Blocked HTTP trigger: key mismatch');
        echo json_encode(['success' => false, 'message' => 'Forbidden']);
        exit;
    }
}

// ── Optional --user filter (CLI) / ?user= (HTTP) ─────────────
$onlyUser = 0;
if ($isCli) {
    foreach ($argv as $arg) {
        if (preg_match('/^--user=(\d+)$/', $arg, $m)) { $onlyUser = (int) $m[1]; }
    }
} else {
    $onlyUser = (int) ($_GET['user'] ?? 0);
}

// Shared LLM budget for this whole run (protects cost + host timeouts).
$llmBudget = AI_TRUST_LLM_MAX_CALLS;

// ── Select target (user, role) profiles ──────────────────────
$sql = "SELECT user_id, profile_role FROM trust_profiles";
$params = [];
if ($onlyUser > 0) {
    $sql .= " WHERE user_id = ?";
    $params[] = $onlyUser;
}
$sql .= " ORDER BY user_id";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$targets = $stmt->fetchAll(PDO::FETCH_ASSOC);

$results = [];
$errors = 0;
foreach ($targets as $t) {
    try {
        $results[] = ai_refresh_user($pdo, (int) $t['user_id'], (string) $t['profile_role'], $llmBudget);
    } catch (Throwable $e) {
        $errors++;
        error_log('[ai_trust_worker] user ' . $t['user_id'] . ' ' . $t['profile_role'] . ': ' . $e->getMessage());
    }
}

$flagged = array_values(array_filter($results, fn($r) => !empty($r['flags'])));

echo json_encode([
    'success'       => true,
    'model_version' => AI_TRUST_MODEL_VERSION,
    'llm_enabled'   => AI_TRUST_LLM_ENABLED && AI_TRUST_LLM_API_KEY !== '',
    'processed'     => count($results),
    'flagged'       => count($flagged),
    'errors'        => $errors,
    'at'            => date('c'),
    'sample_flags'  => array_map(fn($r) => ['user' => $r['user_id'], 'role' => $r['role'], 'flags' => $r['flags']], array_slice($flagged, 0, 25)),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
