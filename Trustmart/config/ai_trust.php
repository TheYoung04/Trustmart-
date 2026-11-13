<?php
/**
 * config/ai_trust.php
 *
 * Configuration for the TrustMart AI Trust Layer (TM-AI-1.0).
 *
 * Everything is env-driven with SAFE DEFAULTS that require no configuration
 * and no external services: the engine runs in pure PHP+MySQL heuristic mode
 * out of the box. Optional LLM enhancement is OFF unless explicitly enabled
 * with an API key — so it deploys identically on XAMPP and shared hosting.
 *
 * Override in your .env file, e.g.:
 *   AI_TRUST_ENABLED=true
 *   AI_TRUST_LLM_ENABLED=true
 *   AI_TRUST_LLM_API_KEY=sk-...
 *   AI_TRUST_LLM_ENDPOINT=https://api.openai.com/v1/chat/completions
 *   AI_TRUST_LLM_MODEL=gpt-4o-mini
 */

require_once __DIR__ . '/env.php';

if (!defined('AI_TRUST_MODEL_VERSION')) {
    define('AI_TRUST_MODEL_VERSION', 'TM-AI-1.0');
}

// Master switch. When false, the AI layer is skipped entirely and the
// deterministic Bayesian score stands alone.
define('AI_TRUST_ENABLED', env('AI_TRUST_ENABLED', true));

// How many reviews/counterparties are needed before AI penalties are trusted.
// Below this, penalties are dampened toward zero (small-sample safety).
define('AI_TRUST_MIN_EVIDENCE', (int) env('AI_TRUST_MIN_EVIDENCE', 3));

// Hard ceiling on the total AI penalty so AI can never fully erase a good
// baseline on its own (keeps the human/Bayesian score authoritative).
define('AI_TRUST_MAX_PENALTY', (float) env('AI_TRUST_MAX_PENALTY', 50.0));

// Fraud-risk threshold at which new purchases from the seller are HELD for
// admin review (trust_profiles.ai_hold). Set above 100 to disable gating.
define('AI_TRUST_HOLD_RISK', (float) env('AI_TRUST_HOLD_RISK', 50.0));

// ── Capability weights (max penalty each can contribute) ──────
define('AI_W_CIRCULAR',   (float) env('AI_W_CIRCULAR', 30.0));  // collusion / A->B->A loops
define('AI_W_REVIEW',     (float) env('AI_W_REVIEW', 20.0));    // low review credibility
define('AI_W_SCAM_CHAT',  (float) env('AI_W_SCAM_CHAT', 15.0)); // off-platform / scam language
define('AI_W_DUPLICATE',  (float) env('AI_W_DUPLICATE', 10.0)); // duplicated review text across buyers

// ── Optional LLM enhancement (OFF by default) ─────────────────
define('AI_TRUST_LLM_ENABLED', filter_var(env('AI_TRUST_LLM_ENABLED', false), FILTER_VALIDATE_BOOLEAN));
define('AI_TRUST_LLM_ENDPOINT', env('AI_TRUST_LLM_ENDPOINT', 'https://api.openai.com/v1/chat/completions'));
define('AI_TRUST_LLM_API_KEY', env('AI_TRUST_LLM_API_KEY', ''));
define('AI_TRUST_LLM_MODEL', env('AI_TRUST_LLM_MODEL', 'gpt-4o-mini'));
define('AI_TRUST_LLM_TIMEOUT', (int) env('AI_TRUST_LLM_TIMEOUT', 8));
// Rate limit: max LLM calls per worker run (protects cost + shared-host timeouts).
define('AI_TRUST_LLM_MAX_CALLS', (int) env('AI_TRUST_LLM_MAX_CALLS', 200));

// ── Scam-language patterns for chat + review analysis ─────────
// Matched case-insensitively against message/review text. Kept in config so
// maintainers can extend without touching the engine.
define('AI_SCAM_PATTERNS', [
    '/transfer\\s*(bank|duit)\\s* terus/i',
    '/bayar\\s*luar\\s*(platform|trustmart)/i',
    '\\bwestrnx\\b|\\bgopay\\b.*\\bluar\\b/i',
    '/\\bwhats(app|ap)?\\b|\\b01[0-9]{7,9}\\b/i',   // push to WhatsApp / phone numbers
    '/no\\s*receipt|tanpa\\s*resit|jangan\\s*report/i',
    '/scam|penipu|tipu|roger\\s*cepat/i',
]);

// Generic low-effort review tokens (heuristic credibility signal).
define('AI_GENERIC_TOKENS', [
    'bagus','ok','okay','good','nice','best','murah','thank','thanks','terima kasih',
    '5 star','star','mantap','power','sit','gud','pkok','lkca','xol',
]);

// Positive / negative lexicons for Malay + English sentiment mismatch check.
define('AI_POS_WORDS', ['bagus','baik','mudah','cepat','puas','puas hati','berkualiti','worth','recommended','sangat','berbaloi','good','great','amazing','berbaloi','mesra','tipon','jujur','jurus']);
define('AI_NEG_WORDS', ['teruk','slow','late','rasuk','cacat','penipu','kecewa','bad','worst','scam','rosak','tidak berbaloi','menyesal','lewat','tidak puas']);
