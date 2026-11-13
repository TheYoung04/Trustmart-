<?php
/**
 * includes/ai_trust.php
 *
 * Presentation helpers for the AI Trust Layer. Pure rendering — it never
 * computes scores (the engine + DB do that). All output is escaped.
 *
 * Usage:
 *   require_once '.../includes/ai_trust.php';
 *   $ai = ai_trust_fetch($pdo, $userId, 'penjual');
 *   echo ai_trust_badge_html($ai);       // compact line for cards
 *   echo ai_trust_detail_html($ai);      // breakdown for the trust modal
 */

require_once __DIR__ . '/ai_trust_engine.php';

/**
 * Fetch persisted AI state for one (user, role). Returns [] if none yet.
 *
 * Carries the hold decision and its audit trail (who released it, when) so the
 * panel can tell an unreviewed warning apart from one an admin has cleared.
 */
function ai_trust_fetch(PDO $pdo, int $userId, string $role): array
{
    $stmt = $pdo->prepare(
        "SELECT tp.score, tp.ai_trust_score, tp.ai_adjustment, tp.fraud_risk,
                tp.review_credibility, tp.ai_flags, tp.ai_model_version,
                tp.ai_calculated_at, tp.ai_hold, tp.ai_hold_released_at,
                tp.ai_hold_released_by, rel.full_name AS ai_hold_released_name
           FROM trust_profiles tp
      LEFT JOIN users rel ON rel.user_id = tp.ai_hold_released_by
          WHERE tp.user_id=? AND tp.profile_role=?"
    );
    $stmt->execute([$userId, $role]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['ai_trust_score'] === null) {
        return [];
    }

    $sig = $pdo->prepare(
        "SELECT signal_code, signal_value, penalty, reason
           FROM trust_ai_signals WHERE user_id=? AND profile_role=?
          ORDER BY penalty ASC"
    );
    $sig->execute([$userId, $role]);
    $row['signals'] = $sig->fetchAll(PDO::FETCH_ASSOC);
    return $row;
}

/**
 * The hold rule itself, as pure data: risk at or above the threshold AND the
 * hold flag set. Split out of ai_trust_seller_hold() so the checkout gate and
 * every surface that WARNS about a hold ask the identical question instead of
 * each re-typing the threshold comparison. A buyer discovering a held seller
 * only when "Buat Pesanan" answers HTTP 403 is a defect even when the block
 * itself is correct (audit #2).
 *
 * Fails open exactly like the gate does: no AI layer, layer disabled, or an
 * uncomputed profile means no hold, so nothing renders a warning that the
 * server would not enforce.
 */
function ai_trust_hold_state($fraud_risk, $ai_hold): bool
{
    if (!AI_TRUST_ENABLED || AI_TRUST_HOLD_RISK > 100) {
        return false;
    }
    if ($fraud_risk === null || $ai_hold === null || $ai_hold === '') {
        return false;
    }
    return (float) $fraud_risk >= AI_TRUST_HOLD_RISK && (int) $ai_hold === 1;
}

/**
 * Refresh one (user, role) profile from inside a request, best-effort.
 *
 * The AI score is baseline + penalty, so it is only true as long as something
 * recomputes it when the inputs change. Until now the only recomputation was the
 * cron worker (ai_trust_worker.php), which means a submitted review or a settled
 * dispute moved the database baseline while the score on screen stayed at
 * whatever the last scheduled run produced — on a demo machine with no
 * scheduler, forever (audit #3b).
 *
 * Deliberately cannot fail the caller: the review IS saved and the dispute IS
 * resolved before this runs, so a scoring problem is logged and swallowed rather
 * than turning a successful action into a 500. It reuses the caller's connection
 * and opens no transaction of its own, so it is safe after a commit.
 *
 * @return bool TRUE when the profile was recomputed.
 */
function ai_trust_refresh_after_write(PDO $pdo, int $userId, string $role): bool
{
    if (!AI_TRUST_ENABLED || $userId <= 0) {
        return false;
    }
    try {
        // Whole budget: this is one profile, not a full sweep.
        $budget = AI_TRUST_LLM_MAX_CALLS;
        $res = ai_refresh_user($pdo, $userId, $role, $budget);
        if (isset($res['skipped'])) {
            // No trust_profiles row for this (user, role): nothing to score.
            error_log('[ai_trust_refresh] skipped user=' . $userId . ' role=' . $role
                    . ': ' . $res['skipped']);
            return false;
        }
        return true;
    } catch (Throwable $e) {
        error_log('[ai_trust_refresh] user=' . $userId . ' role=' . $role . ': ' . $e->getMessage());
        return false;
    }
}

/**
 * Hold state from a row fetched by ai_trust_fetch() (or any row carrying
 * fraud_risk + ai_hold, e.g. a catalogue SELECT).
 */
function ai_trust_held(array $ai): bool
{
    return ai_trust_hold_state(
        $ai['fraud_risk'] ?? null,
        $ai['ai_hold'] ?? null
    );
}

/**
 * The buyer-facing explanation of a hold, shown where the order controls would
 * otherwise be. Wording matches the checkout gate's 403 message so the page and
 * the API never tell two different stories.
 */
function ai_trust_hold_notice_html(array $ai): string
{
    if (!ai_trust_held($ai)) return '';
    $risk = (int) round((float) $ai['fraud_risk']);
    return '<div style="font-size:13px;color:var(--red);padding:12px;background:var(--red-bg);'
         . 'border-radius:var(--r)" role="alert">'
         . '<strong>Penjual ini sedang dalam siasatan PTU (Lapisan AI).</strong>'
         . '<div style="font-size:12px;color:var(--ink2);margin-top:6px">'
         . 'Risiko penipuan AI tinggi (' . $risk . '%). Pesanan baharu dihalang sementara '
         . '— pilih penjual lain atau cuba kemudian.'
         . '</div></div>';
}

/**
 * Order gate: TRUE when this (user, role) profile must be held for admin
 * review — i.e. the AI layer rates it high-risk and no admin has released
 * the hold yet. Fails open: if the AI layer is off or uncomputed, trading
 * is never blocked.
 */
function ai_trust_seller_hold(PDO $pdo, int $userId, string $role = 'penjual'): bool
{
    if (!AI_TRUST_ENABLED || AI_TRUST_HOLD_RISK > 100) {
        return false;
    }
    $stmt = $pdo->prepare(
        'SELECT fraud_risk, ai_hold FROM trust_profiles
          WHERE user_id = ? AND profile_role = ?'
    );
    $stmt->execute([$userId, $role]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return false;
    }
    return ai_trust_hold_state($row['fraud_risk'], $row['ai_hold']);
}

/**
 * Divergence test: does the AI layer add anything beyond the baseline?
 * The AI score is baseline + penalty, so a clean seller's AI score simply
 * duplicates the trust score. The badge is only worth showing when the AI
 * actually adjusted the score or flagged meaningful fraud risk.
 */
function ai_trust_diverged(array $ai, int $risk_threshold = 25): bool
{
    if (empty($ai) || ($ai['ai_trust_score'] ?? null) === null) return false;
    return (float)($ai['ai_adjustment'] ?? 0) < 0
        || (float)($ai['fraud_risk'] ?? 0) >= $risk_threshold;
}

/**
 * A one-line badge for product cards / tables. Renders nothing when the AI
 * layer has not run yet OR agrees with the baseline (no penalty, low risk),
 * so the card never shows the same number twice. When the AI did adjust the
 * score, the badge shows the penalty delta — the divergence IS the message.
 */
function ai_trust_badge_html(array $ai): string
{
    if (!AI_TRUST_ENABLED || !ai_trust_diverged($ai)) return '';
    $risk = (int) $ai['fraud_risk'];
    $adj  = (float) ($ai['ai_adjustment'] ?? 0);
    $color = $risk >= 50 ? 'var(--red)' : 'var(--gold)';
    $label = $adj < 0 ? '&minus;' . number_format(abs($adj), 0) : '&#9888;';
    $title = $adj < 0
        ? 'Lapisan AI mengesan isyarat risiko — skor dilaras ' . number_format($adj, 0) . ' daripada skor asas.'
        : 'Lapisan AI mengesan risiko penipuan yang perlu dipantau.';
    return '<span title="' . $title . '" '
        . 'style="font-size:11px;color:' . $color . ';font-weight:600">🛡 AI ' . $label . '</span>';
}

/**
 * Audit trail appended to a released hold: "pada 13 Okt 2026, 09:40 oleh
 * Norul Aishah". Both halves are optional — a release recorded before the
 * audit columns existed has neither, and an admin reset leaves them NULL.
 * The name is escaped here because the caller injects raw markup.
 */
function ai_trust_release_text(array $ai): string
{
    $parts = [];
    $when = $ai['ai_hold_released_at'] ?? null;
    if ($when !== null && $when !== '' && ($ts = strtotime((string) $when)) !== false) {
        $parts[] = 'pada ' . date('d M Y, H:i', $ts);
    }
    $who = trim((string) ($ai['ai_hold_released_name'] ?? ''));
    if ($who !== '') {
        $parts[] = 'oleh ' . htmlspecialchars($who, ENT_QUOTES, 'UTF-8');
    }
    return $parts ? ' ' . implode(' ', $parts) : '';
}

/**
 * Plain-language label + explanation for one AI signal.
 * The engine stores technical English reasons; the UI translates them into
 * sentences a normal shopper can read, keeping the numbers as evidence.
 * Returns [label, explanation]; falls back to the raw code/reason.
 */
function ai_trust_signal_text(string $code, string $value, string $reason): array {
    if ($code === 'circular_trading' && preg_match('/(\d+)\D+(\d+)/', $reason, $m)) {
        return ['Jual-beli dengan orang yang sama',
            'Penjual ini membuat urus niaga pusingan: jual kepada seseorang, lalu beli semula daripada orang yang sama (' . (int)$m[1] . ' orang daripada ' . (int)$m[2] . ' rakan niaga). Urus niaga macam ini biasanya palsu — tujuannya untuk sama-sama naikkan skor kepercayaan.'];
    }
    if ($code === 'scam_language' && preg_match('/(\d+)/', $reason, $m)) {
        return ['Minta bayaran luar TrustMart',
            (int)$m[1] . ' mesej chat penjual ini meminta pembeli bayar terus kepadanya (contoh: transfer bank). Bayaran di luar TrustMart tidak dilindungi — wang mungkin hilang, barang tidak sampai.'];
    }
    if ($code === 'low_review_credibility' && preg_match('/([\d.]+)\/100 across (\d+)/', $reason, $m)) {
        return ['Ulasan nampak palsu',
            (int)$m[2] . ' ulasan untuk penjual ini terlalu pendek atau tidak sepadan dengan bintang yang diberi — berkemungkinan bukan ditulis oleh pembeli sebenar.'];
    }
    if ($code === 'duplicate_reviews' && preg_match('/(\d+)/', $reason, $m)) {
        return ['Ulasan salin tampal',
            (int)$m[1] . ' kumpulan ulasan menulis teks yang sama sekali. Pembeli sebenar tidak akan menulis perkara yang sama — ini petanda ulasan palsu.'];
    }
    return [$code, $reason];
}

/**
 * Detailed, explainable breakdown for the trust-score modal.
 */
function ai_trust_detail_html(array $ai): string
{
    if (empty($ai)) {
        return '<div style="font-size:12px;color:var(--ink3);margin-top:10px">'
            . 'Lapisan AI belum dikira untuk profil ini.</div>';
    }
    $ver = htmlspecialchars((string)($ai['ai_model_version'] ?? ''), ENT_QUOTES, 'UTF-8');
    $html = '<div style="margin-top:16px;padding:14px;background:var(--cream);border-radius:var(--r)">'
        . '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">'
        . '<strong style="font-size:13px">🛡 AI Trust Layer</strong>'
        . '<span style="font-size:11px;color:var(--ink3)">' . $ver . '</span></div>';

    $risk = (int) $ai['fraud_risk'];
    $riskLabel = $risk >= 50 ? 'TINGGI' : ($risk >= 25 ? 'SEDANG' : 'RENDAH');
    $riskColor = $risk >= 50 ? 'var(--red)' : ($risk >= 25 ? 'var(--gold, #b8860b)' : 'var(--green)');

    // The consequence line has to follow the admin's decision, not the AI
    // number alone. Once PTU releases the hold the buyer CAN order again, so
    // still claiming "dihalang sementara" contradicts a checkout that succeeds
    // one click later. ai_hold defaults to 1, so an unreviewed profile keeps
    // reading as blocked. The risk verdict itself stays red — releasing a hold
    // overrides the block, it does not rewrite what the AI detected.
    $held = (int) ($ai['ai_hold'] ?? 1) === 1;
    if ($risk >= 50) {
        $forYou = $held
            ? 'Pembelian baharu daripada penjual ini <b>dihalang sementara</b> — admin PTU perlu menyemak dahulu.'
            : 'Pembelian <b>dibenarkan</b> — admin PTU telah menyemak dan <b>melepaskan penahan</b>'
                . ai_trust_release_text($ai)
                . '. Amaran risiko AI di atas masih kekal dalam rekod.';
    } elseif ($risk >= 25) {
        $forYou = 'Anda <b>masih boleh membeli</b>, tetapi berwaspada — jangan bayar di luar TrustMart.';
    } else {
        $forYou = 'Tiada aktiviti mencurigakan dikesan setakat ini.';
    }

    // Show the arithmetic in full so the number is never a black box:
    // baseline − AI deduction = final score.
    $base = isset($ai['score']) ? (float) $ai['score'] : null;
    $scoreLine = $base !== null
        ? 'Skor: ' . number_format($base, 1) . ' (asas) − ' . number_format(abs((float)$ai['ai_adjustment']), 1)
            . ' (potongan AI) = <b>' . number_format((float)$ai['ai_trust_score'], 1) . '/100</b>'
        : 'Skor AI: <b>' . number_format((float)$ai['ai_trust_score'], 1) . '/100</b>';

    $html .= '<div style="font-size:12px;color:var(--ink2);margin-bottom:4px">'
        . 'Risiko penipuan: <b style="color:' . $riskColor . '">' . $riskLabel . ' — ' . $risk . '%</b> · ' . $scoreLine . '</div>'
        . '<div style="font-size:12px;color:var(--ink2);margin-bottom:8px">'
        . '<b>Maksudnya untuk anda:</b> ' . $forYou . '</div>'
        . '<div style="font-size:11px;color:var(--ink3);margin-bottom:8px">'
        . 'Amaran AI ini <b>tidak mengubah skor asas</b> penjual — keputusan sebenar di tangan admin PTU.</div>';

    if (!empty($ai['signals'])) {
        $html .= '<ul style="margin:6px 0 0;padding-left:18px;font-size:12px;color:var(--ink2)">';
        foreach ($ai['signals'] as $s) {
            [$label, $plain] = ai_trust_signal_text(
                (string)$s['signal_code'], (string)$s['signal_value'], (string)$s['reason']);
            $pen = number_format(abs((float)$s['penalty']), 1);
            $html .= '<li style="margin-bottom:4px"><b>' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
                . '</b> <span style="color:var(--red)">(-' . $pen . ')</span><br>'
                . '<span style="color:var(--ink3)">' . htmlspecialchars($plain, ENT_QUOTES, 'UTF-8') . '</span></li>';
        }
        $html .= '</ul>';
    } else {
        $html .= '<div style="font-size:12px;color:var(--green)">Tiada isyarat risiko dikenal pasti. ✓</div>';
    }
    $html .= '</div>';
    return $html;
}
