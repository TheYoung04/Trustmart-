<?php
/**
 * includes/ai_trust_engine.php
 *
 * TrustMart AI Trust Layer engine (TM-AI-1.0).
 *
 * Explainable, auditable AI signals that sit BESIDE the deterministic
 * Bayesian score. The baseline trust_profiles.score is never altered; we
 * only compute a bounded, dampened `ai_adjustment` + `fraud_risk` and store
 * every reason as a trust_ai_signals row.
 *
 * Runs entirely on PHP + MySQL. LLM enhancement is optional and silently
 * falls back to heuristics, so it works on XAMPP and shared hosting alike.
 *
 * Capabilities:
 *   ai_detect_circular_trading()  collusion / A->B->A trading loops
 *   ai_score_review()             per-review credibility (heuristic or LLM)
 *   ai_chat_scam_signals()        off-platform / scam language in chat
 *   ai_refresh_user()             aggregate + persist a role profile's AI score
 */

require_once __DIR__ . '/../config/ai_trust.php';

/** Small-sample dampener: penalties scale toward full only as evidence grows. */
function ai_dampen(int $evidence): float
{
    $need = max(1, AI_TRUST_MIN_EVIDENCE);
    if ($evidence <= 0) return 0.0;
    return min(1.0, $evidence / ($evidence + $need));
}

/** Normalize text for duplicate detection. */
function ai_normalize_text(string $t): string
{
    $t = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $t) ?? ''));
    return $t;
}

/**
 * Heuristic review credibility. Returns [int 0-100, string reasons].
 * No external calls — the default, always-available scorer.
 */
function ai_heuristic_review_credibility(string $comment, int $rating): array
{
    $reasons = [];
    $cred = 70;
    $norm = ai_normalize_text($comment);

    if ($norm === '') {
        return [60, 'empty review, no textual evidence'];
    }

    $words = preg_split('/[\s,!.]+/u', $norm, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $wc = count($words);

    if ($wc <= 2)      { $cred -= 25; $reasons[] = 'very low effort'; }
    elseif ($wc >= 15) { $cred += 20; $reasons[] = 'detailed'; }
    elseif ($wc >= 6)  { $cred += 10; $reasons[] = 'some detail'; }

    // Generic-only content
    $meaningful = array_diff($words, AI_GENERIC_TOKENS);
    if ($wc > 0 && $wc <= 4 && count($meaningful) === 0) {
        $cred -= 20; $reasons[] = 'generic wording only';
    }

    // Sentiment vs star mismatch
    $pos = 0; $neg = 0;
    foreach (AI_POS_WORDS as $w) { if (strpos($norm, $w) !== false) $pos++; }
    foreach (AI_NEG_WORDS as $w) { if (strpos($norm, $w) !== false) $neg++; }
    if ($rating >= 4 && $neg > $pos) { $cred -= 25; $reasons[] = 'negative text but high stars'; }
    elseif ($rating <= 2 && $pos > $neg) { $cred -= 15; $reasons[] = 'positive text but low stars'; }
    elseif ($rating >= 4 && $pos > 0 && $wc >= 4) { $cred += 8; $reasons[] = 'substantiated praise'; }

    // Spam formatting
    if (substr_count($comment, '!!!') >= 1 || substr_count($comment, '?') >= 4) {
        $cred -= 10; $reasons[] = 'excessive punctuation';
    }

    // Scam language inside a review
    foreach (AI_SCAM_PATTERNS as $p) {
        if (@preg_match($p, $comment) === 1) { $cred -= 20; $reasons[] = 'suspicious language'; break; }
    }

    $cred = (int) max(0, min(100, $cred));
    return [$cred, $reasons ? implode('; ', $reasons) : 'looks genuine'];
}

/**
 * Optional LLM credibility via any OpenAI-compatible endpoint.
 * Returns [int 0-100, string reason] or null on failure/disabled so the
 * caller falls back to heuristics. Never fatal, never blocks offline hosts.
 */
function ai_llm_review_credibility(string $comment, int $rating, ?int &$budget): ?array
{
    if (!AI_TRUST_LLM_ENABLED || AI_TRUST_LLM_API_KEY === '' || $budget !== null && $budget <= 0) {
        return null;
    }
    if ($budget !== null) { $budget--; }

    $payload = [
        'model' => AI_TRUST_LLM_MODEL,
        'messages' => [[
            'role' => 'user',
            'content' => "Score the credibility (0-100) of a marketplace review as genuine vs fake/spam. "
                . "Return strict JSON: {\"credibility\":<int>,\"reason\":\"<short>\"}. "
                . "Stars: {$rating}. Text: " . json_encode($comment, JSON_UNESCAPED_UNICODE),
        ]],
        'temperature' => 0.2,
        'max_tokens' => 80,
    ];

    $ch = curl_init(AI_TRUST_LLM_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_TIMEOUT => AI_TRUST_LLM_TIMEOUT,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . AI_TRUST_LLM_API_KEY,
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $resp = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);

    if ($resp === false) { error_log('[ai_llm] transport: ' . $err); return null; }
    $data = json_decode($resp, true);
    $content = $data['choices'][0]['message']['content'] ?? null;
    if (!$content) return null;
    if (preg_match('/\{.*\}/s', $content, $m)) {
        $j = json_decode($m[0], true);
        if (is_array($j) && isset($j['credibility'])) {
            return [(int) max(0, min(100, (int) $j['credibility'])), (string) ($j['reason'] ?? 'llm')];
        }
    }
    return null;
}

/**
 * Score + persist one review's credibility. Returns [credibility, method, reasons].
 */
function ai_score_review(PDO $pdo, int $reviewId, string $comment, int $rating, ?int &$llmBudget): array
{
    [$hCred, $hReason] = ai_heuristic_review_credibility($comment, $rating);
    $method = 'heuristic';
    $cred = $hCred;
    $reason = $hReason;

    $llm = ai_llm_review_credibility($comment, $rating, $llmBudget);
    if ($llm !== null) {
        // Blend: LLM nudges the heuristic but never fully overrides it.
        $cred = (int) round(0.5 * $hCred + 0.5 * $llm[0]);
        $method = 'llm';
        $reason = $hReason . ' | llm: ' . $llm[1];
    }

    $pdo->prepare(
        "INSERT INTO review_ai_credibility (review_id, credibility, method, reasons, model_version)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE credibility=VALUES(credibility), method=VALUES(method),
                reasons=VALUES(reasons), model_version=VALUES(model_version)"
    )->execute([$reviewId, $cred, $method, mb_substr($reason, 0, 255), AI_TRUST_MODEL_VERSION]);

    return [$cred, $method, $reason];
}

/**
 * Capability #1: circular-trading / collusion detection.
 * A mutual partner is someone who BOTH bought from and sold to this user.
 * Returns [risk 0-100, reason string, mutualCount, partnerCount].
 */
function ai_detect_circular_trading(PDO $pdo, int $userId): array
{
    $mutual = $pdo->prepare(
        "SELECT COUNT(*) FROM (
            SELECT a.partner FROM
              (SELECT buyer_id AS partner FROM transactions WHERE seller_id = :u1 GROUP BY buyer_id) a
            JOIN
              (SELECT seller_id AS partner FROM transactions WHERE buyer_id = :u2 GROUP BY seller_id) b
              ON a.partner = b.partner
        ) m"
    );
    $mutual->execute([':u1' => $userId, ':u2' => $userId]);
    $mutualCount = (int) $mutual->fetchColumn();

    $partners = $pdo->prepare(
        "SELECT COUNT(DISTINCT cp) FROM (
            SELECT buyer_id AS cp FROM transactions WHERE seller_id = :u AND buyer_id <> :u2
            UNION
            SELECT seller_id AS cp FROM transactions WHERE buyer_id = :u3 AND seller_id <> :u4
        ) x"
    );
    $partners->execute([':u'=>$userId, ':u2'=>$userId, ':u3'=>$userId, ':u4'=>$userId]);
    $partnerCount = (int) $partners->fetchColumn();

    $risk = 0.0;
    $reason = '';
    if ($partnerCount > 0 && $mutualCount > 0) {
        $ratio = $mutualCount / $partnerCount;          // how much of the network is bidirectional
        $risk = min(100.0, $ratio * 100.0);
        $reason = "{$mutualCount} reciprocal trading partner(s) out of {$partnerCount}";
    }
    return [(int) round($risk), $reason, $mutualCount, $partnerCount];
}

/**
 * Capability #3: scam / off-platform language in this user's chat messages.
 * Returns [risk 0-100, hitCount, reason].
 */
function ai_chat_scam_signals(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        "SELECT message_text FROM messages WHERE sender_id = ? ORDER BY created_at DESC LIMIT 500"
    );
    $stmt->execute([$userId]);
    $hits = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $text) {
        foreach (AI_SCAM_PATTERNS as $p) {
            if (@preg_match($p, (string) $text) === 1) { $hits++; break; }
        }
    }
    $risk = (int) min(100.0, $hits * 25.0);
    $reason = $hits > 0 ? "{$hits} message(s) with off-platform/scam language" : '';
    return [$risk, $hits, $reason];
}

/**
 * Master routine: compute + persist the AI layer for one (user, role).
 * Safe to call repeatedly. Never touches the baseline Bayesian score.
 * Returns a summary array, or ['skipped'=>reason] if not applicable.
 */
function ai_refresh_user(PDO $pdo, int $userId, string $role, ?int &$llmBudget): array
{
    $prof = $pdo->prepare("SELECT score FROM trust_profiles WHERE user_id=? AND profile_role=?");
    $prof->execute([$userId, $role]);
    $baseline = $prof->fetchColumn();
    if ($baseline === false) {
        return ['user_id'=>$userId, 'role'=>$role, 'skipped'=>'no baseline profile'];
    }
    $baseline = (float) $baseline;

    $signals = [];   // rows to persist
    $flags = [];
    $totalPenalty = 0.0;

    // ── Circular trading (both roles) ──
    [$cRisk, $cReason, $mutual, $partners] = ai_detect_circular_trading($pdo, $userId);
    if ($cRisk > 0) {
        $pen = AI_W_CIRCULAR * ($cRisk / 100.0) * ai_dampen($mutual + 1);
        $totalPenalty += $pen;
        $signals[] = ['circular_trading', $cRisk, -$pen, $cReason];
        $flags[] = 'circular_trading';
    }

    // ── Review credibility (seller only) ──
    $meanCred = null;
    if ($role === 'penjual') {
        $revs = $pdo->prepare(
            "SELECT review_id, COALESCE(comment,'') AS comment, rating FROM reviews WHERE seller_id=?"
        );
        $revs->execute([$userId]);
        $rows = $revs->fetchAll(PDO::FETCH_ASSOC);

        $count = count($rows);
        $sumCred = 0; $dupGroups = 0; $textFreq = [];
        foreach ($rows as $r) {
            [$cred, $method, $reason] = ai_score_review($pdo, (int)$r['review_id'], (string)$r['comment'], (int)$r['rating'], $llmBudget);
            $sumCred += $cred;
            $key = ai_normalize_text((string) $r['comment']);
            if ($key !== '') { $textFreq[$key] = ($textFreq[$key] ?? 0) + 1; }
        }
        if ($count > 0) {
            $meanCred = $sumCred / $count;
            if ($meanCred < 70) {
                $pen = AI_W_REVIEW * ((70.0 - $meanCred) / 70.0) * ai_dampen($count);
                $totalPenalty += $pen;
                $signals[] = ['review_credibility', $meanCred, -$pen, "mean credibility {$meanCred}/100 across {$count} review(s)"];
                $flags[] = 'low_review_credibility';
            }
            // Duplicated review text across buyers
            foreach ($textFreq as $txt => $c) {
                if ($c >= 3) { $dupGroups++; }
            }
            if ($dupGroups > 0) {
                $dupRatio = min(1.0, $dupGroups / max(1, $count));
                $pen = AI_W_DUPLICATE * $dupRatio * ai_dampen($count);
                $totalPenalty += $pen;
                $signals[] = ['duplicate_reviews', $dupGroups, -$pen, "{$dupGroups} duplicated review phrase(s)"];
                $flags[] = 'duplicate_reviews';
            }
        }
    }

    // ── Chat scam language (both roles) ──
    [$sRisk, $sHits, $sReason] = ai_chat_scam_signals($pdo, $userId);
    if ($sRisk > 0) {
        $pen = AI_W_SCAM_CHAT * ($sRisk / 100.0) * ai_dampen($sHits);
        $totalPenalty += $pen;
        $signals[] = ['scam_language', $sRisk, -$pen, $sReason];
        $flags[] = 'scam_language';
    }

    // ── Clamp total penalty and compute composite fraud risk ──
    $totalPenalty = min(AI_TRUST_MAX_PENALTY, $totalPenalty);
    $aiAdjustment = -$totalPenalty;
    $fraudRisk = (int) round(min(100.0, $cRisk * 0.5 + $sRisk * 0.3 + ($dupGroups ?? 0) * 20));
    if ($meanCred !== null && $meanCred < 40) { $fraudRisk = (int) min(100.0, $fraudRisk + 20); }

    $finalScore = max(0.0, min(100.0, $baseline + $aiAdjustment));

    // ── Persist ──
    $pdo->prepare("DELETE FROM trust_ai_signals WHERE user_id=? AND profile_role=?")
        ->execute([$userId, $role]);
    if ($signals) {
        $ins = $pdo->prepare(
            "INSERT INTO trust_ai_signals (user_id, profile_role, signal_code, signal_value, penalty, reason, model_version)
             VALUES (?,?,?,?,?,?,?)"
        );
        foreach ($signals as $s) {
            $ins->execute([$userId, $role, $s[0], round($s[1],2), round($s[2],2), mb_substr($s[3],0,255), AI_TRUST_MODEL_VERSION]);
        }
    }

    $pdo->prepare(
        "UPDATE trust_profiles
            SET fraud_risk=?, ai_adjustment=?, ai_trust_score=?, review_credibility=?,
                ai_flags=?, ai_model_version=?, ai_calculated_at=CURRENT_TIMESTAMP
          WHERE user_id=? AND profile_role=?"
    )->execute([
        $fraudRisk, round($aiAdjustment,2), round($finalScore,2),
        $meanCred === null ? null : round($meanCred,2),
        $flags ? implode(',', $flags) : null,
        AI_TRUST_MODEL_VERSION, $userId, $role,
    ]);

    return [
        'user_id' => $userId, 'role' => $role,
        'baseline' => round($baseline,2), 'adjustment' => round($aiAdjustment,2),
        'final' => round($finalScore,2), 'fraud_risk' => $fraudRisk,
        'flags' => $flags,
    ];
}
