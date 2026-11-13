<?php
// ============================================================
// TrustMart Smart Trust Score v2 presentation helpers.
// The database procedures are the only scoring authority; PHP never
// reconstructs component mathematics.
// ============================================================

declare(strict_types=1);

const TRUST_FORMULA_VERSION = 'TM-TRUST-2.0';
const TRUST_PROFILE_ROLES = ['pembeli', 'penjual'];

/**
 * Return a stable empty profile for users with no calculated evidence yet.
 */
function trust_empty_profile(string $role): array
{
    return [
        'user_id' => 0,
        'profile_role' => $role,
        'score' => 0.0,
        'raw_score' => 0.0,
        'confidence' => 0.0,
        'confidence_label' => 'Tiada',
        'trust_level' => 'Baharu',
        'evidence_units' => 0.0,
        'distinct_counterparties' => 0,
        'completed_transactions' => 0,
        'formula_version' => TRUST_FORMULA_VERSION,
        'calculated_at' => null,
        'components' => [],
    ];
}

/**
 * Fetch the authoritative role-specific profile and its persisted components.
 */
function trust_profile(PDO $pdo, int $userId, string $role): array
{
    if ($userId < 1 || !in_array($role, TRUST_PROFILE_ROLES, true)) {
        return trust_empty_profile($role);
    }

    $stmt = $pdo->prepare(
        'SELECT user_id, profile_role, score, raw_score, confidence,
                confidence_label, trust_level, evidence_units,
                distinct_counterparties, completed_transactions,
                formula_version, calculated_at
           FROM trust_profiles
          WHERE user_id = ? AND profile_role = ?'
    );
    $stmt->execute([$userId, $role]);
    $row = $stmt->fetch();

    if (!$row) {
        $empty = trust_empty_profile($role);
        $empty['user_id'] = $userId;
        return $empty;
    }

    $row['user_id'] = (int) $row['user_id'];
    $row['score'] = (float) $row['score'];
    $row['raw_score'] = (float) $row['raw_score'];
    $row['confidence'] = (float) $row['confidence'];
    $row['evidence_units'] = (float) $row['evidence_units'];
    $row['distinct_counterparties'] = (int) $row['distinct_counterparties'];
    $row['completed_transactions'] = (int) $row['completed_transactions'];

    $componentStmt = $pdo->prepare(
        'SELECT component_code, component_label, component_order,
                max_points, metric_value, points, evidence_count, details
           FROM trust_score_components
          WHERE user_id = ? AND profile_role = ?
          ORDER BY component_order'
    );
    $componentStmt->execute([$userId, $role]);
    $row['components'] = array_map(
        static function (array $component): array {
            $component['component_order'] = (int) $component['component_order'];
            $component['max_points'] = (float) $component['max_points'];
            $component['metric_value'] = (float) $component['metric_value'];
            $component['points'] = (float) $component['points'];
            $component['evidence_count'] = (int) $component['evidence_count'];
            return $component;
        },
        $componentStmt->fetchAll()
    );

    return $row;
}

/**
 * Build a compact profile from aliases returned by joined catalogue queries.
 */
function trust_profile_from_row(array $row, string $prefix = 'trust_'): array
{
    $profile = trust_empty_profile((string)($row[$prefix . 'role'] ?? 'penjual'));
    $profile['score'] = (float)($row[$prefix . 'score'] ?? 0);
    $profile['raw_score'] = (float)($row[$prefix . 'raw_score'] ?? 0);
    $profile['confidence'] = (float)($row[$prefix . 'confidence'] ?? 0);
    $profile['confidence_label'] = (string)($row[$prefix . 'confidence_label'] ?? 'Tiada');
    $profile['trust_level'] = (string)($row[$prefix . 'level'] ?? 'Baharu');
    $profile['evidence_units'] = (float)($row[$prefix . 'evidence'] ?? 0);
    $profile['distinct_counterparties'] = (int)($row[$prefix . 'counterparties'] ?? 0);
    $profile['completed_transactions'] = (int)($row[$prefix . 'completed'] ?? 0);
    return $profile;
}

function trust_has_evidence(array $profile): bool
{
    return (float)($profile['evidence_units'] ?? 0) > 0;
}

function trust_score_display(array $profile, int $decimals = 0): string
{
    if (!trust_has_evidence($profile)) {
        return 'Baharu';
    }
    return number_format((float)$profile['score'], $decimals) . '/100';
}

function trust_score_color(array $profile): string
{
    if (!trust_has_evidence($profile)) {
        return 'var(--ink4)';
    }

    $score = (float)($profile['score'] ?? 0);
    return match (true) {
        $score >= 85 => 'var(--green)',
        $score >= 70 => 'var(--gold)',
        $score >= 50 => 'var(--blue)',
        default => 'var(--red)',
    };
}

/**
 * Unified one-line trust label for catalogue cards and chat headers.
 *
 * Reads the role-specific Bayesian profile fields carried by a joined seller
 * row (trust_evidence = tp.evidence_units, trust_baseline = tp.score,
 * ai_trust_score = tp.ai_trust_score) so EVERY surface agrees: a seller with
 * no real evidence shows "Baharu", never a misleading legacy users.trust_score
 * 0/100. The returned label carries no "/100" suffix so each surface can add
 * its own wording; has_score tells it whether a suffix is appropriate.
 *
 * @return array{label:string,color:string,has_score:bool}
 */
function trust_display_from_row(array $row): array
{
    $evidence = (float) ($row['trust_evidence'] ?? 0);
    if ($evidence <= 0) {
        return ['label' => 'Baharu', 'color' => 'var(--ink4)', 'has_score' => false];
    }

    $ai = $row['ai_trust_score'] ?? null;
    $score = $ai !== null ? (float) $ai : (float) ($row['trust_baseline'] ?? 0);
    $color = match (true) {
        $score >= 85 => 'var(--green)',
        $score >= 70 => 'var(--gold)',
        $score >= 50 => 'var(--blue)',
        default      => 'var(--red)',
    };
    return ['label' => number_format($score, 0), 'color' => $color, 'has_score' => true];
}

function trust_confidence_class(array $profile): string
{
    return match ((string)($profile['confidence_label'] ?? 'Tiada')) {
        'Tinggi' => 'badge-green',
        'Sederhana' => 'badge-blue',
        'Rendah' => 'badge-gold',
        default => 'badge-gray',
    };
}

function trust_summary_text(array $profile): string
{
    if (!trust_has_evidence($profile)) {
        return 'Profil baharu — belum cukup sejarah transaksi';
    }

    return sprintf(
        '%s · Keyakinan %s (%d rakan niaga unik)',
        (string)$profile['trust_level'],
        (string)$profile['confidence_label'],
        (int)$profile['distinct_counterparties']
    );
}

/**
 * Plain-language reading of the two numbers a profile carries.
 *
 * The modal shows a headline score AND a quality score, which reads as a
 * contradiction unless someone says out loud that one is "how good your record
 * looks" and the other is that figure discounted for "how much proof exists".
 * These are the live numbers in sentences, replacing the formula line
 * (skor asas × keyakinan) that nobody could parse.
 *
 * @param string $counterparty who this user deals with: 'penjual' on a buyer
 *                             profile, 'pembeli' on a seller profile.
 */
function trust_explained_html(array $profile, string $counterparty = 'penjual'): string
{
    $raw    = (float) ($profile['raw_score'] ?? 0);
    $score  = (float) ($profile['score'] ?? 0);
    $conf   = (float) ($profile['confidence'] ?? 0);
    $partners = (int) ($profile['distinct_counterparties'] ?? 0);
    $cp = $counterparty === 'pembeli' ? 'pembeli' : 'penjual';

    if (!trust_has_evidence($profile)) {
        return '<p style="margin-bottom:14px">'
            . '<b>Skor anda belum dikira.</b> Sistem tidak memberi angka'
            . ' berdasarkan andaian — ia menunggu urus niaga pertama anda'
            . ' selesai. Label <b>Baharu</b> itu normal, bukan penalti.</p>';
    }

    $quality = '<b>Kualiti rekod anda: ' . number_format($raw, 1) . '/100.</b>'
        . ' Angka ini dinilai daripada pesanan yang betul-betul selesai, ulasan'
        . ' yang diberi, dan tiada rekod aduan terhadap anda.';

    if ($conf >= 99.5) {
        $proof = 'Anda sudah berniaga dengan <b>' . $partners . ' ' . $cp . '</b> berbeza,'
            . ' jadi bukti cukup untuk memaparkan skor penuh: <b>'
            . number_format($score, 1) . '/100</b>.';
    } else {
        $proof = 'Tetapi setakat ini anda baru berniaga dengan <b>' . $partners . ' ' . $cp . '</b>'
            . ' berbeza. Bukti daripada seorang dua belum boleh dipercayai sepenuhnya,'
            . ' jadi skor anda dipaparkan sebagai <b>' . number_format($score, 1) . '/100</b>'
            . ' (keyakinan ' . number_format($conf, 0) . '%).';
    }

    $howto = '<span style="color:var(--ink3)">Cara menaikkannya: selesaikan urus niaga'
        . ' dengan lebih ramai ' . $cp . ' yang <b>berbeza</b> — bukan lebih banyak'
        . ' pesanan daripada orang yang sama (setiap satu hanya mengira 3 urus niaga).</span>';

    return '<p style="margin-bottom:6px">' . $quality . '</p>'
        . '<p style="margin-bottom:6px">' . $proof . '</p>'
        . '<p style="margin-bottom:14px;font-size:12px">' . $howto . '</p>';
}

/**
 * Render the stored component rows. All values are output-escaped and no
 * arithmetic besides progress-bar display is performed here.
 */
function trust_components_html(array $profile): string
{
    // A seller with no evidence only holds Bayesian PRIOR points, so the
    // component bars (e.g. "26.3/35") contradict the "Baharu" headline.
    // Show an explanatory notice until real evidence exists.
    if (!trust_has_evidence($profile)) {
        return '<div style="padding:14px;background:var(--cream);border:1px solid var(--cream3);'
            . 'border-radius:var(--r);font-size:12px;color:var(--ink3);line-height:1.6">'
            . 'Pecahan skor akan diaktifkan selepas transaksi pertama anda selesai.'
            . '<br><span style="font-size:11px;color:var(--ink4)">Penilaian bermula daripada '
            . 'nilai prior Bayesian dan hanya dikira setelah terdapat bukti sebenar '
            . '(transaksi, ulasan atau aduan).</span></div>';
    }

    if (empty($profile['components'])) {
        return '<div class="empty-state" style="padding:18px">Belum ada pecahan skor.</div>';
    }

    $html = '';
    foreach ($profile['components'] as $component) {
        $label = htmlspecialchars((string)$component['component_label'], ENT_QUOTES, 'UTF-8');
        $details = htmlspecialchars((string)$component['details'], ENT_QUOTES, 'UTF-8');
        $metric = max(0.0, min(100.0, (float)$component['metric_value']));
        $points = number_format((float)$component['points'], 1);
        $maxPoints = number_format((float)$component['max_points'], 0);
        $html .= '<div style="margin-bottom:14px">'
            . '<div style="display:flex;justify-content:space-between;gap:12px;font-size:12px;font-weight:600;margin-bottom:5px">'
            . '<span>' . $label . '</span><span>' . $points . '/' . $maxPoints . '</span></div>'
            . '<div class="trust-track" style="height:7px;background:var(--cream3);border-radius:10px;overflow:hidden">'
            . '<div class="trust-prog" style="width:' . number_format($metric, 2, '.', '') . '%;height:100%;background:var(--gold);border-radius:10px"></div></div>'
            . '<div style="display:flex;justify-content:space-between;gap:12px;margin-top:5px;font-size:11px;color:var(--ink3)">'
            . '<span>' . $details . '</span><span>' . number_format($metric, 0) . '%</span></div></div>';
    }
    return $html;
}
