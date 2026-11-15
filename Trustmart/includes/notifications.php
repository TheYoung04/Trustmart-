<?php
// ============================================================
// includes/notifications.php
//
// "Needs your action" counters for the buyer and seller consoles —
// the same shape the admin console has always used (a count computed
// at page load, shown as a chip on the sidebar tab and a clickable
// pill in the topbar).
//
// Why this is a shared helper instead of inline SQL in each page:
// the number appears in three places for a seller (sidebar chip,
// topbar pill, and the AJAX stats payload the dashboard re-reads
// after every order action). Two of those are PHP and one is JSON,
// which is exactly the "two renderers, one patched" pattern that has
// produced real defects in this project. One function, one query set.
//
// Counting rule — and it is deliberate:
//   Only what THAT user can do right now is counted as an action.
// An order sitting in Menunggu because the buyer has not paid is not
// a seller action (the server would 422 a confirmation), so it is not
// in the seller's number. Anything else would train the user to
// ignore the badge.
// ============================================================

if (!function_exists('pending_actions')) {
    /**
     * Pending-action counts for one side of one account.
     *
     * A user has two sides (a penjual also shops as a buyer), so the caller
     * says which console is asking. The identifier column is whitelisted from
     * that same choice, never taken from input.
     *
     * @param  string $side  'penjual' | 'pembeli'
     * @return array {
     *   action:   int   total items only this user can act on now
     *   detail:   array per-reason counts (keys above)
     *   reviews:  int   buyer-only: completed orders never reviewed
     *   disputes: int   open disputes touching this user on this side
     * }
     */
    function pending_actions(PDO $pdo, int $user_id, string $side): array
    {
        $is_seller = ($side === 'penjual');
        $col = $is_seller ? 'seller_id' : 'buyer_id';

        if ($is_seller) {
            // Sahkan: an online order that is paid but not yet approved.
            // Sah Terima Tunai: a cash order the seller is holding money for
            // but has not recorded — the stranded-cash case.
            $stmt = $pdo->prepare(
                "SELECT
                   SUM(CASE WHEN status = 'Menunggu' AND payment_type = 'online'
                             AND payment_status = 'paid' THEN 1 ELSE 0 END) AS perlu_sahkan,
                   SUM(CASE WHEN status = 'Menunggu' AND payment_type = 'cash'
                             AND payment_status = 'unpaid_cash' THEN 1 ELSE 0 END) AS perlu_tunai
                 FROM transactions WHERE {$col} = ?"
            );
        } else {
            // Bayar: unpaid, or an abandoned gateway attempt (pending/failed)
            // the buyer can resume — all three render a pay button.
            // Selesai: the seller has dispatched, only the buyer can close it.
            $stmt = $pdo->prepare(
                "SELECT
                   SUM(CASE WHEN status = 'Menunggu' AND payment_type = 'online'
                             AND payment_status IN ('unpaid','pending','failed') THEN 1 ELSE 0 END) AS perlu_bayar,
                   SUM(CASE WHEN status = 'Disahkan' THEN 1 ELSE 0 END) AS perlu_selesai
                 FROM transactions WHERE {$col} = ?"
            );
        }
        $stmt->execute([$user_id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $detail = [];
        foreach ($row as $k => $v) {
            $detail[$k] = (int) $v;
        }

        $action = $is_seller
            ? ($detail['perlu_sahkan'] + $detail['perlu_tunai'])
            : ($detail['perlu_bayar'] + $detail['perlu_selesai']);

        // Open disputes are not the user's action — PTU resolves them — so they
        // get their own quieter count rather than inflating the red badge.
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM disputes d
               JOIN transactions t ON t.transaction_id = d.transaction_id
              WHERE t.{$col} = ? AND d.status <> 'Selesai'"
        );
        $stmt->execute([$user_id]);
        $disputes = (int) $stmt->fetchColumn();

        // Reviews are buyer-only and advisory, so they are reported apart from
        // `action` too: the dashboard wording must not call them urgent.
        $reviews = 0;
        if (!$is_seller) {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM transactions t
                  WHERE t.buyer_id = ? AND t.status = 'Selesai'
                    AND NOT EXISTS (SELECT 1 FROM reviews r
                                     WHERE r.transaction_id = t.transaction_id)"
            );
            $stmt->execute([$user_id]);
            $reviews = (int) $stmt->fetchColumn();
        }

        return [
            'action'   => $action,
            'detail'   => $detail,
            'reviews'  => $reviews,
            'disputes' => $disputes,
            // carried through so the renderer can tell the two consoles apart
            // without the page repeating which side it asked for.
            'side'     => $is_seller ? 'penjual' : 'pembeli',
        ];
    }
}

if (!function_exists('notify_count_chip_html')) {
    /**
     * Sidebar chip, drawn exactly like the admin console's (same inline style,
     * because the three consoles share no CSS file).
     *
     * At zero it still emits a hidden <span>: the seller console re-reads this
     * count over AJAX after each order action, and the updater needs a node to
     * put the number back into.
     */
    function notify_count_chip_html(int $n, string $id = ''): string
    {
        $span_id = $id !== '' ? ' id="' . htmlspecialchars($id) . '"' : '';
        if ($n > 0) {
            return '<span' . $span_id . ' style="margin-left:auto;background:var(--red);color:#fff;'
                 . 'font-size:10px;font-weight:600;padding:1px 6px;border-radius:10px">' . $n . '</span>';
        }
        return '<span' . $span_id . ' style="display:none;margin-left:auto;background:var(--red);'
             . 'color:#fff;font-size:10px;font-weight:600;padding:1px 6px;border-radius:10px"></span>';
    }
}

if (!function_exists('notify_pill_html')) {
    /**
     * Topbar pill: "<count> <label>" that jumps to the tab holding the action.
     *
     * The label is passed in by the page, never built here, so the wording has
     * one home; the JS that re-reads the count after an action only replaces the
     * number and toggles visibility (see renderPendingNotify).
     *
     * $tone is 'red' (needs your action), 'gold' (advisory) or 'blue' (FYI).
     * Narrow screens are handled by layout, not by hiding: see the
     * @media(860px) .console-topbar rules in public/assets/css/trustmart-ui.css,
     * which let the topbar grow to a second row rather than truncate a count.
     *
     * Deliberately a plain clickable <div> with no role/tabindex, matching the
     * admin pill and every .nav-item in these consoles: advertising
     * role="button" without a keydown handler would promise keyboard operation
     * that does not exist.
     */
    function notify_pill_html(int $n, string $count_id, string $label, string $tab, string $tone = 'red'): string
    {
        $bg = ['red' => 'var(--red-bg)', 'gold' => 'var(--gold-pale)', 'blue' => 'var(--blue-bg)'][$tone] ?? 'var(--red-bg)';
        $fg = ['red' => 'var(--red)',     'gold' => '#7A5A10',          'blue' => 'var(--blue)'][$tone] ?? 'var(--red)';
        $show = $n > 0 ? 'flex' : 'none';

        return '<div id="' . htmlspecialchars($count_id) . '-pill" '
              . 'style="display:' . $show . ';align-items:center;gap:6px;font-size:12px;'
              . 'background:' . $bg . ';color:' . $fg . ';padding:5px 12px;border-radius:20px;'
              . 'cursor:pointer;white-space:nowrap" '
              . 'onclick="showTab(\'' . htmlspecialchars($tab) . '\')">'
              . '<span id="' . htmlspecialchars($count_id) . '">' . $n . '</span> '
              . htmlspecialchars($label)
              . '</div>';
    }
}

if (!function_exists('notify_topbar_html')) {
    /**
     * The whole topbar notification group for one console.
     *
     * Three separate counts on purpose:
     *   red   - things only this user can do, and doing nothing blocks them
     *   gold  - buyer review invitations (encouraged, never blocking)
     *   blue  - disputes PTU is handling (this user is informed, not on duty)
     * Folding the latter two into the red number would make the badge lie
     * about how much work is actually waiting.
     *
     * Wording lives here and nowhere else. The AJAX updater may only swap the
     * number and toggle display, so a re-read count can never render different
     * sentences from the page load.
     *
     * @param array  $notify result of pending_actions()
     * @param string $prefix id namespace shared with notify_js()
     * @param string $tab    showTab() target holding the actionable list
     */
    function notify_topbar_html(array $notify, string $prefix, string $tab): string
    {
        $is_seller = (($notify['side'] ?? 'pembeli') === 'penjual');
        $out  = '';

        $out .= notify_pill_html((int) $notify['action'], $prefix . '-action',
                                 'Pesanan menunggu tindakan anda', $tab, 'red');

        if (!$is_seller && (int) $notify['reviews'] > 0) {
            $out .= notify_pill_html((int) $notify['reviews'], $prefix . '-review',
                                     'belum diulas', $tab, 'gold');
        }
        if ((int) $notify['disputes'] > 0) {
            $out .= notify_pill_html((int) $notify['disputes'], $prefix . '-dispute',
                                     'Aduan disemak oleh pentadbir', $tab, 'blue');
        }

        // Mirrors the admin console's "Sistem beroperasi normal" slot.
        $show = $notify['action'] > 0 ? 'none' : 'inline';
        $out .= '<span id="' . htmlspecialchars($prefix) . '-empty" '
              . 'style="display:' . $show . ';font-size:12px;color:var(--ink4);white-space:nowrap">'
              . 'Tiada pesanan menunggu tindakan anda</span>';

        return $out;
    }
}

if (!function_exists('notify_js')) {
    /**
     * Number-only updater for the group above, for consoles that re-read stats
     * over AJAX instead of reloading (the seller console swaps order rows in
     * place; the buyer console reloads and therefore needs none of this).
     */
    function notify_js(string $prefix): string
    {
        // The body is written against an "ID-" id namespace so it stays
        // readable; the prefix is substituted at the one place that knows it.
        return str_replace('ID-', $prefix . '-', <<<'JS'
function renderPendingNotify(n) {
  if (!n) return;
  const set = (key, val, show) => {
    const c = document.getElementById('ID-' + key);
    const p = document.getElementById('ID-' + key + '-pill');
    if (c) c.textContent = val;
    if (p) p.style.display = show ? 'flex' : 'none';
  };
  set('action',  n.action,   n.action > 0);
  set('review',  n.reviews,  n.reviews > 0);
  set('dispute', n.disputes, n.disputes > 0);

  const empty = document.getElementById('ID-empty');
  if (empty) empty.style.display = n.action > 0 ? 'none' : 'inline';

  const chip = document.getElementById('ID-nav-count');
  if (chip) {
    chip.textContent = n.action;
    chip.style.display = n.action > 0 ? 'inline-block' : 'none';
  }
}
JS);
    }
}
